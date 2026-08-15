<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Support\AuditLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download di un documento, **mediato dall'applicazione** (🔗 ADR-026).
 *
 * Il file non si serve mai con una URL pre-firmata dell'object store: quella è
 * di fatto un bearer token — chi ce l'ha legge fino alla scadenza, con la
 * Policy fuori dal giro — e legherebbe il prodotto al provider. Qui
 * l'autorizzazione si ricontrolla a **ogni** richiesta, ed è l'unico modo
 * perché ADR-003 («mai dati senza auth») e ADR-018 (fail-closed) valgano anche
 * per i file e non solo per le schermate che li elencano.
 *
 * Il route-model binding è scopato: un documento di un altro Ente, o fuori dal
 * sotto-albero di un Responsabile, dà 404 prima di arrivare qui — risponde il
 * global scope, non un controllo scritto a mano.
 */
class ScaricaDocumento extends Controller
{
    public function __invoke(Documento $documento): StreamedResponse
    {
        // `Gate::authorize` e non `$this->authorize()`: il Controller base di
        // questo progetto non usa `AuthorizesRequests` — un dettaglio che il
        // 500 ha reso evidente subito.
        Gate::authorize('documenti.download');

        // Prima di rispondere: con `throw => true` un file mancante solleverebbe
        // DENTRO lo StreamedResponse, cioè a header già inviati, e il client
        // riceverebbe un 200 troncato. Meglio un 404 onesto.
        abort_unless($documento->esisteSulDisco(), 404);

        // ADR-026: il download di un documento è tracciato. Non è una scrittura
        // di dominio (ADR-027 copre quelle), è un accesso — ed è la contropartita
        // di aver scelto di far passare i file dall'applicazione.
        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($documento)
            ->log('Documento scaricato');

        return response()->streamDownload(
            function () use ($documento) {
                $stream = Storage::disk(Documento::DISCO)->readStream($documento->path);
                fpassthru($stream);
                fclose($stream);
            },
            $documento->nome,
            ['Content-Type' => $documento->mime ?? 'application/octet-stream'],
        );
    }
}

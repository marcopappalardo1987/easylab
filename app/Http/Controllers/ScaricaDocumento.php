<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Support\AuditLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
 *
 * 🔴 **Nome e mime vengono dal client** (`getClientOriginalName`,
 * `getClientMimeType` in `CaricaDocumento`), e il security pass di S7 li ha
 * trovati riflessi negli header così com'erano:
 *  - un `\` nel nome faceva lanciare a Symfony un'eccezione dentro
 *    `makeDisposition`, cioè un 500 a comando e un documento non più scaricabile;
 *  - un mime dichiarato `text/html` o `image/svg+xml` tornava tale e quale.
 *    L'`attachment` già impedisce il rendering, ma è una sola difesa: qui si
 *    rimanda solo un mime che la validazione (`mimes:pdf,jpg,jpeg,png`) può
 *    aver ammesso, più `nosniff`, che vale anche senza il middleware globale.
 */
class ScaricaDocumento extends Controller
{
    /** I mime che la validazione del caricamento può aver ammesso. */
    private const MIME_AMMESSI = ['application/pdf', 'image/jpeg', 'image/png'];

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

        $nome = self::nomeScaricabile($documento->nome);

        $risposta = response()->streamDownload(
            function () use ($documento) {
                $stream = Storage::disk(Documento::DISCO)->readStream($documento->path);
                fpassthru($stream);
                fclose($stream);
            },
            null,
            [
                'Content-Type' => in_array($documento->mime, self::MIME_AMMESSI, true)
                    ? $documento->mime
                    : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );

        // Il ripiego ASCII lo diamo noi (T1cB-1): quello di Laravel è
        // `Str::ascii()` del nome senza `%`, cioè VUOTO per «%» o per un nome di
        // sole emoji, e Symfony su un ripiego vuoto rilancia il nome UTF-8 come
        // ripiego e lo rifiuta: 500 su un documento che l'elenco mostra.
        $risposta->headers->set('Content-Disposition', $risposta->headers->makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $nome,
            self::ripiegoAscii($nome),
        ));

        return $risposta;
    }

    /**
     * Il nome che l'header `Content-Disposition` può portare: via separatori di
     * percorso e caratteri di controllo, che Symfony rifiuta lanciando.
     */
    /** Il nome in ASCII stampabile, mai vuoto: è ciò che legge un client senza RFC 5987. */
    private static function ripiegoAscii(string $nome): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]|[%"\\\\\/]/', '', Str::ascii($nome));
        $estensione = pathinfo($ascii, PATHINFO_EXTENSION);
        $base = trim(pathinfo($ascii, PATHINFO_FILENAME), ' .');

        return ($base === '' ? 'documento' : $base).($estensione !== '' ? '.'.$estensione : '');
    }

    private static function nomeScaricabile(?string $nome): string
    {
        $pulito = trim((string) preg_replace('#[\\\\/\x00-\x1F\x7F]+#u', '-', (string) $nome));

        return $pulito === '' ? 'documento' : $pulito;
    }
}

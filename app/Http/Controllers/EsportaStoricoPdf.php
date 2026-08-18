<?php

namespace App\Http\Controllers;

use App\Models\Strumento;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Storico di una macchina in PDF (🔗 ADR-031 — Elenco Funzionalità
 * «esportare storici, certificati e report di fine lavoro» — S4 STRETCH).
 *
 * **Il foglio è un documento, non la pagina stampata.** Non riusa i blade della
 * scheda: quelli hanno tab, modali e azioni, cioè tutto ciò che su un foglio non
 * esiste. La view dedicata sta in `resources/views/pdf/` e usa HTML semplice,
 * come vuole ADR-031.
 *
 * ⚠️ **Nessun controllo di autorizzazione scritto qui, e non è una dimenticanza
 * lasciata a metà**: `strumenti.view` e `documenti.export_pdf` sono imposti dai
 * middleware della rotta, e *quale* macchina si può leggere lo decide il global
 * scope tramite il route-model binding — una macchina di un altro Ente dà 404,
 * la stessa risposta che darebbe la sua scheda. È la forma già scelta per
 * l'accesso via QR (`AccessoQr`): una sola catena di autorizzazione, verificabile
 * guardando un file solo.
 *
 * Il **report di fine lavoro** compare accanto all'intervento che lo ha
 * prodotto: è il dato che il tecnico scrive chiudendo, e questo foglio è il
 * «foglio di intervento» che l'Elenco Funzionalità chiede di archiviare.
 *
 * Il PDF si genera **al volo e non si archivia** (ADR-031): un file salvato
 * invecchia mentre lo storico cambia, e resterebbe in giro un foglio che dice
 * un'altra cosa.
 */
class EsportaStoricoPdf extends Controller
{
    public function __invoke(Strumento $strumento): Response
    {
        // Gli interventi passano dal model, quindi dagli scope: un Responsabile
        // esporta ciò che vede, non ciò che esiste. `with` sui documenti perché
        // il foglio dice se il certificato c'è — «fatto» e «documentato» non
        // sono la stessa cosa (ADR-009).
        $interventi = $strumento->interventi()
            ->with('tecnico')
            ->orderByDesc('data_scadenza')
            ->orderByDesc('id')
            ->get();

        $pdf = Pdf::loadView('pdf.storico-strumento', [
            'strumento' => $strumento,
            'percorso' => $strumento->percorsoUbicazione(),
            'interventi' => $interventi,
            // Le garanzie ricambio restano fuori dal foglio: chi lo esporta può
            // non avere titolo a vederle (ADR-004/029), e un PDF non ha modo di
            // degradare per permesso una volta uscito dall'applicazione.
            'garanzia' => Gate::allows('garanzie.macchina.view')
                ? $strumento->garanzie()->orderByDesc('data_scadenza_effettiva')->first()
                : null,
            'generatoIl' => now(),
            'generatoDa' => auth()->user()?->name,
        ])->setPaper('a4');

        // `stream` e non `download`: sul telefono di un tecnico si vuole
        // guardare il foglio, non ritrovarselo fra i file scaricati. Chi lo
        // vuole salvare lo fa dal visualizzatore.
        return $pdf->stream("storico-{$strumento->id}.pdf");
    }
}

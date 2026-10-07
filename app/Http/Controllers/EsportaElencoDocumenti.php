<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\Strumento;
use App\Support\Documenti\FiltroDocumenti;
use App\Support\Tenancy\SediSeguite;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * L'indice dell'archivio documentale in PDF (🔗 ADR-026/031, ERD §8.1).
 *
 * **È un INDICE, non un pacchetto dei file, e la differenza è una decisione.**
 * Uno ZIP di N documenti sarebbe **una sola** riga di audit per N file, cioè lo
 * smontaggio di ADR-026, che ha scelto il download mediato proprio perché
 * l'autorizzazione — e la sua traccia — si ricontrolli a *ogni* richiesta. (E
 * dompdf non concatena PDF.) Il foglio elenca quindi le stesse colonne dello
 * schermo, e i file restano scaricabili uno per uno dalla rotta mediata. Se
 * servisse davvero lo ZIP è una decisione con una sua ADR, non un'aggiunta qui.
 *
 * 🔴 **L'autorizzazione è scritta qui dentro, e non solo sulla rotta.** Le due
 * domande sono diverse — «puoi vedere l'elenco» (`documenti.view`) e «puoi
 * portartene via un foglio» (`documenti.export_pdf`) — e il Tecnico ha la prima
 * e non la seconda: è ciò che rende la guardia falsificabile, e c'è un caso di
 * test che lo congela. Il primo abbozzo di questo file **non** autorizzava,
 * delegando tutto ai due `can:` della rotta, «una sola catena come su
 * `EsportaStoricoPdf`». La catena però non esisteva ancora — `routes/web.php` è
 * tenuto da una mano sola — e i casi che l'avrebbero verificata erano `skip()`ati
 * finché non fosse nata: per tutto quel tempo l'unica affermazione sul perimetro
 * «chi può esportare» non veniva eseguita da nessuna asserzione, e una rotta
 * registrata domani con il solo `can:documenti.view` avrebbe consegnato al
 * Tecnico l'archivio documentale dell'Ente in PDF.
 *
 * ⚠️ **Il caso del guest non è teorico**: senza utente `CurrentTenant::shouldScope()`
 * è falsa e `Documento::query()` non porta **alcuno** scope — un'invocazione non
 * autenticata stamperebbe l'archivio di *ogni* Ente su un foglio solo.
 * `Gate::authorize()` senza utente nega, quindi il caso è chiuso qui e non
 * dipende dal fatto che qualcuno si ricordi di mettere `auth` sulla rotta.
 *
 * I due `can:` restano comunque **dichiarati sulla rotta** (difesa in profondità,
 * e il 403 arriva prima di costruire la query): il loro assert strutturale vive
 * in `EsportaElencoDocumentiTest`, che è ciò che li tiene falsificabili — non la
 * loro unicità. *Quali* righe finiscono sul foglio lo decidono comunque i global
 * scope di `Documento`, esattamente come a schermo.
 *
 * ⚠️ `Gate::authorize()` e non `$this->authorize()`: il `Controller` base di
 * questo progetto non usa `AuthorizesRequests`, e la cicatrice (un 500) è
 * annotata su `ScaricaDocumento`.
 *
 * **ADR-031 letta per il suo scopo**: sul foglio non entra ciò che chi lo
 * esporta non potrebbe vedere a schermo. Non «sul foglio non entrano nomi» — lo
 * storico macchina stampa già il tecnico, e qui `caricato_da` compare, perché è
 * la stessa informazione che l'elenco mostra a chi sta guardando.
 *
 * **Nessuna riga di audit per l'export**, di proposito: il perimetro di ADR-027
 * traccia le *scritture*, con due sole eccezioni nominate (download documenti,
 * accesso tecnico). Un indice non è un download di documento, e
 * `EsportaStoricoPdf` oggi non logga: allargare il perimetro a una terza
 * eccezione è una decisione da prendere, non un effetto collaterale di questa
 * pagina.
 */
class EsportaElencoDocumenti extends Controller
{
    /**
     * Il tetto **dichiarato** del foglio.
     *
     * Oltre qualche migliaio di righe dompdf consuma memoria in modo non
     * lineare e il render muore in produzione senza dire perché. Il foglio porta
     * quindi in coda «elenco troncato»: un limite dichiarato vale più di un
     * limite scoperto, e un PDF che finisce a metà senza dirlo è un documento
     * che mente.
     */
    private const MAX_RIGHE = 2000;

    public function __invoke(Request $request): Response
    {
        // ⚠️ **Due autorizzazioni, non una.** Sono due domande diverse, e sono
        // separabili: il Tecnico ha `documenti.view` e non `documenti.export_pdf`.
        // Un solo `authorize` sulla prima renderebbe la seconda decorativa.
        Gate::authorize('documenti.view');
        Gate::authorize('documenti.export_pdf');

        $filtro = FiltroDocumenti::daRichiesta($request);

        // ⚠️ **`Documento::query()` e non una query riscritta**: gli scope sono
        // gli stessi dello schermo, quindi il foglio non può contenere una riga
        // che il suo autore non vedrebbe nell'elenco. Un PDF non sa degradare
        // per permesso una volta uscito dall'applicazione (ADR-031): ciò che non
        // deve leggere chi lo esporta non ci deve **entrare**.
        $righe = $filtro
            ->applica(Documento::query()->with([
                'strumento:id,nome,matricola',
                'caricatoBy:id,name',
                'documentabile',
            ]))
            ->orderByDesc('created_at')
            // Lo stesso tie-break dello schermo: senza, «le prime 2000» non
            // sarebbero un insieme stabile fra due esportazioni identiche.
            ->orderByDesc('id')
            // +1 riga per **sapere** di aver troncato senza dover contare
            // l'intera tabella con una seconda query.
            ->limit(self::MAX_RIGHE + 1)
            ->get();

        $troncato = $righe->count() > self::MAX_RIGHE;
        $righe = $righe->take(self::MAX_RIGHE);

        $utente = auth()->user();

        $pdf = Pdf::loadView('pdf.elenco-documenti', [
            'righe' => $righe,
            'troncato' => $troncato,
            'massimo' => self::MAX_RIGHE,
            // La descrizione dei filtri, con il **nome** della macchina e non il
            // suo id: un foglio ritrovato fra un anno deve dire di quale
            // sottoinsieme parla, e «#7» non è quello. La query è scopata, quindi
            // un id estraneo resta un `#id` invece di rivelare un nome altrui.
            'filtri' => $filtro->descrizione(
                $filtro->strumentoId !== null
                    ? Strumento::query()->whereKey($filtro->strumentoId)->pluck('nome', 'id')->all()
                    : []
            ),
            'generatoIl' => now(),
            'generatoDa' => $utente?->name,
            // Il nome della sede CORRENTE, non di `users.tenant_id`: impersonando,
            // lo switcher sposta il contesto in sessione e le righe sono di
            // quella sede (D-T2-3, security pass S7). E `null` per chi ha in
            // elenco i documenti di più clienti (ADR-046): il titolo non nomina
            // una sede sola sopra un foglio che ne contiene diverse.
            'ente' => SediSeguite::sedeUnica(),
        ])->setPaper('a4');

        // `stream` e non `download`, come sullo storico macchina: si vuole
        // guardare il foglio, non ritrovarselo fra i file scaricati.
        return $pdf->stream('documenti-elenco.pdf');
    }
}

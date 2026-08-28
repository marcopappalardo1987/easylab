<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Models\Account;
use App\Support\AuditLog;
use App\Support\Piattaforma\CsvSicuro;
use App\Support\Piattaforma\EsportazioneClienti;
use App\Support\Piattaforma\MetrichePiattaforma;
use App\Support\Piattaforma\RiepilogoPiattaforma;
use App\Support\Tenancy\VistaPiattaforma;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 🔴 Le esportazioni della cabina di regia: l'unica superficie che porta i dati
 * dei clienti **fuori** dall'applicazione.
 *
 * ⛔ **Nessuna rotta nuova: due azioni Livewire.** È la decisione di progetto
 * più importante di questo blocco. Una rotta `/piattaforma/clienti.csv`
 * dovrebbe rileggere e ri-validare i filtri dalla query string una **seconda**
 * volta, e quella seconda copia è esattamente il posto in cui nasce «il file
 * esporta tutto mentre la pagina ne mostra dodici». Con l'azione il perimetro è
 * lo stato del componente, e la definizione resta una: `queryClienti()`.
 * Precedente nel progetto: `ImportStrumenti::scaricaTemplate()`.
 *
 * ⚠️ **Questo trait non è usabile senza `ElencaClienti`**: `queryClienti()`,
 * `dettagliDellaPagina()` e `filtriNormalizzati()` sono `private` là dentro, e
 * si raggiungono solo perché i trait vengono appiattiti dentro `Cabina`. La
 * dipendenza è scritta su entrambi i lati: il progetto ha già pagato i concern
 * che si scrivevano le property a vicenda senza dichiararlo, ed è la ragione per
 * cui `chiudiOgniModale()` vive su `Cabina`.
 *
 * **Autorizzazione.** Ogni azione apre con `Gate::authorize(VistaPiattaforma::PERMESSO)`
 * benché ogni lettura passi comunque dalla porta di `VistaPiattaforma`: un'azione
 * che **restituisce una risposta** non arriva mai a `render()`, che è dove oggi
 * la porta gata le letture della pagina. È la stessa disciplina di
 * `ElencaClienti::espandi()`.
 * ⚠️ Va detto per onestà che, finché ogni riga passa da `VistaPiattaforma`,
 * questa chiamata **non è falsificabile**: togliendola, l'export continuerebbe a
 * negare — perché a negare sarebbe la porta, una riga più sotto. È difesa in
 * profondità, e il giorno in cui serve davvero è quello in cui qualcuno mette un
 * `skipRender()` o legge da una sorgente già in memoria.
 * ⛔ **Deliberatamente NON si mette `documenti.export_pdf` in AND.** Là dove quel
 * permesso partiziona davvero (il Tecnico ha `strumenti.view` e non
 * `documenti.export_pdf`) ha senso; qui no — gli unici ruoli con
 * `tenants.view_all` sono Developer e Superadmin, che ce l'hanno entrambi. E
 * sarebbe **dannoso**: `tenants.view_all` è nel set `locked` di
 * `config/rbac.php`, `documenti.export_pdf` no. Metterli in AND renderebbe
 * l'export dell'intero portafoglio clienti concedibile e revocabile a runtime
 * da `/piattaforma/ruoli`, cioè sposterebbe la protezione su una regola
 * modificabile da una schermata.
 *
 * ⚠️ **Dentro Livewire un download NON è uno stream.** `SupportFileDownloads`
 * intercetta il valore di ritorno, ne cattura l'output e lo **base64-encoda
 * dentro il payload JSON** dell'update: il file sta comunque tutto in memoria
 * (~4/3 della sua dimensione) e `Content-Disposition: inline` non apre una
 * scheda — arriva come file scaricato lo stesso. Da qui due conseguenze:
 *  · lo `streamDownload()` del CSV è nominale, e il docblock di `CsvSicuro` lo
 *    dice invece di far finta;
 *  · ⛔ il PDF **non può** essere restituito da `Pdf::download()`: quel metodo
 *    rende un `Illuminate\Http\Response`, e `SupportFileDownloads` riconosce
 *    solo `StreamedResponse` e `BinaryFileResponse` — il file verrebbe
 *    silenziosamente buttato via e l'utente non vedrebbe **nulla**. Si prende
 *    quindi `->output()` e lo si riavvolge in uno `streamDownload()`.
 * **Il tetto è dichiarato**: intorno ai 5.000 account questo meccanismo va
 * sostituito da un job in coda che deposita il file, non allargato. Un limite
 * dichiarato vale più di un limite scoperto in produzione.
 *
 * **Il file non si archivia** (ADR-031): un file salvato invecchia mentre i dati
 * cambiano, come lo storico macchina.
 *
 * 🚩 **L'audit dell'export è una TERZA eccezione al perimetro di ADR-027**
 * («si tracciano le scritture, non le letture»), dopo il download documenti
 * (ADR-026) e l'accesso tecnico (ADR-030). Il precedente è dalla stessa parte —
 * portare fuori l'intero portafoglio clienti è almeno tanto sensibile quanto
 * scaricare un documento — ma la riga in ADR-027 la scrive Marco, non chi
 * costruisce: qui c'è il codice, e la decisione si annulla cancellando
 * `tracciaEsportazione()`. ⚠️ Nota per chi decide: `EsportaElencoDocumenti`
 * dichiara nel proprio docblock di **non** loggare, per non allargare il
 * perimetro «di sfuggita». Le due scelte vanno allineate in un verso o
 * nell'altro.
 *
 * 🔗 ADR-013 (le due sorgenti di lockout), ADR-018/032 (il confine di
 * piattaforma), ADR-026/027 (audit), ADR-031 (l'export è HTML→PDF in PHP e non
 * si archivia), ERD §1.
 */
trait EsportaClienti
{
    /**
     * Il CSV dei clienti che corrispondono ai filtri.
     *
     * `text/csv; charset=UTF-8` con il BOM davanti: senza, l'Excel italiano
     * apre le ragioni sociali accentate corrotte.
     */
    public function esportaCsv(): StreamedResponse
    {
        Gate::authorize(VistaPiattaforma::PERMESSO);

        [$intestazioni, $righe] = $this->matriceClienti();

        $this->tracciaEsportazione('csv', count($righe));

        $contenuto = CsvSicuro::stringa([$intestazioni, ...$righe]);

        return response()->streamDownload(
            fn () => print $contenuto,
            $this->nomeFileEsportazione('csv'),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Il foglio A4 **orizzontale** degli stessi clienti.
     *
     * Orizzontale e a 8pt perché le colonne sono tredici: in verticale
     * finirebbero l'una sull'altra, e un foglio illeggibile è un foglio che
     * qualcuno rigenera in CSV — cioè due esportazioni per una domanda sola.
     */
    public function esportaPdf(): StreamedResponse
    {
        Gate::authorize(VistaPiattaforma::PERMESSO);

        $dati = $this->datiFoglioClienti();

        $this->tracciaEsportazione('pdf', count($dati['righe']));

        // ⛔ `->output()` e non `->download()`: vedi il docblock del trait.
        $binario = Pdf::loadView('pdf.clienti-piattaforma', $dati)
            ->setPaper('a4', 'landscape')
            ->output();

        return response()->streamDownload(
            fn () => print $binario,
            $this->nomeFileEsportazione('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Intestazioni e righe, dalla **stessa** matrice che usa il PDF.
     *
     * ⛔ **`protected`, e la parola è la guardia.** Era `public` «perché un test
     * possa leggerlo senza passare dagli effects», ed è costato una **porta di
     * servizio muta**: in Livewire ogni metodo pubblico non statico del
     * componente è invocabile dal browser — `HandleComponents::callMethods()`
     * costruisce l'elenco con `Utils::getPublicMethodsDefinedBySubClass()`, che
     * filtra su `isPublic() && ! isStatic()` e toglie solo `render`, e i metodi
     * di trait appiattiti in `Cabina` ci rientrano. Un `$wire.matriceClienti()`
     * dalla console restituiva l'**intero portafoglio filtrato** dentro
     * l'effect `returns`, senza passare da `tracciaEsportazione()`: non una fuga
     * verso chi non doveva (l'autorizzazione teneva comunque), ma la garanzia di
     * tracciabilità dichiarata qui sotto resa **falsa**.
     *
     * ⚠️ I test la raggiungono legando una closure al componente
     * (`Closure::bind(..., Cabina::class)`), che è il modo di leggere un interno
     * senza renderlo una superficie: la comodità di test non paga con una porta
     * in più. `GuardrailEsportazioniTest` — nel file dei test di questo blocco —
     * congela l'elenco delle azioni invocabili dal browser.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    protected function matriceClienti(): array
    {
        return $this->matriceDa($this->righeDaEsportare());
    }

    /**
     * I dati del foglio PDF.
     *
     * ⛔ `protected` per la stessa ragione di `matriceClienti()`, e con più
     * motivo: questo metodo porta fuori i KPI e i filtri **oltre** alle righe.
     *
     * ⚠️ **I quattro KPI NON seguono i filtri**, come in pagina, e il foglio lo
     * dice con la stessa didascalia del blade della cabina. Non si «aggiustano»
     * ricalcolandoli sul filtrato: `MetrichePiattaforma::riepilogo()` non prende
     * parametri, e dargliene uno metterebbe in due forme il codice che somma
     * denaro. Accanto ci va invece il totale a listino **delle sole righe in
     * elenco**, etichettato come tale — che è il numero che segue i filtri.
     *
     * @return array{intestazioni: list<string>, righe: list<list<string>>, riepilogo: RiepilogoPiattaforma, filtri: array<string,string>, totaleListinoEuro: int, generatoIl: Carbon, generatoDa: ?string}
     */
    protected function datiFoglioClienti(): array
    {
        // Una passata sola: `matriceClienti()` e il totale leggono la **stessa**
        // collezione, o sarebbero due query e due fotografie di un dato che nel
        // frattempo può cambiare.
        $clienti = $this->righeDaEsportare();

        [, $righe] = $this->matriceDa($clienti);

        return [
            // ⛔ Le intestazioni del foglio sono quelle **in prosa**: le righe
            // sono le stesse del CSV (matrice unica), i nomi macchina no. Vedi
            // `EsportazioneClienti::intestazioniLeggibili()`.
            'intestazioni' => EsportazioneClienti::intestazioniLeggibili(),
            'righe' => $righe,
            'riepilogo' => MetrichePiattaforma::riepilogo(),
            'filtri' => $this->filtriAttivi(),
            'totaleListinoEuro' => intdiv(EsportazioneClienti::totaleListinoCent($clienti), 100),
            'generatoIl' => now(),
            'generatoDa' => auth()->user()?->name,
        ];
    }

    /**
     * ⛔ **Tutte** le righe che i filtri lasciano, e non la sola pagina.
     *
     * La paginazione non è un filtro di privacy: chi vede la prima pagina può
     * sfogliare fino all'ultima, quindi limitare il file alla pagina corrente
     * sarebbe scomodità travestita da sicurezza. Il bottone lo dichiara a chi lo
     * preme, e due test opposti congelano le due metà dell'invariante — quella
     * che dice «tutte le righe filtrate» e quella che dice «**solo** quelle».
     *
     * ⛔ Da `queryClienti()` e non da `VistaPiattaforma::accounts()`: la porta da
     * sola non conosce i filtri, e un export che li ignorasse sarebbe un difetto
     * di privacy, non di comodità.
     *
     * @return Collection<int,Account>
     */
    private function righeDaEsportare(): Collection
    {
        return $this->queryClienti()->get();
    }

    /**
     * @param  Collection<int,Account>  $clienti
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function matriceDa(Collection $clienti): array
    {
        // Le **stesse** due query aggregate della pagina, sull'insieme filtrato
        // invece che sulla pagina: sedi e strumenti si contano una volta sola e
        // in SQL, non una query per riga.
        $dettagli = $this->dettagliDellaPagina($clienti);

        return [
            EsportazioneClienti::intestazioni(),
            EsportazioneClienti::righe(
                $clienti,
                $dettagli['sedi']->map(fn (Collection $sedi) => $sedi->count())->all(),
                $dettagli['strumenti'],
            ),
        ];
    }

    /**
     * Il nome del file porta **la data**, non l'ora: due esportazioni dello
     * stesso giorno si sovrascrivono nella cartella dei download, ed è il
     * comportamento voluto — la fotografia del giorno è una.
     */
    private function nomeFileEsportazione(string $estensione): string
    {
        return 'clienti-easylab-'.now()->format('Y-m-d').'.'.$estensione;
    }

    /**
     * La riga di registro dell'esportazione (🚩 vedi il docblock del trait: è la
     * terza eccezione ad ADR-027, e va ratificata).
     *
     * **Senza soggetto**, perché il soggetto sarebbero *tutti* i clienti:
     * `EtichettaSoggetto::assente()` esiste già per i login e la 2FA, e
     * `RegistroAudit` la renderizza. I filtri entrano nelle properties
     * **normalizzati** — cioè come la query li ha davvero usati — o il registro
     * direbbe di aver esportato un insieme diverso da quello uscito.
     */
    private function tracciaEsportazione(string $formato, int $righe): void
    {
        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->withProperties([
                'formato' => $formato,
                'righe' => $righe,
                'filtri' => $this->filtriAttivi(),
            ])
            ->log('Esportazione clienti');
    }
}

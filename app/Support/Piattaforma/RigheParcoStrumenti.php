<?php

namespace App\Support\Piattaforma;

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Support\Semaforo;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Le tre fonti del semaforo per le **righe in pagina** del Parco clienti
 * (🔗 ADR-005, ADR-004, ADR-020, ADR-037).
 *
 * ## Perché esiste, invece di riusare gli scope di `Strumento`
 *
 * ⛔ `Strumento::scopeConStato()`, `scopeOrdinaPerStato()` e `scopeObsoleti()`
 * sono la forma **SQL** della regola del semaforo, e compongono sottoquery che
 * partono da `Intervento::query()` e `Garanzia::query()` — cioè **scopate per
 * tenant**. Su un elenco cross-cliente quelle sottoquery non trovano nulla per
 * gli Enti altrui: le macchine degli altri clienti risulterebbero **verdi**, e
 * la pagina tornerebbe un elenco plausibile e sbagliato. `ParcoClienti` lo
 * vieta per iscritto e `VistaPiattaformaTest` per nome.
 *
 * Qui si prende quindi l'**altra** forma della stessa regola — quella per-model,
 * `App\Support\Semaforo` — che è pura: riceve tre date e restituisce lo stato.
 * Le tre date arrivano da tre query **costanti** sulle sole righe in pagina,
 * lette attraverso `ParcoClienti`, cioè non scopate ma **dentro il perimetro**.
 * La regola non viene riscritta da nessuna parte: `Semaforo::calcola()` resta
 * l'unica definizione, e questa classe è solo il suo approvvigionamento.
 *
 * ⚠️ **Questa classe MOSTRA il semaforo, non lo filtra** — e la distinzione è
 * diventata operativa il 29 Ago 2026, quando il filtro per stato è stato
 * chiesto. Filtrare in PHP *dopo* la paginazione darebbe pagine incomplete e
 * conteggi falsi, quindi il filtro **non passa di qui**: vive in
 * `StrumentiPerStato`, che costruisce le tre fonti non-scopate e le fa passare
 * dalla porta. Nessun `whereExists` scritto in questa classe, che continua a
 * rispondere a una domanda sola: «come sta la riga che sto disegnando».
 *
 * ⛔ Resta aperta la strada scritta qui sotto — le fonti di
 * `Strumento::fontiArancione()` costruibili non-scopate — che è ciò che
 * risparmierebbe a `StrumentiPerStato` la seconda copia della **composizione**:
 * il debito è dichiarato là, e legato da due test differenziali.
 *
 * ## Il costo, che è la ragione per cui la classe prende una PAGINA e non un builder
 *
 * Tre query fisse per pagina, indipendenti dal numero di righe: si parte sempre
 * dagli id già paginati. È lo stesso schema del blocco in coda a
 * `ElencoStrumenti::render()`, e vale qui **di più**, perché il debito noto di
 * quell'elenco (sottoquery correlate per riga) su una vista che guarda tutti i
 * clienti si moltiplicherebbe per il numero di Enti.
 *
 * ⚠️ Su una pagina vuota non si esegue nessuna query: un `whereIn` su un array
 * vuoto è un giro dal database per sapere una cosa che si sa già.
 */
final class RigheParcoStrumenti
{
    /**
     * @param  Collection<int, Intervento>  $interventi  id strumento → intervento aperto più vicino
     * @param  Collection<int, Garanzia>  $garanzie  id strumento → garanzia macchina più vicina
     * @param  Collection<int, Garanzia>  $garanzieRicambio  id strumento → garanzia del pezzo montato più vicina
     */
    private function __construct(
        private Collection $interventi,
        private Collection $garanzie,
        private Collection $garanzieRicambio,
    ) {}

    /**
     * Le tre fonti per gli strumenti **in pagina**, dentro il perimetro.
     *
     * @param  Collection<int, Strumento>  $strumenti
     */
    public static function perPagina(Collection $strumenti, Perimetro $perimetro): self
    {
        $ids = $strumenti->pluck('id')->all();

        if ($ids === []) {
            return new self(collect(), collect(), collect());
        }

        // Fonte 1 — l'intervento APERTO più vicino (🔗 ADR-005). Solo gli
        // aperti: lo storico `fatto` è ciò che cresce senza limite, e cade
        // sull'indice (strumento_id, stato, data_scadenza). Porta con sé il
        // `tipo`, che serve all'etichetta della colonna scadenza — un aggregato
        // SQL puro non lo darebbe senza un join-back.
        $interventi = ParcoClienti::interventi($perimetro)
            ->whereIn('interventi.strumento_id', $ids)
            ->where('interventi.stato', StatoIntervento::NonFatto->value)
            ->orderBy('interventi.data_scadenza')
            ->orderBy('interventi.id')
            ->get(['interventi.id', 'interventi.strumento_id', 'interventi.tipo', 'interventi.data_scadenza', 'interventi.stato'])
            ->unique('strumento_id') // ordinati asc → il primo per strumento è il minimo
            ->keyBy('strumento_id');

        // Fonte 2 — la garanzia della MACCHINA (🔗 ADR-004).
        $garanzie = ParcoClienti::garanzie($perimetro)
            ->whereIn('garanzie.strumento_id', $ids)
            ->orderBy('garanzie.data_scadenza_effettiva')
            ->orderBy('garanzie.id')
            ->get(['garanzie.id', 'garanzie.strumento_id', 'garanzie.data_scadenza_effettiva'])
            ->unique('strumento_id')
            ->keyBy('strumento_id');

        // Fonte 3 — la garanzia dei pezzi MONTATI (🔗 ADR-020).
        //
        // ⚠️ `deiPezziMontati()` è l'unico punto del progetto autorizzato a
        // leggere le righe `soggetto = ricambio` senza
        // `GaranziaRicambioPrivacyScope`, ed è legittimo perché il pallino è un
        // **aggregato dovuto a tutti**: si prendono id e data, mai il nome del
        // pezzo, che non entra nemmeno nel result set.
        //
        // Alias `montato_su_id` e non `strumento_id`: su una riga
        // `soggetto = ricambio` la colonna `garanzie.strumento_id` è NULL per
        // invariante, e sovrascriverla metterebbe in circolo model che mentono
        // su sé stessi.
        $garanzieRicambio = ParcoClienti::garanzie($perimetro)
            ->deiPezziMontati()
            ->whereIn('ricambio_utilizzo.strumento_id', $ids)
            ->orderBy('garanzie.data_scadenza_effettiva')
            ->orderBy('garanzie.id')
            ->get([
                'garanzie.id',
                'garanzie.data_scadenza_effettiva',
                'ricambio_utilizzo.strumento_id as montato_su_id',
            ])
            ->unique('montato_su_id')
            ->keyBy('montato_su_id');

        return new self($interventi, $garanzie, $garanzieRicambio);
    }

    /**
     * Lo stato EFFETTIVO della riga: il forzato vince (🔗 ADR-005 punto 5).
     *
     * `forced_state` è già sulla riga paginata, quindi costo zero. La regola
     * calcolata non è riscritta: è `Semaforo::calcola()`, la stessa che usa
     * l'elenco per-Ente.
     */
    public function semaforo(Strumento $strumento): StatoSemaforo
    {
        return $strumento->forced_state ?? Semaforo::calcola(
            $this->interventi->get($strumento->id)?->data_scadenza,
            $this->garanzie->get($strumento->id)?->data_scadenza_effettiva,
            $this->garanzieRicambio->get($strumento->id)?->data_scadenza_effettiva,
        );
    }

    /**
     * ⏳ La riga è **obsoleta**? (🔗 ADR-014), con la soglia della SUA sede.
     *
     * 🔴 Esiste perché il badge non poteva essere `<x-ui.obsoleto>`, e la
     * differenza non è di stile: quel componente chiama
     * `Strumento::isObsoleto()` → `sogliaObsolescenza()` → `$this->tenant`, che
     * è una relazione verso `UnitaOrganizzativa` — cioè **scopata per tenant**.
     * Su questa vista risolve a NULL per ogni cliente che non è il proprio e
     * ricade sul default 10: il badge direbbe «oltre la soglia di 10 anni»
     * proprio sulle sedi che ne hanno scelta un'altra, cioè **contraddirebbe il
     * filtro** sulla riga accanto. Sarebbe anche un N+1, ed è la ragione per
     * cui l'errore si nota tardi: la pagina resta plausibile.
     *
     * La soglia arriva quindi dalla `leftJoin` che la pagina ha già in tavola
     * (`sede.soglia_obsolescenza_anni as sede_soglia`), e questa classe non fa
     * nessuna query in più per rispondere.
     *
     * ⚠️ **Il confine è lo stesso in tutte e tre le forme**: qui `lte(oggi −
     * N anni)` come `Strumento::isObsoleto()`, in SQL `< limite+1` come
     * `StrumentiPerStato::obsoleti()` e `Strumento::scopeObsoleti()`. È
     * INCLUSIVO: installata esattamente N anni fa oggi è già obsoleta. Un test
     * differenziale confronta il badge con la selezione del filtro riga per
     * riga, perché due letture della stessa regola che divergono danno un
     * difetto muto — un ⏳ su una riga che il filtro non prende.
     */
    public function obsoleta(Strumento $strumento): bool
    {
        if ($strumento->data_installazione === null) {
            return false;
        }

        return $strumento->data_installazione->lte(today()->subYears($this->soglia($strumento)));
    }

    /**
     * La soglia di obsolescenza **della sede della riga**, in anni.
     *
     * ⛔ **Rumorosa quando la colonna non c'è, invece che accomodante.** Un
     * `?? 10` su una riga che non è passata dalla join darebbe la soglia
     * sbagliata a tutti, in silenzio e in modo plausibile: è esattamente il
     * difetto che questo metodo esiste per impedire, riprodotto dalla sua
     * stessa salvaguardia. Il fallback a 10 vale solo per una colonna **letta**
     * e nulla, che è il fallback di `Strumento::sogliaObsolescenza()` e quello
     * di `StrumentiPerStato`: le tre letture devono cadere insieme.
     */
    public function soglia(Strumento $strumento): int
    {
        if (! array_key_exists('sede_soglia', $strumento->getAttributes())) {
            throw new LogicException(
                'RigheParcoStrumenti: la riga non porta `sede_soglia`. La soglia di obsolescenza '
                .'viaggia con la query di pagina (`sede.soglia_obsolescenza_anni as sede_soglia`) '
                .'perché la relazione `tenant` è scopata e cross-cliente risolve a NULL.'
            );
        }

        return (int) ($strumento->getAttributes()['sede_soglia'] ?? 10);
    }

    /**
     * La colonna «Prossima scadenza»: testo già pronto e flag di ritardo.
     *
     * 🔴 **Si calcola qui e non in Blade**, al contrario dell'elenco per-Ente:
     * quella vista tiene due closure in cima al file, e tre `@if` annidati in
     * un paragrafo hanno già fatto sparire un blocco intero dalla vista
     * compilata in questo stesso giro. Il markup resta piatto e la logica
     * resta provabile senza render.
     *
     * ⚠️ **Il DETTAGLIO degrada, l'aggregato no** (🔗 ADR-020, wireframe §1):
     * senza `garanzie.ricambio.view` l'etichetta della garanzia di un pezzo
     * diventa «Garanzia», che è la dicitura del wireframe. La riga **non** si
     * esclude e la data **non** si nasconde — è informazione sul bene del
     * cliente; a essere protetta è la fonte, cioè l'esistenza del pezzo
     * sostituito. Il pallino, che è l'aggregato, non cambia per nessuno.
     *
     * Ordine a parità di data: intervento, garanzia macchina, garanzia
     * ricambio. L'intervento vince perché porta con sé il tipo (Taratura e
     * certificazione, Manutenzione…), più informativo di «Garanzia»; fra le due
     * garanzie vince quella macchina, che si può nominare a chiunque.
     *
     * ## ⛔ Debito dichiarato: questa regola esiste in DUE copie
     *
     * Il docblock qui sopra argomenta contro una seconda copia della regola del
     * **semaforo** — e ha ragione — ma nel farlo ne introduce una
     * dell'**etichetta**: `scadenza()` ed `etichetta()` riproducono riga per
     * riga `$scadenzaLabel` e `$etichettaScadenza`, le due closure in cima a
     * `resources/views/livewire/strumenti/elenco-strumenti.blade.php`. Stesso
     * ordine di precedenza, stesso `usort` stabile, stessa degradazione
     * «Garanzia ricambio» → «Garanzia». Oggi coincidono, e il giorno in cui una
     * sola delle due diventasse «tra 3 gg ⚠» i due schermi direbbero cose
     * diverse sullo stesso strumento.
     *
     * L'unico filo che oggi le lega è un test — *«spells the deadline exactly
     * like the per-Ente list does»* in `tests/Feature/Piattaforma/ParcoStrumentiTest.php`:
     * calcola l'etichetta di qui e la cerca **nella pagina per-Ente**, quindi
     * cambiarne una sola diventa rosso. La chiusura vera è una regola sola
     * chiamata da entrambi: estrarre queste due funzioni in un supporto e farle
     * usare anche a quel Blade, che sta fuori dal perimetro di questa classe.
     *
     * @return array{testo: string, inRitardo: bool}
     */
    public function scadenza(Strumento $strumento, bool $vedeGaranzieRicambio): array
    {
        $intervento = $this->interventi->get($strumento->id);
        $garanzia = $this->garanzie->get($strumento->id);
        $garanziaRicambio = $this->garanzieRicambio->get($strumento->id);

        $candidati = [];

        if ($intervento !== null) {
            $candidati[] = [$intervento->data_scadenza, $intervento->tipo->label(), $intervento->isScaduto()];
        }

        if ($garanzia !== null) {
            $candidati[] = [$garanzia->data_scadenza_effettiva, 'Garanzia', $garanzia->isScaduta()];
        }

        if ($garanziaRicambio !== null) {
            $candidati[] = [
                $garanziaRicambio->data_scadenza_effettiva,
                $vedeGaranzieRicambio ? 'Garanzia ricambio' : 'Garanzia',
                $garanziaRicambio->isScaduta(),
            ];
        }

        if ($candidati === []) {
            return ['testo' => '—', 'inRitardo' => false];
        }

        // `usort` stabile da PHP 8.0: a parità di timestamp resta l'ordine di
        // inserimento, che è l'ordine di precedenza dichiarato sopra.
        usort($candidati, fn (array $a, array $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        [$data, $tipo, $scaduta] = $candidati[0];

        // «In ritardo» è il flag della fonte VINCENTE e non l'OR delle tre, che
        // è la forma scritta nell'elenco per-Ente: le due dicono la stessa cosa
        // — la vincente è la data minima, quindi se non è passata non lo è
        // nessuna — e qui si tiene la forma che non può divergere dalla data
        // che si sta mostrando.
        return ['testo' => self::etichetta($tipo, $data, $scaduta), 'inRitardo' => $scaduta];
    }

    /** «Taratura — scaduta», «Garanzia — oggi», «Manutenzione tra 12 gg». */
    private static function etichetta(string $tipo, CarbonInterface $data, bool $scaduta): string
    {
        if ($scaduta) {
            return $tipo.' — scaduta';
        }

        $giorni = (int) today()->diffInDays($data);

        return $giorni === 0 ? $tipo.' — oggi' : "{$tipo} tra {$giorni} gg";
    }
}

<?php

namespace App\Support\Piattaforma;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gli andamenti a dodici mesi della cabina di regia, in **tre query costanti**
 * (S6 — Wireframe §4; 🔗 ADR-018, ADR-034).
 *
 * Sta accanto a `MetrichePiattaforma` e ne condivide il perimetro: entrambe
 * partono da `PerimetroClienti`, che è l'unica definizione di «cliente» del
 * progetto. Non è un dettaglio di eleganza — è la sola cosa che impedisce al
 * grafico di chiudere su un numero diverso dalla tile che gli sta a due
 * centimetri, e `AndamentiPiattaformaTest` lo verifica punto per punto.
 *
 * ## ⛔ Perché il raggruppamento per mese NON si fa in SQL
 *
 * Non c'è una forma di «raggruppa per mese» che regga su tutti e due i driver:
 * `strftime('%Y-%m', …)` non esiste su Postgres, `to_char(…)` non esiste su
 * SQLite, e `EXTRACT(MONTH …)` **fonderebbe dicembre 2025 con dicembre 2026** —
 * cioè darebbe un grafico plausibile e sbagliato, che è la forma di guasto
 * peggiore per questa pagina. L'unica forma comune è **una sola query per
 * tabella con tredici `SUM(CASE WHEN created_at < ? …)`**, coi confini passati
 * come binding `Y-m-d H:i:s` completi: su SQLite il confronto è lessicografico
 * su quella stringa, quindi corretto; su Postgres è un cast a timestamp.
 *
 * ⚠️ **Gli alias sono scritti a mano e minuscoli** (`c0`…`c12`). È la stessa
 * trappola già documentata dentro `MetrichePiattaforma`: senza alias la chiave
 * del risultato è `count` su Postgres e `count(*)` su SQLite — verde in locale,
 * numeri a zero in CI.
 *
 * ## ⛔ Perché ogni termine porta `created_at is null or`
 *
 * `$table->timestamps()` crea colonne **nullable**. Una riga con `created_at` a
 * NULL cadrebbe fuori da ogni `CASE`, e il grafico chiuderebbe **sotto** il KPI
 * della tile accanto: il numero non sarebbe rotto, sarebbe *plausibile e
 * diverso*. Col ramo `IS NULL` le righe senza data stanno nella base — «erano
 * già lì all'inizio della finestra» — e il cumulato torna sempre.
 *
 * ## I confini sono mezzi aperti, e cadono sulla mezzanotte italiana
 *
 * Ogni mese è `[inizio, fine)`: le 23:59:59 dell'ultimo giorno stanno nel mese
 * che finisce, le 00:00:00 del primo in quello che comincia. I confini seguono
 * `config/app.php`, che dal 6 Set 2026 dichiara `Europe/Rome` (🔗 ADR-041).
 *
 * ⚠️ **Fino a quel giorno erano confini UTC, e l'approssimazione era dichiarata
 * qui**: un cliente creato all'01:30 del 1° settembre ora italiana (23:30 UTC
 * del 31 agosto) finiva nel bucket di agosto, mentre la tabella clienti — a due
 * centimetri sulla stessa schermata — lo datava al 1° settembre. Era il difetto
 * che questo file dichiara di voler impedire, accettato perché «non si cambia il
 * fuso dell'applicazione per un grafico». Il fuso è stato cambiato per ragioni
 * più grosse, e questa incoerenza è sparita di conseguenza: grafico e tabella
 * ora contano lo stesso cliente nello stesso giorno.
 *
 * ## Il costo, e il vincolo da non mollare
 *
 * ⚠️ `Cabina::render()` gira a **ogni** update di Livewire, ricerca compresa:
 * questi tre `SUM(CASE)` si ripagano a ogni tasto. Restano **tre query
 * costanti** — non una per mese e non una per tile — ed è il vincolo da non
 * mollare. Su ~5.000 strumenti è un seq scan da poco; il giorno in cui pesasse,
 * la risposta è un indice su `strumenti(tenant_id, created_at)`, **non** una
 * cache: Redis è condiviso fra `easylab` e `easylab_test`, e questo progetto ha
 * già pagato una volta il prezzo di scriverci dentro dal contesto sbagliato.
 *
 * ⚠️ **Solo contesti HTTP autenticati.** Ogni builder passa da
 * `VistaPiattaforma::porta()`, che fa `Gate::authorize()` e quindi **lancia**
 * senza utente: questa classe non va chiamata da console, job o scheduler. Il
 * precedente vivo è `NotificaScadenze`, che per la stessa ragione non passa di
 * lì.
 */
final class AndamentiPiattaforma
{
    /** Quanti mesi mostra la finestra, mese corrente **compreso**. */
    public const MESI = 12;

    /**
     * Le abbreviazioni italiane dei mesi, indicizzate 1-12.
     *
     * Una costante e non `isoFormat('MMM')` con `locale('it')`: la seconda forma
     * dipende dai locale installati sulla **macchina**, quindi darebbe «Sep» su
     * un container senza l'italiano — e senza dare errore. Le etichette di un
     * grafico non possono dipendere dall'immagine di deploy.
     *
     * @var array<int,string>
     */
    public const ETICHETTE = [
        1 => 'Gen', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mag', 6 => 'Giu',
        7 => 'Lug', 8 => 'Ago', 9 => 'Set', 10 => 'Ott', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * Le tre serie cumulate e la serie dei nuovi clienti, sugli ultimi dodici
     * mesi (mese corrente compreso, e l'ultimo bucket è parziale).
     *
     * `$adesso` esiste per i test dei confini: `Carbon::setTestNow()` basterebbe,
     * ma passare l'istante rende esplicito che la finestra è una funzione del
     * momento in cui si guarda, non uno stato.
     */
    public static function ultimiDodiciMesi(?CarbonImmutable $adesso = null): AndamentoPiattaforma
    {
        $confini = self::confini($adesso);

        // I mesi mostrati sono quelli che **finiscono** ai confini 1..12, cioè
        // quelli che cominciano ai confini 0..11.
        $mesi = [];
        $etichette = [];

        for ($i = 0; $i < self::MESI; $i++) {
            $mesi[] = $confini[$i]->format('Y-m');
            $etichette[] = self::ETICHETTE[(int) $confini[$i]->format('n')];
        }

        // ⚠️ `clienti()` e non `idClienti()`: il secondo porta già `accounts.id`
        // in proiezione, e `select accounts.id, sum(…)` senza `GROUP BY` è un
        // errore su Postgres — che SQLite invece accetta. Verde in locale.
        $clienti = self::conteggiCumulati(PerimetroClienti::clienti(), 'accounts.created_at', $confini);
        $sedi = self::conteggiCumulati(PerimetroClienti::sedi(), 'unita_organizzativa.created_at', $confini);
        $strumenti = self::conteggiCumulati(PerimetroClienti::strumenti(), 'strumenti.created_at', $confini);

        // ⛔ **`base:` non è un di più: senza, il numero scritto a parole accanto
        // alla sparkline misura undici mesi su dodici.** Il primo punto della
        // curva cumulata è `c1` — il totale alla FINE del primo bucket — quindi
        // `ultimo() - primo()` perde tutto ciò che è entrato nel mese di
        // apertura della finestra. La base è `c0`, cioè «quanti ce n'erano già
        // prima che la finestra cominciasse», e va passata a ogni serie
        // cumulata. `nuoviClienti` non ne ha una: è già una differenza.
        return new AndamentoPiattaforma(
            nuoviClienti: new SerieMensile($mesi, $etichette, self::differenze($clienti)),
            clientiCumulati: new SerieMensile($mesi, $etichette, self::cumulato($clienti), base: $clienti[0]),
            sediCumulate: new SerieMensile($mesi, $etichette, self::cumulato($sedi), base: $sedi[0]),
            strumentiCumulati: new SerieMensile($mesi, $etichette, self::cumulato($strumenti), base: $strumenti[0]),
        );
    }

    /**
     * I **tredici** confini di mese della finestra, in UTC.
     *
     * `$confini[0]` è l'inizio del primo mese mostrato; `$confini[12]` è
     * l'inizio del mese **prossimo**, cioè il confine esclusivo che chiude il
     * mese corrente. Tredici e non dodici perché una differenza prima ha bisogno
     * di un punto in più della serie che produce.
     *
     * @return list<CarbonImmutable>
     */
    private static function confini(?CarbonImmutable $adesso): array
    {
        $fine = ($adesso ?? CarbonImmutable::now())->startOfMonth()->addMonth();

        $confini = [];

        for ($i = 0; $i <= self::MESI; $i++) {
            $confini[] = $fine->subMonths(self::MESI - $i);
        }

        return $confini;
    }

    /**
     * Quante righe esistevano **prima** di ciascun confine, in una query sola.
     *
     * @param  Builder<*>  $query  un builder già passato da `VistaPiattaforma`
     * @param  string  $colonna  `created_at` QUALIFICATA col nome della tabella: il
     *                           builder porta sottoquery che nominano altre tabelle,
     *                           e una colonna nuda è ambigua per il pianificatore
     * @param  list<CarbonImmutable>  $confini
     * @return list<int> tredici conteggi, `[c0, …, c12]`
     */
    private static function conteggiCumulati(Builder $query, string $colonna, array $confini): array
    {
        $termini = [];
        $binding = [];

        foreach ($confini as $i => $confine) {
            $termini[] = "sum(case when {$colonna} is null or {$colonna} < ? then 1 else 0 end) as c{$i}";
            $binding[] = $confine->format('Y-m-d H:i:s');
        }

        $riga = $query->selectRaw(implode(', ', $termini), $binding)->first();

        $conteggi = [];

        for ($i = 0; $i <= self::MESI; $i++) {
            // Cast espliciti, per la ragione già scritta in `MetrichePiattaforma`:
            // il tipo di ritorno di un aggregato dipende dal driver e dalla sua
            // configurazione, non dalla query — e `sum()` su zero righe è NULL.
            $conteggi[] = (int) ($riga?->{'c'.$i} ?? 0);
        }

        return $conteggi;
    }

    /**
     * I dodici punti della curva cumulata: `[c1, …, c12]`.
     *
     * `c0` non è un punto della serie — è la **base** su cui poggia la prima
     * differenza, cioè «quanti ce n'erano già prima che la finestra cominciasse».
     *
     * @param  list<int>  $c
     * @return list<int>
     */
    private static function cumulato(array $c): array
    {
        return array_values(array_slice($c, 1));
    }

    /**
     * I dodici incrementi mensili: `[c1-c0, …, c12-c11]`.
     *
     * @param  list<int>  $c
     * @return list<int>
     */
    private static function differenze(array $c): array
    {
        $nuovi = [];

        for ($i = 1; $i <= self::MESI; $i++) {
            $nuovi[] = $c[$i] - $c[$i - 1];
        }

        return $nuovi;
    }
}

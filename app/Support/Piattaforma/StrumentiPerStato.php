<?php

namespace App\Support\Piattaforma;

use App\Enums\StatoSemaforo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * I due filtri di **stato** del Parco clienti — semaforo e obsolescenza — in
 * forma SQL e **dentro il perimetro** (🔗 ADR-005, ADR-014, ADR-037).
 *
 * ## Perché in SQL, e non in PHP sulle righe già paginate
 *
 * 🔴 `RigheParcoStrumenti` calcola il semaforo **riga per riga** sulla pagina, ed
 * è la forma giusta per *mostrarlo*. Filtrare lì sarebbe filtrare **dopo** la
 * paginazione: la pagina 1 tornerebbe 6 righe su 20, il totale in fondo
 * ("1–20 di 47") conterebbe le righe scartate e la barra offrirebbe pagine
 * vuote. Un filtro che mente sui propri conteggi è peggio di un filtro assente —
 * ed è la ragione per cui questa scheda, fino a oggi, il filtro non ce l'aveva.
 *
 * ## ⛔ Il debito che questa classe introduce, dichiarato invece che scoperto
 *
 * `Strumento::scopeConStato()` e `scopeObsoleti()` sono la forma SQL delle due
 * regole, e **non sono riusabili qui**: le tre sottoquery di
 * `Strumento::fontiArancione()` partono da `Intervento::query()` e
 * `Garanzia::query()`, cioè **con** i loro global scope di tenancy. Concatenate
 * a un builder cross-cliente, la query esterna vedrebbe tutti gli Enti e le
 * sottoquery solo il proprio: le macchine altrui uscirebbero **verdi**, con
 * l'elenco «solo arancioni» che torna corto e plausibile. `ParcoClienti` lo
 * vieta per iscritto e `VistaPiattaformaTest` per nome.
 *
 * ⚠️ Quello che si riusa, e quello che si ricopia, va detto con precisione:
 *
 *   - **Le regole restano UNA**. «Aperto ed entro la soglia» è
 *     `Intervento::scopeApertiEntroSoglia()`; «garanzia entro la soglia» è
 *     `Garanzia::scopeEntroSoglia()`; il doppio salto verso i pezzi montati, col
 *     suo bypass di privacy e la sua correlazione, è
 *     `Garanzia::scopeDeiPezziMontatiSullaRiga()`. Qui non se ne riscrive
 *     nessuna: cambia **da quale builder partono** — non `Model::query()` ma
 *     `ParcoClienti::interventi()`/`::garanzie()`, che gli scope di tenancy li
 *     hanno tolti per nome e in cambio portano il perimetro.
 *   - **La COMPOSIZIONE è invece una seconda copia**: «arancione = almeno una
 *     fonte accesa», «verde = il complemento di tutte e tre», «il forzato vince»
 *     stanno anche in `scopeConStato()`, e il confine `< limite+1` di
 *     `scopeObsoleti()` sta anche in `obsoleti()` qui sotto. Oggi coincidono, e
 *     il giorno in cui una sola cambiasse i due schermi direbbero cose diverse
 *     sulla stessa macchina.
 *
 * L'unico filo che le lega sono **due test differenziali** in
 * `tests/Feature/Piattaforma/ParcoStrumentiTest.php` — *«partitions exactly like
 * the per-Ente filter does»* e *«calls obsolete exactly the machines the per-Ente
 * filter calls obsolete»*: girano su un Ente solo, dove entrambe le forme sono
 * legittime, e confrontano gli **insiemi di id**. Cambiarne una sola diventa
 * rosso. La chiusura vera resta quella scritta in `RigheParcoStrumenti`: fonti
 * costruibili non-scopate su `Strumento`, e una composizione sola per tutti —
 * ma `app/Models` è fuori dal perimetro di questo blocco.
 *
 * ## Il costo
 *
 * Il filtro semaforo è **zero query in più**: tre `EXISTS` correlati dentro la
 * query di pagina, come nell'elenco per-Ente. Quello di obsolescenza ne costa
 * **una**, e solo quando è acceso: le soglie delle sedi nel perimetro.
 */
final class StrumentiPerStato
{
    /**
     * La soglia di chi non ne ha scelta una, in anni: il fallback di
     * `Strumento::sogliaObsolescenza()` e del badge ⏳ sulla riga. Le tre
     * letture della stessa regola devono cadere sullo stesso numero.
     */
    private const SOGLIA_PREDEFINITA = 10;

    /**
     * Filtra per stato semaforo **effettivo**: il forzato vince, e solo in sua
     * assenza si guarda il calcolato (🔗 ADR-005 punto 5).
     *
     * I tre rami sono disgiunti ed esaustivi sui quattro valori legali di
     * `forced_state` (NULL più i tre dell'enum): verde + arancione + rosso è
     * tutto il parco nel perimetro. Il rosso esiste **solo** come forzatura —
     * il motore calcolato non lo produce mai — quindi il suo ramo si ferma alla
     * colonna e non interroga nessuna fonte.
     *
     * @param  Builder<Strumento>  $query
     */
    public static function semaforo(Builder $query, StatoSemaforo $stato, Perimetro $perimetro): void
    {
        $query->where(function (Builder $q) use ($stato, $perimetro): void {
            $q->where('strumenti.forced_state', $stato->value);

            if ($stato === StatoSemaforo::Rosso) {
                return;
            }

            $q->orWhere(function (Builder $q) use ($stato, $perimetro): void {
                $q->whereNull('strumenti.forced_state')
                    ->where(function (Builder $q) use ($stato, $perimetro): void {
                        foreach (self::fontiArancione($perimetro) as $i => $fonte) {
                            $stato === StatoSemaforo::Arancione
                                // Basta UNA fonte accesa.
                                ? ($i === 0 ? $q->whereExists($fonte) : $q->orWhereExists($fonte))
                                // Il verde è il complemento di TUTTE e tre.
                                : $q->whereNotExists($fonte);
                        }
                    });
            });
        });
    }

    /**
     * Filtra le macchine obsolete (🔗 ADR-014), forma SQL di
     * `Strumento::isObsoleto()` **con la soglia di ciascuna sede**.
     *
     * 🔴 La soglia è per Ente, e su una vista cross-cliente questo smette di
     * essere un dettaglio: una soglia sola applicata a tutte le righe
     * contraddirebbe il badge ⏳ della riga accanto per ogni cliente che ha
     * scelto un valore diverso dal proprio vicino. È lo stesso difetto che
     * `scopeObsoleti()` ha corretto per l'elenco per-Ente, moltiplicato per il
     * numero di clienti.
     *
     * ⚠️ **Confine `< limite+1`, e mai `<=`.** Su SQLite le colonne `date` sono
     * stringhe `'Y-m-d H:i:s'` e il confronto è lessicografico, quindi
     * `'2026-08-31 00:00:00' <= '2026-08-31'` è **falso** mentre su Postgres è
     * vero: un `<=` passerebbe in locale e cadrebbe in CI. Il confine dell'ADR
     * resta INCLUSIVO — installata esattamente N anni fa oggi è già obsoleta.
     *
     * ⚠️ **Nessun ramo di salvaguardia per la sede senza soglia**, al contrario
     * di `scopeObsoleti()`, e non è una dimenticanza: là le righe visibili e le
     * soglie lette vengono da due insiemi diversi (uno strumento può stare in un
     * Ente cestinato, che il fallback recupera), qui vengono **dallo stesso**
     * — `ParcoClienti::strumenti()` confina le righe alle `idSedi()` del
     * perimetro, che è la query da cui le soglie sono lette. Ogni riga in
     * elenco ha quindi il proprio ramo per costruzione.
     *
     * @param  Builder<Strumento>  $query
     */
    public static function obsoleti(Builder $query, Perimetro $perimetro): void
    {
        $soglie = self::soglieNelPerimetro($perimetro);

        // ⚠️ Zero rami in un `where()` **non filtrano nulla**: senza questa riga
        // il caso limite salterebbe verso «passa tutto» invece che verso «non
        // passa niente», che su un filtro è la direzione sbagliata.
        //
        // ⚠️ **Raggiungibile ma non osservabile, misurato**: l'insieme è vuoto
        // solo quando lo è il perimetro, e con un perimetro vuoto la query
        // esterna non ha righe comunque — togliendo queste quattro righe la
        // suite resta verde. Non è quindi una guardia che un test difende: è la
        // direzione in cui il codice cade, e vale il suo posto solo per quello.
        // Stessa conclusione già scritta sul gemello in `Strumento`.
        if ($soglie === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNotNull('strumenti.data_installazione')
            ->where(function (Builder $q) use ($soglie, $perimetro): void {
                foreach ($soglie as $anni) {
                    // Nessuna aritmetica di intervallo in SQL: la data limite si
                    // calcola in PHP, perché `date_sub`/`INTERVAL` non esistono
                    // uguali sui due driver.
                    $limite = today()->subYears($anni)->addDay()->toDateString();

                    $q->orWhere(fn (Builder $q) => $q
                        ->whereIn('strumenti.tenant_id', self::sediConSoglia($perimetro, $anni))
                        ->where('strumenti.data_installazione', '<', $limite));
                }
            });
    }

    /**
     * Le sedi del perimetro che usano **questa** soglia, come **sottoquery**.
     *
     * 🔴 Sottoquery e non lista di id, ed è la correzione che rende vera la
     * frase di `soglieNelPerimetro()`. La stesura precedente raggruppava
     * correttamente per soglia — K rami invece di N — ma poi ogni ramo portava
     * la lista ESPLICITA degli id, cioè **un binding per sede del perimetro**:
     * l'asse messo fuori dalla porta veniva rimesso dentro dalla finestra, e
     * per giunta due volte, perché la query di pagina ha la sua gemella di
     * COUNT. Il confine principale sulle stesse sedi
     * (`ParcoClienti::strumenti()`) è già una sottoquery poche righe sopra: qui
     * si usa la **stessa forma**, e per costruzione lo stesso insieme.
     *
     * ⚠️ Il ramo `whereNull` accompagna la soglia 10 e non è morto per errore:
     * la colonna è oggi `NOT NULL`, ma `soglieNelPerimetro()` fa cadere una
     * soglia assente su 10 — come `Strumento::sogliaObsolescenza()` e come il
     * badge della riga. Le tre letture devono cadere insieme, e il giorno in
     * cui la colonna diventasse nullable questa riga è ciò che impedisce alle
     * sedi senza soglia di sparire dal filtro mentre il ⏳ le dichiara obsolete.
     *
     * @return Builder<UnitaOrganizzativa>
     */
    private static function sediConSoglia(Perimetro $perimetro, int $anni): Builder
    {
        $sedi = ParcoClienti::idSedi($perimetro);

        if ($anni !== self::SOGLIA_PREDEFINITA) {
            return $sedi->where('unita_organizzativa.soglia_obsolescenza_anni', $anni);
        }

        return $sedi->where(fn (Builder $q) => $q
            ->where('unita_organizzativa.soglia_obsolescenza_anni', $anni)
            ->orWhereNull('unita_organizzativa.soglia_obsolescenza_anni'));
    }

    /**
     * Le tre fonti dell'arancione (🔗 ADR-005 · ADR-004 · ADR-020) come
     * sottoquery **correlate** alla riga `strumenti`, costruite fuori dagli
     * scope di tenancy e dentro il perimetro.
     *
     * ⚠️ **Builder NUOVI a ogni chiamata**, per la stessa ragione scritta su
     * `Strumento::fontiArancione()`: i binding contengono `today()` e gli id del
     * perimetro. Memoizzarli congelerebbe la soglia di ieri, o il perimetro di
     * un'altra richiesta, sotto un worker persistente.
     *
     * ⚠️ Il perimetro **dentro** l'`EXISTS` è ridondante — un intervento non può
     * appartenere a un Ente diverso da quello del proprio strumento, e le righe
     * esterne sono già confinate — ma passare comunque da `ParcoClienti` è ciò
     * che tiene questa lettura cross-cliente dentro la porta unica, invece di
     * essere il primo `Intervento::query()->withoutGlobalScopes()` scritto a mano
     * fuori da essa.
     *
     * @return list<Builder<*>>
     */
    private static function fontiArancione(Perimetro $perimetro): array
    {
        return [
            // Un intervento aperto già scaduto o entro la soglia.
            ParcoClienti::interventi($perimetro)->select(DB::raw('1'))
                ->whereColumn('interventi.strumento_id', 'strumenti.id')
                ->apertiEntroSoglia(),

            // La garanzia della macchina (🔗 ADR-004).
            ParcoClienti::garanzie($perimetro)->select(DB::raw('1'))
                ->whereColumn('garanzie.strumento_id', 'strumenti.id')
                ->entroSoglia(),

            // La garanzia di un pezzo MONTATO (🔗 ADR-020): doppio salto,
            // correlazione e bypass del privacy scope stanno tutti nello scope,
            // che è l'unico punto del progetto autorizzato a farlo.
            ParcoClienti::garanzie($perimetro)->select(DB::raw('1'))
                ->deiPezziMontatiSullaRiga()
                ->entroSoglia(),
        ];
    }

    /**
     * Le soglie **distinte** in uso nel perimetro. **Una query.**
     *
     * ⚠️ Un ramo per soglia e non uno per sede: le soglie distinte sono un
     * pugno, le sedi della piattaforma no. Un ramo `OR` per sede — la forma di
     * `scopeObsoleti()`, corretta là dove gli Enti visibili sono uno o pochi —
     * qui crescerebbe con il numero di **clienti**, cioè con l'unica dimensione
     * che questa scheda esiste per far crescere.
     *
     * 🔴 Si leggono i **valori** e non più la mappa `soglia → id delle sedi`:
     * di quegli id il filtro non ha bisogno, perché ogni ramo ritrova le proprie
     * sedi con una sottoquery (`sediConSoglia()`). Tenerli significava
     * trasportarli come binding, cioè far crescere l'SQL sull'asse che questo
     * docblock dichiara di aver chiuso — la mappa era la prova che la frase non
     * era ancora vera. Un test conta i binding con due sedi e con ventidue.
     *
     * Il fallback a 10 è quello di `Strumento::sogliaObsolescenza()` e quello
     * del badge sulla riga: una sede senza soglia non perde il proprio ramo, o
     * le sue macchine sparirebbero dal filtro mentre il ⏳ continua a
     * dichiararle obsolete.
     *
     * @return list<int>
     */
    private static function soglieNelPerimetro(Perimetro $perimetro): array
    {
        return ParcoClienti::sedi($perimetro)
            ->pluck('unita_organizzativa.soglia_obsolescenza_anni')
            ->map(fn ($anni) => (int) ($anni ?? self::SOGLIA_PREDEFINITA))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}

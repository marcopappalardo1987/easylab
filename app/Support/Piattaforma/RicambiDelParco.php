<?php

namespace App\Support\Piattaforma;

use App\Models\Account;
use App\Models\Ricambio;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Le righe della scheda **Ricambi** del Parco clienti (🔗 ADR-037).
 *
 * Il catalogo ricambi di tutti i clienti nel perimetro, in **sola lettura**: la
 * domanda che questa scheda pone non è quella di `RicercaRicambi` (🔗 ADR-008,
 * «dove è montato questo pezzo», dentro un Ente solo) ma «**che pezzi hanno in
 * catalogo i clienti che sto guardando**», e la risposta deve dire di chi è ogni
 * riga — cliente e sede — perché è ciò che impedisce di agire sul cliente
 * sbagliato quando da qui si passa all'impersonazione.
 *
 * ## Ogni query passa da `ParcoClienti`, e non è una formalità
 *
 * ⛔ Qui non si toglie un solo global scope a mano e non si legge da
 * `VistaPiattaforma`: quei builder non sono filtrati per cliente — servono a
 * **contare**, non a **elencare** — e usarli mostrerebbe «tutti» mentre il
 * filtro in cima alla pagina dice altro. `ParcoBypassGuardrailTest` lo rende
 * rosso; questo docblock dice perché il guardrail ha ragione.
 *
 * ⚠️ **Né garanzie né utilizzi compaiono qui, e la ragione è che la porta non
 * li consegna — non che uno scope li filtri.** Non c'è nessuna colonna
 * «garanzia» in questa scheda, e non ci deve arrivare per deduzione: se domani
 * servisse, si legge da `ParcoClienti::garanzie()`. ⛔ Chi la scrivesse invece
 * qui non sarebbe coperto da niente: `GaranziaRicambioPrivacyScope` (🔗 ADR-029)
 * lo registra `Garanzia`, non `Ricambio`, quindi su questo builder non esiste
 * un filtro da cui ereditare il controllo — le righe uscirebbero **nude**.
 * Nemmeno gli **utilizzi** compaiono: `RicambioUtilizzo` è deliberatamente
 * fuori dalla porta (due scope in più, cioè due domande a cui questa schermata
 * non deve rispondere), quindi qui non si dice su quali macchine un pezzo sia
 * montato.
 *
 * ## Ciò che NON si può ordinare, e perché è una decisione
 *
 * 🔴 **Non si ordina per cliente né per sede.** Sarebbe la colonna che viene da
 * sé su una vista cross-cliente, e si scriverebbe con una `join` su
 * `unita_organizzativa`/`accounts` oppure con una sottoquery `orderBy(
 * UnitaOrganizzativa::select('nome')->whereColumn(...))`. Entrambe sono
 * trappole, in due modi diversi:
 *
 *   - la **join** porta in query `unita_organizzativa` **senza i suoi scope**,
 *     soft delete compreso: righe di sedi cestinate rientrerebbero dalla
 *     finestra dopo che il perimetro le aveva escluse dalla porta;
 *   - la **sottoquery** parte da un model **scopato**, quindi per il Superadmin
 *     `TenantScope` la ridurrebbe al proprio Ente: la chiave di ordinamento
 *     tornerebbe `NULL` per ogni riga degli altri clienti, e l'elenco
 *     sembrerebbe ordinato mentre non lo è. È la stessa forma di difetto che
 *     ADR-037 dichiara sugli scope `conStato()`/`obsoleti()`: una cifra
 *     plausibile, non una mancante.
 *
 * Il raggruppamento per cliente lo fa il **filtro** in cima alla pagina, che
 * passa dalla porta e quindi non ha nessuno dei due problemi.
 *
 * ⚠️ **Non si ordina nemmeno per `codice`**, ed è una divergenza fra driver e
 * non un capriccio: la colonna è nullable (🔗 ADR-022 l'ha resa facoltativa) e
 * i NULL vanno **primi** su SQLite e **ultimi** su Postgres in ordine crescente.
 * L'elenco non si spaccherebbe — il tie-break sull'id tiene la paginazione
 * stabile su entrambi — ma il blocco dei pezzi senza codice comparirebbe in cima
 * in locale e in fondo in produzione, cioè la classe di difetto che CLAUDE.md
 * registra sulle date. Il codice si **cerca**, non si ordina.
 *
 * ⚠️ L'ordinamento per nome usa `nome_normalizzato` e non `nome`: quest'ultimo
 * dipenderebbe dalla collation del driver, e due ambienti darebbero due ordini
 * per la stessa pagina. È la stessa scelta di `RicercaRicambi`.
 */
final class RicambiDelParco
{
    /**
     * Le colonne per cui si può ordinare → la colonna SQL che le realizza.
     *
     * Cliente, sede e codice **non ci sono**: vedi il docblock di classe. La
     * mappa è la sola whitelist, ed è consultata sia da `elenco()` (che ordina)
     * sia dalla vista (che disegna la freccia): due copie divergerebbero, e la
     * pagina indicherebbe una colonna mentre la query ne usa un'altra.
     */
    public const COLONNE_ORDINE = [
        'nome' => 'ricambi.nome_normalizzato',
        'created_at' => 'ricambi.created_at',
    ];

    /** Le taglie di pagina offerte. */
    public const PER_PAGE = [20, 50, 100];

    private const PER_PAGE_PREDEFINITA = 20;

    private const ORDINE_PREDEFINITO = 'nome';

    /**
     * I filtri **come la query li userà davvero**, e non come arrivano dal
     * browser.
     *
     * Ogni property del componente è `#[Url]`: `sortBy` può valere `password`, e
     * finisce dentro un `orderBy()`. La normalizzazione sta **qui** e non nel
     * componente perché ha tre consumatori — la query, la freccia in
     * intestazione e la riga «filtri attivi» — e tre copie della stessa
     * whitelist divergono. È la lezione già scritta in `ElencaClienti`.
     *
     * ⚠️ La ricerca si ripulisce con `Ricambio::ripulisciNome()` e non con
     * `trim()`: i copia-incolla da PDF portano lo spazio unificatore (NBSP), che
     * `trim()` non toglie. Senza questa riga una casella che **sembra** vuota
     * sarebbe una ricerca attiva su un carattere invisibile, e la pagina direbbe
     * «nessun pezzo» a chi non ha chiesto nulla.
     *
     * @return array{search: string, sortBy: string, sortDir: string, perPage: int}
     */
    public static function filtriNormalizzati(
        string $search,
        string $sortBy,
        string $sortDir,
        int $perPage,
    ): array {
        try {
            $cercato = Ricambio::ripulisciNome($search);
        } catch (InvalidArgumentException) {
            // Casella vuota, o di soli spazi (NBSP compreso): non è una domanda.
            $cercato = '';
        }

        return [
            'search' => $cercato,
            'sortBy' => array_key_exists($sortBy, self::COLONNE_ORDINE) ? $sortBy : self::ORDINE_PREDEFINITO,
            'sortDir' => mb_strtolower($sortDir) === 'desc' ? 'desc' : 'asc',
            'perPage' => in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE_PREDEFINITA,
        ];
    }

    /**
     * Le voci di catalogo dei clienti nel perimetro, filtrate e ordinate.
     *
     * ⚠️ **`->orderBy('ricambi.id')` non è decorativo**: a parità di nome —
     * ed è il caso normale, visto che clienti diversi chiamano lo stesso pezzo
     * allo stesso modo — l'ordine fra due pagine è una proprietà del motore.
     * Postgres può riordinare i pari fra la query di pagina 1 e quella di pagina
     * 2, e una riga esce da **entrambe** (CLAUDE.md, 25 Ago 2026). Su questa
     * scheda i pari non sono l'eccezione: sono la regola.
     *
     * @param  array{search: string, sortBy: string, sortDir: string, perPage: int}  $filtri
     * @return Builder<Ricambio>
     */
    public static function elenco(Perimetro $perimetro, array $filtri): Builder
    {
        $query = ParcoClienti::ricambi($perimetro);

        if ($filtri['search'] !== '') {
            // La regola di normalizzazione si **chiama**, non si riscrive in
            // SQL: è l'intero motivo per cui la colonna `nome_normalizzato`
            // esiste (ERD §7.1). Un `lower(nome)` nel WHERE non userebbe
            // l'indice e — peggio — `like` è case-insensitive su SQLite e
            // case-SENSITIVE su Postgres, cioè una ricerca che funziona in
            // locale e non in produzione.
            $nome = addcslashes(Ricambio::normalizzaNome($filtri['search']), '%_\\');

            // Il codice si cerca **grezzo**, solo abbassato di caso: non è un
            // nome, e collassarne gli spazi non avrebbe senso.
            $codice = addcslashes(mb_strtolower($filtri['search'], 'UTF-8'), '%_\\');

            $query->where(function (Builder $q) use ($nome, $codice) {
                // `%`, `_` e `\` diventano testo: chi cerca «50%» cerca il pezzo
                // che si chiama così, non «tutto ciò che inizia per 50». Il
                // carattere di escape va DICHIARATO perché SQLite, a differenza
                // di Postgres, non ne ha uno per default.
                $q->whereRaw('"ricambi"."nome_normalizzato" like ? escape \'\\\'', ['%'.$nome.'%'])
                    ->orWhereRaw('lower("ricambi"."codice") like ? escape \'\\\'', ['%'.$codice.'%']);
            });
        }

        return $query
            ->orderBy(self::COLONNE_ORDINE[$filtri['sortBy']], $filtri['sortDir'])
            ->orderBy('ricambi.id');
    }

    /**
     * 🔴 **Di chi sono le righe in pagina**: sede, cliente, e in quanti clienti
     * del perimetro lo stesso pezzo compare.
     *
     * È il requisito posto per primo dal committente — su ogni riga si deve
     * vedere di **chi** è il dato — ed è anche ciò che rende sicuro il tasto
     * «impersona» accanto: si entra in casa del cliente il cui nome si sta
     * leggendo.
     *
     * Tre query, non una per riga:
     *
     *   1. i «gemelli»: le righe di catalogo che portano lo stesso nome
     *      normalizzato di quelle in pagina, **dentro il perimetro**;
     *   2. le sedi di tutte quelle righe, dalla porta;
     *   3. i clienti di quelle sedi, dalla porta.
     *
     * ⚠️ **Tre query, ma non tre query piccole, e va detto invece di lasciar
     * credere il contrario.** Il *numero* è costante; il *volume* della (1) no:
     * cresce col numero di clienti del perimetro, non con la pagina. Una pagina
     * da 100 nomi presenti presso 100 clienti sono 10.000 righe — e `render()`
     * rigira a ogni battuta della casella di ricerca. Per questo i gemelli si
     * leggono con `toBase()`, cioè come righe grezze e non come model `Ricambio`
     * idratati: di quelle righe servono due colonne, e l'idratazione era l'unica
     * parte del costo che non serviva a niente. Resta aperto il tetto vero — il
     * `whereIn` della (2) porta un id per sede toccata, e su Postgres i bind
     * parameter si fermano a 65.535: sopra quell'ordine di grandezza la
     * diffusione va ripensata come conteggio in SQL, non come lettura.
     *
     * ⚠️ **La diffusione si conta nel perimetro e non sulla piattaforma**, ed è
     * la ragione per cui la query (1) riparte da `ParcoClienti::ricambi()`
     * invece che da un conteggio globale: un numero che ignorasse il filtro
     * direbbe «presente presso 9 clienti» sopra una pagina che ne mostra 2, e
     * chi legge non avrebbe modo di sapere quale delle due cifre è la risposta
     * alla sua domanda. La vista lo scrive per esteso accanto al numero.
     *
     * ⚠️ La chiave del conteggio è l'**account**, non la sede: un cliente con
     * cinque sedi che ha lo stesso pezzo a catalogo in tutte è **un** cliente,
     * e contare le sedi gonfierebbe la diffusione proprio sui clienti più
     * grandi — cioè dove il numero verrebbe guardato di più.
     *
     * @param  Collection<int, Ricambio>  $pagina
     * @return array{
     *     sedi: Collection<int, UnitaOrganizzativa>,
     *     clienti: Collection<int, Account>,
     *     clientiInPagina: Collection<int, Account>,
     *     diffusione: array<string, int>,
     * }
     */
    public static function contesto(Perimetro $perimetro, Collection $pagina): array
    {
        if ($pagina->isEmpty()) {
            // ⚠️ Collection **di Eloquent** e non quella di Support: il chiamante
            // passa `clientiInPagina` a `candidatiDellaPagina()`, che apre con un
            // `loadMissing('membri.roles')` — un metodo che la Collection base
            // non ha. Con la pagina piena il tipo arriva da `get()` ed è quello
            // giusto; il ramo vuoto è l'unico che lo sceglie a mano, quindi è
            // l'unico che può sbagliarlo, e sbaglierebbe **solo** quando non c'è
            // nessuna riga — cioè proprio dove nessuno guarda.
            return [
                'sedi' => new EloquentCollection,
                'clienti' => new EloquentCollection,
                'clientiInPagina' => new EloquentCollection,
                'diffusione' => [],
            ];
        }

        // ⚠️ `toBase()` e non `get()` sull'Eloquent builder: `toBase()` **applica**
        // gli scope e poi consegna il query builder, quindi il perimetro della
        // porta e il soft delete restano dove sono — si perde solo l'idratazione
        // in model, che qui è puro costo: di queste righe servono due colonne e
        // nessun comportamento.
        $gemelli = ParcoClienti::ricambi($perimetro)
            ->whereIn('ricambi.nome_normalizzato', $pagina->pluck('nome_normalizzato')->unique()->values()->all())
            ->distinct()
            ->toBase()
            ->get(['ricambi.nome_normalizzato', 'ricambi.tenant_id']);

        $sedi = ParcoClienti::sedi($perimetro)
            ->whereIn('unita_organizzativa.id', $pagina->pluck('tenant_id')
                ->concat($gemelli->pluck('tenant_id'))
                ->unique()
                ->values()
                ->all())
            ->get(['unita_organizzativa.id', 'unita_organizzativa.nome', 'unita_organizzativa.account_id'])
            ->keyBy('id');

        $clienti = ParcoClienti::clienti($perimetro)
            ->whereIn('accounts.id', $sedi->pluck('account_id')->unique()->values()->all())
            ->get(['accounts.id', 'accounts.ragione_sociale', 'accounts.piano'])
            ->keyBy('id');

        // Il raggruppamento si fa in memoria, su righe già lette: un secondo
        // `group by` in SQL costerebbe una query per lo stesso numero, e
        // servirebbe comunque la mappa sede → cliente per contare gli account.
        $diffusione = $gemelli
            ->groupBy('nome_normalizzato')
            ->map(fn (Collection $righe) => $righe
                ->map(fn (object $r) => $sedi->get($r->tenant_id)?->account_id)
                ->filter()
                ->unique()
                ->count())
            ->all();

        $inPagina = $pagina
            ->map(fn (Ricambio $r) => $sedi->get($r->tenant_id)?->account_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'sedi' => $sedi,
            'clienti' => $clienti,
            'clientiInPagina' => $clienti->only($inPagina)->values(),
            'diffusione' => $diffusione,
        ];
    }
}

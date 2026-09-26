<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Support\Piani;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * L'elenco dei clienti della cabina di regia (S6 — Wireframe §4).
 *
 * **La riga è l'Account, non l'Ente**, e si espande nelle sue sedi. Il wireframe
 * originale elencava Enti con le colonne Piano e Stato, ma è **antecedente ad
 * ADR-032**: piano, lockout e dati fiscali sono dell'Account, e una tabella per
 * sede ripeterebbe quei valori su ogni riga dello stesso cliente. Un Ente
 * «bloccato» non esiste — è il contratto a esserlo.
 *
 * Vive in un concern e non dentro `Cabina` per la ragione che il progetto si è
 * già dato guardando `ElencoStrumenti` diventare un file da 500 righe: la
 * cabina raccoglierà anche le quattro leve, e sarebbe la stessa storia.
 *
 * 🔴 **`queryClienti()` È il perimetro della pagina, e non solo della pagina.**
 * Dal blocco delle esportazioni (S6) ci passa anche `EsportaClienti`: il file
 * che esce dall'applicazione contiene esattamente le righe che la tabella
 * mostrerebbe sfogliando fino in fondo, perché la definizione è **una**. Una
 * seconda copia della catena di filtri — per esempio in una rotta che li
 * rileggesse dalla query string — è precisamente il posto in cui nasce «il file
 * esporta tutto mentre la pagina ne mostra dodici».
 *
 * ⚠️ **`EsportaClienti` non è usabile senza questo trait**: raggiunge
 * `queryClienti()` e `dettagliDellaPagina()`, che sono `private`, solo perché i
 * trait vengono appiattiti dentro `Cabina`. La dipendenza è dichiarata su
 * entrambi i lati perché il progetto ha già pagato il caso opposto — concern che
 * si scrivevano le property a vicenda senza dirlo — ed è la ragione per cui
 * `chiudiOgniModale()` vive su `Cabina` e non nei concern.
 */
trait ElencaClienti
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $piano = '';

    #[Url]
    public string $stato = '';

    #[Url]
    public string $sortBy = 'ragione_sociale';

    #[Url]
    public string $sortDir = 'asc';

    #[Url]
    public int $perPage = 20;

    /** L'id della riga espansa, o `null`. Una alla volta: due aprono una lista, non un dettaglio. */
    public ?int $espanso = null;

    private const ORDINABILI = ['ragione_sociale', 'piano', 'created_at'];

    private const PER_PAGE = [20, 50, 100];

    private const STATI = ['attivo', 'bloccato_manuale', 'bloccato_stripe'];

    /**
     * Come si chiamano i filtri **per una persona**: le stesse parole delle
     * `<option>` del blade e delle intestazioni della tabella. Servono al foglio
     * esportato, che non ha la pagina accanto per farsi tradurre.
     */
    private const ETICHETTE_STATO = [
        'attivo' => 'Attivi',
        'bloccato_manuale' => 'Bloccati a mano',
        'bloccato_stripe' => 'Bloccati da Stripe',
    ];

    private const ETICHETTE_ORDINE = [
        'ragione_sociale' => 'Cliente',
        'piano' => 'Piano',
        'created_at' => 'Cliente dal',
    ];

    /** Valore del filtro Piano che isola le righe il cui piano non è più a catalogo. */
    public const FUORI_CATALOGO = '__fuori_catalogo';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPiano(): void
    {
        $this->resetPage();
    }

    public function updatingStato(): void
    {
        $this->resetPage();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function ordina(string $colonna): void
    {
        // La whitelist è qui **e** in `render()`: questa ferma il click, quella
        // ferma la query string. Sono due strade, non una ripetizione.
        if (! in_array($colonna, self::ORDINABILI, true)) {
            return;
        }

        // ⚠️ `resetPage()` anche qui, e non solo negli hook dei filtri: cambiare
        // ordinamento rimescola **tutte** le righe, quindi restare a pagina 3
        // fa atterrare a metà di un elenco che non si è mai visto dall'inizio.
        // È la riga che `ElencoStrumenti::sort()` ha e che questo trait,
        // dichiarando di imitarlo, aveva perso per strada.
        $this->resetPage();

        if ($this->sortBy === $colonna) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortBy = $colonna;
        $this->sortDir = 'asc';
    }

    /**
     * L'altra strada per cambiare riga espansa: la property, che Livewire
     * accetta dal browser senza passare da `espandi()`.
     *
     * Oggi non è un trapelamento — la vista usa `$espanso` solo per indicizzare
     * un gruppo già ristretto alla pagina — ma un id arbitrario che *entra* e
     * poi non trova nulla è un'invariante che sembra chiusa e non lo è. Si
     * chiude con la stessa rilettura dell'azione, così le due strade dicono la
     * stessa cosa invece che due cose diverse.
     */
    public function updatingEspanso(mixed $valore): void
    {
        if ($valore !== null) {
            VistaPiattaforma::accounts()->whereKey($valore)->firstOrFail();
        }
    }

    /** Le taglie di pagina offerte dalla vista: la costante non è raggiungibile da Blade. */
    public function opzioniPerPage(): array
    {
        return self::PER_PAGE;
    }

    /**
     * L'ordinamento **effettivamente applicato**, per la freccia in intestazione.
     *
     * ⚠️ La vista non deve leggere `$sortBy`/`$sortDir`: quelle sono le property
     * come arrivano dal browser, e possono valere `password` mentre la query ha
     * usato il fallback. La freccia direbbe una colonna e l'elenco ne mostrerebbe
     * un'altra — una bugia piccola, e proprio per questo credibile.
     *
     * @return array{0: string, 1: string}
     */
    public function ordinamentoEffettivo(): array
    {
        $f = $this->filtriNormalizzati();

        return [$f['sortBy'], $f['sortDir']];
    }

    /**
     * 🔴 I filtri **come la query li userà davvero**, e non come arrivano dal
     * browser.
     *
     * ⚠️ Ogni property qui sotto è `#[Url]`: vale ciò che c'è nella query
     * string, e `sortBy` può valere `password`. Le whitelist stavano in due
     * posti — `clienti()` e `ordinamentoEffettivo()` — e questo metodo le mette
     * in uno: due copie della stessa whitelist divergono, e il giorno in cui
     * divergono la pagina ordina per una colonna e ne indica un'altra.
     *
     * 🔴 **Il terzo consumatore è l'esportazione**, ed è quello che ha reso
     * l'estrazione necessaria invece che elegante: il foglio PDF stampa «Filtri
     * attivi», e leggere là le property grezze farebbe uscire dall'applicazione,
     * su carta, un elenco di filtri che la query non ha applicato. È la stessa
     * bugia della freccia di intestazione, con la differenza che una freccia si
     * corregge ricaricando e un PDF resta.
     *
     * @return array{search: string, piano: string, stato: string, sortBy: string, sortDir: string, perPage: int}
     */
    private function filtriNormalizzati(): array
    {
        // `FUORI_CATALOGO` è un valore legittimo che per costruzione NON è un
        // codice a listino: va riconosciuto prima di chiedere `Piani::esiste()`,
        // o cadrebbe nel ramo «piano inesistente» e il filtro sparirebbe.
        $piano = match (true) {
            $this->piano === self::FUORI_CATALOGO => self::FUORI_CATALOGO,
            $this->piano !== '' && Piani::esiste($this->piano) => $this->piano,
            default => '',
        };

        return [
            'search' => trim($this->search),
            'piano' => $piano,
            'stato' => in_array($this->stato, self::STATI, true) ? $this->stato : '',
            'sortBy' => in_array($this->sortBy, self::ORDINABILI, true) ? $this->sortBy : 'ragione_sociale',
            'sortDir' => mb_strtolower($this->sortDir) === 'desc' ? 'desc' : 'asc',
            'perPage' => in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : 20,
        ];
    }

    /**
     * I filtri attivi **in prosa**, per il foglio esportato e per la riga di
     * audit dell'esportazione.
     *
     * Le etichette sono le stesse delle `<option>` del blade: un foglio che
     * chiamasse «bloccato_stripe» ciò che a schermo si chiama «Bloccati da
     * Stripe» costringerebbe chi lo legge a tradurre, e chi traduce sbaglia.
     *
     * ⚠️ Legge **solo** `filtriNormalizzati()`: vedi il docblock di quel metodo.
     *
     * L'ordinamento compare **solo se non è quello di partenza**: così l'array
     * è vuoto quando nessun filtro è attivo — che è la condizione con cui il
     * foglio decide fra «Filtri attivi: …» e «Nessun filtro: tutti i clienti» —
     * e quando compare descrive l'ordine che le righe hanno davvero.
     *
     * @return array<string, string>
     */
    public function filtriAttivi(): array
    {
        $f = $this->filtriNormalizzati();
        $attivi = [];

        if ($f['search'] !== '') {
            $attivi['Ricerca'] = '«'.$f['search'].'»';
        }

        if ($f['piano'] === self::FUORI_CATALOGO) {
            $attivi['Piano'] = 'Fuori catalogo';
        } elseif ($f['piano'] !== '') {
            $attivi['Piano'] = Piani::etichetta($f['piano']);
        }

        if ($f['stato'] !== '') {
            $attivi['Stato'] = self::ETICHETTE_STATO[$f['stato']];
        }

        if ($f['sortBy'] !== 'ragione_sociale' || $f['sortDir'] !== 'asc') {
            $attivi['Ordinato per'] = self::ETICHETTE_ORDINE[$f['sortBy']]
                .' '.($f['sortDir'] === 'asc' ? '↑' : '↓');
        }

        return $attivi;
    }

    /**
     * Apre o chiude la riga di un cliente.
     *
     * ⚠️ **Apre con `porta()`**, benché non legga nulla di suo: l'id arriva dal
     * browser, e `Account` non ha global scope. Il gate di rotta copre già
     * questa strada — `Authorize` è fra i middleware persistenti di Livewire —
     * ma la disciplina è che ogni azione che accetta un id chieda il permesso
     * per conto proprio: il giorno in cui una di queste azioni **scrive**, il
     * `render()` che oggi gata le letture gira dopo, e con `skipRender()` non
     * gira affatto.
     */
    public function espandi(int $accountId): void
    {
        VistaPiattaforma::accounts()->whereKey($accountId)->firstOrFail();

        $this->espanso = $this->espanso === $accountId ? null : $accountId;
    }

    /** @return LengthAwarePaginator<Account> */
    private function clienti(): LengthAwarePaginator
    {
        return $this->queryClienti()->paginate($this->filtriNormalizzati()['perPage'])->onEachSide(1);
    }

    /**
     * 🔴 **Il perimetro.** Le righe che questa pagina può mostrare, filtrate e
     * ordinate, senza ancora decidere quante se ne guardano per volta.
     *
     * Separata da `clienti()` perché la **paginazione non è un filtro di
     * privacy**: chi vede la prima pagina può sfogliare fino all'ultima, quindi
     * l'esportazione copre tutto il filtrato e non la sola pagina. Le due cose
     * hanno bisogno della stessa catena di `where` e di ordini, e averne una
     * copia per consumatore è il modo in cui il file e lo schermo smettono di
     * dire la stessa cosa.
     *
     * ⚠️ **L'ordine finisce con `->orderBy('id')`, e non è decorativo**: a parità
     * di chiave l'ordine fra due pagine è una proprietà del motore — Postgres
     * può riordinare i pari fra la query di pagina 1 e quella di pagina 2, e una
     * riga esce da **entrambe** (CLAUDE.md, 25 Ago 2026). Chi riusa questa query
     * eredita il tie-break; chi la riscrive lo perde, e ottiene un file con una
     * riga doppia e una mancante.
     *
     * @return Builder<Account>
     */
    private function queryClienti(): Builder
    {
        // Ri-validazione **a ogni render** e non solo negli hook: questi valori
        // arrivano dalla query string senza passare da `updatingXxx()`, e
        // `sortBy` finisce dentro un `orderBy()`. È la lezione di
        // `ElencoStrumenti`, dove la whitelist copre tutte e tre le proprietà.
        //
        // Una definizione sola, condivisa con la freccia in intestazione e col
        // foglio esportato: due copie della stessa whitelist divergono, e il
        // giorno in cui divergono la pagina ordina per una colonna e ne indica
        // un'altra.
        $f = $this->filtriNormalizzati();
        [$sortBy, $sortDir] = [$f['sortBy'], $f['sortDir']];

        $query = VistaPiattaforma::accounts();

        if ($f['search'] !== '') {
            // I jolly di LIKE si neutralizzano: senza, `%` da solo restituisce
            // ogni cliente e `_` diventa «un carattere qualunque». `ESCAPE` va
            // dichiarato perché SQLite, a differenza di Postgres, non ha un
            // carattere di escape di default.
            $ricerca = addcslashes(mb_strtolower($f['search']), '%_\\');

            $query->where(function ($q) use ($ricerca) {
                // `LOWER LIKE` e non `ILIKE`: quest'ultimo è solo Postgres, e la
                // suite gira anche su SQLite. Stessa forma di `ElencoStrumenti`.
                //
                // ⚠️ **`LOWER()` non si comporta allo stesso modo sui due
                // driver**: quello di SQLite converte solo A–Z, quello di
                // Postgres è UTF-8-aware. Cercare «SOCIETÀ» trova in CI e in
                // produzione, **non in locale** — divergenza nella direzione
                // insolita, quindi un test onesto su questo caso nascerebbe
                // rosso sulla macchina di sviluppo. Chi vorrà chiuderla dovrà
                // normalizzare gli accenti a monte, non cambiare la funzione.
                foreach (['ragione_sociale', 'partita_iva', 'codice_fiscale'] as $colonna) {
                    $q->orWhereRaw("LOWER({$colonna}) LIKE ? ESCAPE '\\'", ["%{$ricerca}%"]);
                }

                // Anche per **nome di sede**: chi cerca «San Raffaele» pensa al
                // laboratorio, non alla ragione sociale che lo fattura. In SQL e
                // non filtrando in PHP dopo la paginazione, che darebbe pagine
                // incomplete e conteggi sbagliati.
                //
                // Da `VistaPiattaforma::enti()` e non da un `withoutGlobalScopes()`
                // nudo: il nudo toglie anche il soft delete, e una sede cestinata
                // farebbe comparire in ricerca un cliente che nella tabella si
                // mostra poi con zero sedi. È lo stesso difetto che il guardrail
                // dei bypass nudi esiste per fermare — e lo ha fermato qui.
                $q->orWhereIn('id', VistaPiattaforma::enti()
                    ->where('tipo', TipoUnitaOrganizzativa::Ente)
                    ->whereRaw("LOWER(nome) LIKE ? ESCAPE '\\'", ["%{$ricerca}%"])
                    ->select('account_id'));
            });
        }

        // ⚠️ La voce `?` — «fuori catalogo» — non è un di più: l'avviso sopra la
        // tabella dice che quei clienti si riparano da qui, e senza un filtro
        // che li isoli, su trecento righe non c'è modo di trovarli. Un banner
        // che segnala un problema e non dà la strada per raggiungerlo è peggio
        // che tacere.
        if ($f['piano'] === self::FUORI_CATALOGO) {
            $query->whereNotIn('piano', Piani::codici());
        } elseif ($f['piano'] !== '') {
            $query->where('piano', $f['piano']);
        }

        if ($f['stato'] !== '') {
            match ($f['stato']) {
                'attivo' => $query->where('is_locked', false),
                // Le due sorgenti si filtrano **separatamente**, come si mostrano
                // (ADR-013): «bloccato» da solo nasconderebbe la distinzione che
                // esiste apposta per non far riaprire un contenzioso a un
                // pagamento riuscito.
                'bloccato_manuale' => $query->whereNotNull('locked_at'),
                'bloccato_stripe' => $query->whereNotNull('stripe_locked_at'),
            };
        }

        return $query->orderBy($sortBy, $sortDir)->orderBy('id');
    }

    /**
     * Sedi e strumenti dei soli account **in pagina**, in due query a costo
     * costante — il pattern di `ElencoStrumenti::render()`.
     *
     * Le sedi servono sia al conteggio sia all'espansione: aprire una riga non
     * costa una query, perché il dato è già qui.
     *
     * @param  Collection<int,Account>  $pagina
     * @return array{sedi: Collection<int,Collection<int,UnitaOrganizzativa>>, strumenti: array<int,int>, perSede: array<int,int>}
     */
    private function dettagliDellaPagina(Collection $pagina): array
    {
        $ids = $pagina->pluck('id');

        $sedi = VistaPiattaforma::enti()
            ->where('tipo', TipoUnitaOrganizzativa::Ente)
            ->whereIn('account_id', $ids)
            ->orderBy('nome')
            // ⚠️ `visibilita_garanzie_ricambio` è **indispensabile**, non decorativa: la
            // `select` dell'espansione la legge per marcare l'opzione corrente. Senza,
            // il model esce da `newFromBuilder()` con `setRawAttributes()`, che spazza
            // via il default dichiarato in `$attributes`; l'accessor entra comunque nel
            // cast e restituisce `null`, senza errori. Nessuna `<option>` porta
            // `selected` e il browser mostra la **prima**, che è «Nascoste» — mentre il
            // default vero è `Modifica`. La cabina direbbe «questo cliente non vede le
            // garanzie» di un cliente che le vede e le modifica, e chi volesse
            // *imporre* «Nascoste» la troverebbe già selezionata, non toccherebbe nulla,
            // e la clausola non verrebbe mai scritta.
            //
            // *Era stata tolta come «colonna che nessuno legge» quando ancora nessuno la
            // leggeva: il blocco successivo l'ha resa necessaria, e il difetto è passato
            // fra due revisioni.*
            ->get(['id', 'account_id', 'nome', 'visibilita_garanzie_ricambio'])
            ->groupBy('account_id');

        $perSede = VistaPiattaforma::strumenti()
            ->whereIn('tenant_id', $sedi->flatten()->pluck('id'))
            ->selectRaw('tenant_id, count(*) as totale')
            ->groupBy('tenant_id')
            ->pluck('totale', 'tenant_id');

        // La somma per account si fa **in memoria**, su dati già caricati: un
        // secondo raggruppamento in SQL costerebbe una query per lo stesso
        // numero.
        $strumenti = $sedi->map(
            fn (Collection $delCliente) => $delCliente->sum(fn ($sede) => (int) ($perSede[$sede->id] ?? 0))
        )->all();

        // Il dettaglio per sede esce dalla **stessa** query del totale: senza,
        // aprire una riga costerebbe un round-trip, e l'espansione è il gesto
        // che si ripete di più su questa pagina.
        return ['sedi' => $sedi, 'strumenti' => $strumenti, 'perSede' => $perSede->all()];
    }
}

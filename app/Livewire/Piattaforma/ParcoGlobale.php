<?php

namespace App\Livewire\Piattaforma;

use App\Livewire\Piattaforma\Concerns\OffreImpersonazione;
use App\Models\Account;
use App\Models\Garanzia;
use App\Models\Strumento;
use App\Support\Piani;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use App\Support\Piattaforma\RigheParcoStrumenti;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Il parco **macchine** di tutti i clienti, in sola lettura (🔗 ADR-037).
 *
 * È la stessa domanda di `App\Livewire\Strumenti\ElencoStrumenti` — «che
 * macchine ci sono e come stanno» — allargata oltre il proprio Ente. Filtri,
 * paginazione e semaforo riusano le convenzioni di quell'elenco invece di
 * inventarne di nuove; ciò che si aggiunge è il **perimetro**, e ciò che si
 * toglie è ogni azione che scriva.
 *
 * ## ⛔ Da qui si GUARDA. Si scrive impersonando.
 *
 * Nessun bottone di questa pagina modifica alcunché: ogni modifica passa
 * dall'impersonazione, che è **per cliente** e lascia nel registro di audit chi
 * agiva e per conto di chi (🔗 ADR-018, ADR-027). Una scrittura cross-cliente
 * perderebbe quel contesto proprio dove serve di più. Il tasto «Impersona»
 * accanto a ogni riga è quindi la **funzione** della pagina e non un ornamento:
 * è ciò che rende quel confine rapido invece che fastidioso.
 *
 * ⚠️ Il tasto riusa `OffreImpersonazione`, lo stesso trait della cabina: la
 * scelta del membro quando i membri sono più d'uno, il Developer mai
 * impersonabile, «chi sta già impersonando non impersona nessuno» sono decisioni
 * che vivono là e non vanno riscritte qui.
 *
 * ## Di CHI sono i dati: la colonna che non si può togliere
 *
 * 🔴 Cliente e sede stanno **su ogni riga**, ed è il primo requisito posto dal
 * committente. Non è cosmesi: su una vista cross-cliente il rischio non è
 * sbagliare macchina, è sbagliare **cliente** — e chi impersona dalla riga
 * sbagliata entra in casa di qualcun altro. Un test lo congela.
 *
 * I due nomi arrivano da due `leftJoin` e non da un `with()`: `Strumento::tenant`
 * e `Strumento::unita` puntano a `UnitaOrganizzativa`, che porta `TenantScope` —
 * un eager load li risolverebbe **a NULL** per ogni cliente che non è il
 * proprio, cioè per quasi tutta la pagina, e la colonna direbbe «—» senza
 * lamentarsi. La join è `left` e non `inner` di proposito: **non filtra**, e non
 * deve poterlo fare. Il perimetro l'ha già applicato la porta
 * (`whereIn strumenti.tenant_id`), le due `ON` sono su chiave primaria — quindi
 * non duplicano — e una `inner` potrebbe solo far **sparire** righe in silenzio,
 * che su una vista di sorveglianza è il guasto peggiore.
 *
 * ## Il semaforo, e il filtro che NON c'è
 *
 * ⛔ `Strumento::conStato()`, `ordinaPerStato()` e `obsoleti()` sono la forma SQL
 * della regola e compongono sottoquery **scopate per tenant**: su questo elenco
 * classificherebbero come verdi le macchine altrui. Il semaforo si calcola
 * quindi con `Semaforo::calcola()` sulle sole righe in pagina, via
 * `RigheParcoStrumenti` — tre query costanti, la regola resta una sola.
 *
 * ⚠️ **Conseguenza dichiarata**: qui non esiste il filtro «solo arancioni»
 * dell'elenco per-Ente, e non è una dimenticanza. Filtrarlo in PHP dopo la
 * paginazione darebbe pagine incomplete e un totale falso; farlo in SQL
 * vorrebbe dire riscrivere `Strumento::fontiArancione()` non-scopata, cioè una
 * seconda copia della regola del semaforo. Si preferisce un filtro assente a un
 * filtro che mente. La strada per averlo è scritta in `RigheParcoStrumenti`.
 *
 * ## Il costo
 *
 * Query **costanti** al crescere delle righe: la pagina, il suo conteggio, le
 * tre fonti del semaforo sulle sole righe in pagina, gli account della pagina
 * (per l'impersonazione) e la tendina dei clienti. Nessuna sottoquery correlata
 * per riga — l'elenco per-Ente ne ha tre nell'ordinamento per stato e per
 * prossima scadenza, ed è il debito che la roadmap gli imputa: qui quelle due
 * colonne **non sono ordinabili**, così il debito non viene moltiplicato per il
 * numero di clienti. Un test conta le query e le confronta fra una pagina da
 * poche righe e una da molte.
 *
 * 🔗 ADR-005 (semaforo), ADR-004/ADR-020 (le due fonti garanzia), ADR-018
 * (nessun bypass di tenancy), ADR-037 (la porta del parco).
 */
#[Layout('components.layouts.app')]
class ParcoGlobale extends Component
{
    use OffreImpersonazione, WithPagination;

    /**
     * Il permesso è `tenants.view_all`, lo stesso della cabina: significa
     * letteralmente «vedi oltre il tuo Ente», è già del solo Developer/Superadmin
     * ed è nel set bloccato (🔗 ADR-016). Nessun permesso nuovo, nessun riseeding.
     */
    public const PERMESSO = VistaPiattaforma::PERMESSO;

    /**
     * Il perimetro, in tre proprietà che arrivano dal **browser**.
     *
     * ⛔ Non sono input fidati e non vengono usate mai direttamente: passano da
     * `Perimetro::daRichiesta()`, che normalizza la forma, e poi da
     * `ParcoClienti::clienti()`, che interseca gli id con l'insieme legittimo.
     * Un modo che non esiste, un piano fuori catalogo o un id forgiato non
     * allargano: cadono su `nessuno()` o spariscono nell'intersezione.
     */
    #[Url]
    public string $modo = Perimetro::TUTTI;

    /** @var array<int|string> */
    #[Url]
    public array $accountIds = [];

    #[Url]
    public ?string $piano = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $sortBy = 'cliente';

    #[Url]
    public string $sortDir = 'asc';

    /**
     * Righe per pagina. Arriva dalla query string, quindi è input dell'utente e
     * va validato contro `PER_PAGE`: un `?perPage=999999` chiederebbe al DB il
     * parco intero di ogni cliente in una volta sola.
     */
    #[Url]
    public int $perPage = self::PER_PAGE_DEFAULT;

    /** Scelte ammesse: il massimo è 100 righe per pagina. */
    private const PER_PAGE = [20, 50, 100];

    private const PER_PAGE_DEFAULT = 20;

    /**
     * Le colonne ordinabili, e sono **tutte colonne vere**.
     *
     * ⚠️ `stato` e `prossima scadenza` non ci sono: sono derivate, e ordinarle
     * richiederebbe le sottoquery correlate per riga di `ElencoStrumenti`
     * (scopate, quindi sbagliate qui) o una loro copia non-scopata, cioè una
     * seconda regola del semaforo. `cliente` e `sede` sono ordinabili perché
     * la join le rende colonne, non sottoquery.
     */
    private const SORTABLE = ['cliente', 'sede', 'nome', 'modello', 'matricola'];

    private const SORT_DEFAULT = 'cliente';

    /**
     * Chiude ogni modale della pagina.
     *
     * Richiesto da `OffreImpersonazione::apriScelta()`, che lo chiama per
     * mantenere l'invariante «una modale alla volta». Qui la modale è una sola —
     * la scelta del membro — perché da questa pagina non si amministra nulla:
     * la sola azione è entrare in casa di un cliente, e quella passa da un GET.
     */
    public function chiudiOgniModale(): void
    {
        $this->sceltaImpersonazione = null;
    }

    /**
     * Cambiare perimetro cambia l'insieme: restare a pagina 7 atterrerebbe
     * fuori dall'elenco. Vale per ognuna delle tre proprietà, e per la ricerca.
     */
    public function updatingModo(): void
    {
        $this->resetPage();
    }

    public function updatingAccountIds(): void
    {
        $this->resetPage();
    }

    public function updatingPiano(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** Cambiando la dimensione di pagina, la pagina corrente non ha più senso. */
    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function sort(string $col): void
    {
        if (! in_array($col, self::SORTABLE, true)) {
            return;
        }

        // 🔴 Si confrontano i valori **normalizzati**, non le property grezze.
        //
        // `render()` normalizza (una colonna fuori whitelist ricade su
        // `SORT_DEFAULT`, una direzione ignota su `asc`), quindi con un
        // `?sortBy=` forgiato la freccia in intestazione dice una cosa e queste
        // due righe ne leggevano un'altra: il primo clic sulla colonna che la
        // pagina stava GIÀ mostrando ordinata la reimpostava ad `asc`, cioè non
        // faceva nulla. Segnalato dal correttore dei ricambi, che aveva lo
        // stesso difetto sul proprio `ordina()` e questo file non poteva
        // toccarlo.
        $attualeBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : self::SORT_DEFAULT;
        $attualeDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $this->sortBy = $col;
        $this->sortDir = $attualeBy === $col
            ? ($attualeDir === 'asc' ? 'desc' : 'asc')
            : 'asc';

        // Cambiare ordinamento rimescola tutte le righe: restare sulla pagina
        // corrente farebbe atterrare a metà elenco.
        $this->resetPage();
    }

    /**
     * Il perimetro **normalizzato**, unica via per cui le tre property entrano
     * in una query.
     *
     * `daRichiesta()` e non i costruttori nominati: è la forma scritta apposta
     * per ciò che arriva dal browser, e in caso di dubbio torna `nessuno()`
     * invece di `tutti()` — su una vista cross-cliente la differenza fra le due
     * risposte a un input incomprensibile è fra zero righe e le righe di tutti.
     */
    public function perimetro(): Perimetro
    {
        return Perimetro::daRichiesta($this->modo, $this->accountIds, $this->piano);
    }

    /**
     * Almeno un filtro **oltre al perimetro** è attivo.
     *
     * Serve al messaggio di elenco vuoto, e sta qui e non in Blade per la
     * ragione già pagata su `ElencoStrumenti`: una lista di filtri riscritta in
     * vista diverge dalla lista con cui i filtri vengono applicati, e manda a
     * togliere filtri che non ci sono. Il perimetro non conta come filtro: ha
     * un messaggio suo, perché «nessun cliente selezionato» e «nessuna macchina
     * per questa ricerca» sono due fatti diversi.
     */
    public function haFiltriAttivi(): bool
    {
        return filled($this->search);
    }

    /**
     * Il messaggio dell'elenco vuoto, che deve mandare al controllo **che è a
     * schermo**.
     *
     * 🔴 Non basta distinguere «perimetro vuoto» da «nessun risultato»: il
     * perimetro si svuota in tre modi diversi, e i controlli visibili sono
     * diversi in ognuno. Col modo «per piano» e nessun piano scelto il
     * perimetro è vuoto (`daRichiesta()` cade su `nessuno()`, che è
     * `scelti([])`), ma il multi-select dei clienti **non è renderizzato**:
     * dire «scegline almeno uno dal filtro qui sopra» manda a un controllo che
     * non c'è, cioè fa cercare un difetto. Per questo si guarda anche il modo
     * **grezzo**: è quello che decide quali filtri la vista disegna.
     *
     * ⚠️ Il conteggio è dei clienti scelti **veri** — l'intersezione con la
     * tendina — e non della lunghezza dell'array: un id di EasyLab o di un
     * account cestinato è «scelto» per il browser e non è un cliente, quindi
     * la risposta giusta resta «non hai selezionato nessun cliente» e non
     * «questi clienti non hanno macchine».
     */
    private function messaggioElencoVuoto(Perimetro $perimetro, int $clientiScelti): string
    {
        $nessunClienteScelto = $perimetro->modo === Perimetro::SCELTI && $clientiScelti === 0;

        if ($perimetro->eNessuno() || $nessunClienteScelto) {
            return match ($this->modo) {
                Perimetro::PER_PIANO => 'Nessun piano selezionato: scegline uno dal filtro qui sopra.',
                Perimetro::SCELTI => 'Nessun cliente selezionato: scegline almeno uno dal filtro qui sopra.',
                default => 'Perimetro non valido: scegli i clienti dal filtro qui sopra.',
            };
        }

        return $this->haFiltriAttivi()
            ? 'Nessun risultato per i filtri applicati.'
            : 'Nessuna macchina per i clienti nel perimetro.';
    }

    /**
     * Il titolo dice **su chi** si sta guardando.
     *
     * 🔴 Un titolo statico «Strumenti di tutti i clienti» sopra una tabella
     * filtrata su un cliente solo dice il falso, e lo dice proprio sulla
     * scheda il cui primo requisito è sapere di CHI sono le righe. Il rischio
     * di questa pagina non è sbagliare macchina: è impersonare dalla riga del
     * cliente sbagliato, e ogni testo che allarga il perimetro a parole ci
     * lavora contro.
     */
    private function titolo(Perimetro $perimetro, int $clientiScelti): string
    {
        return match (true) {
            $perimetro->modo === Perimetro::TUTTI => 'Strumenti di tutti i clienti',
            $perimetro->modo === Perimetro::PER_PIANO => 'Strumenti dei clienti sul piano '.Piani::etichetta($perimetro->piano),
            $clientiScelti === 1 => 'Strumenti di 1 cliente scelto',
            $clientiScelti > 1 => "Strumenti di {$clientiScelti} clienti scelti",
            default => 'Strumenti dei clienti scelti',
        };
    }

    /** Opzioni del select. @return list<int> */
    public function opzioniPerPage(): array
    {
        return self::PER_PAGE;
    }

    /**
     * I clienti selezionabili nella tendina del perimetro.
     *
     * `selezionabili()` è la stessa `VistaPiattaforma::accounts()` contro cui
     * `ParcoClienti::clienti()` interseca gli id scelti: la tendina offre
     * quindi esattamente l'insieme legittimo, senza EasyLab e senza i
     * cestinati — non per un `where` scritto qui, ma per costruzione.
     *
     * @return Collection<int, Account>
     */
    public function selezionabili(): Collection
    {
        return ParcoClienti::selezionabili()
            ->orderBy('accounts.ragione_sociale')
            ->orderBy('accounts.id')
            ->get(['accounts.id', 'accounts.ragione_sociale', 'accounts.piano']);
    }

    /**
     * La query della pagina: le macchine del perimetro, coi nomi di cliente e
     * sede attaccati.
     *
     * @return Builder<Strumento>
     */
    private function macchine(Perimetro $perimetro): Builder
    {
        $query = ParcoClienti::strumenti($perimetro)
            // ⚠️ `strumenti.*` e non `*`: con due join in mezzo, `*` porterebbe
            // in dote le colonne omonime di `unita_organizzativa` e `accounts`
            // (`id`, `nome`, `created_at`) sovrascrivendo quelle della macchina.
            ->select([
                'strumenti.*',
                'sede.nome as sede_nome',
                'cliente.id as cliente_id',
                'cliente.ragione_sociale as cliente_nome',
            ])
            ->leftJoin('unita_organizzativa as sede', 'sede.id', '=', 'strumenti.tenant_id')
            ->leftJoin('accounts as cliente', 'cliente.id', '=', 'sede.account_id')
            // `forcedBy` è un `User`, che non ha global scope di tenancy: si può
            // caricare cross-cliente e serve al tooltip della pill «forzato».
            ->with('forcedBy');

        if (filled($this->search)) {
            // Colonne QUALIFICATE: con `sede.nome` in gioco, un `nome` nudo è
            // un'ambiguità che Postgres rifiuta e SQLite risolve a caso.
            $like = '%'.strtolower(trim($this->search)).'%';

            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw('LOWER(strumenti.nome) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(strumenti.modello, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(strumenti.matricola, \'\')) LIKE ?', [$like]);
            });
        }

        return $query;
    }

    /**
     * Applica l'ordinamento scelto, **col tie-break sull'id**.
     *
     * 🔴 L'ultima chiave è sempre `strumenti.id`, e non è pignoleria: a parità
     * di chiave l'ordine fra due pagine è una proprietà del motore — SQLite
     * scansiona in modo stabile, Postgres può riordinare i pari fra la query di
     * pagina 1 e quella di pagina 2, e una riga esce da **entrambe**. Qui pesa
     * più che altrove: ordinando per «cliente» i pari sono tutte le macchine di
     * uno stesso cliente, cioè quasi tutta la pagina.
     *
     * @param  Builder<Strumento>  $query
     */
    private function applicaOrdinamento(Builder $query, string $sortBy, string $sortDir): void
    {
        $colonna = match ($sortBy) {
            'cliente' => 'cliente.ragione_sociale',
            'sede' => 'sede.nome',
            default => 'strumenti.'.$sortBy,
        };

        $query->orderBy($colonna, $sortDir)->orderBy('strumenti.id');
    }

    public function render(): View
    {
        $perimetro = $this->perimetro();

        $sortBy = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : self::SORT_DEFAULT;
        $sortDir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $query = $this->macchine($perimetro);
        $this->applicaOrdinamento($query, $sortBy, $sortDir);

        // Ri-validato a ogni render e non solo all'update: il valore può
        // arrivare dalla query string senza passare da `updatingPerPage()`.
        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : self::PER_PAGE_DEFAULT;

        $strumenti = $query->paginate($perPage)->onEachSide(1);

        // Gli account **delle sole righe in pagina**, riletti dalla porta: da
        // qui escono i candidati all'impersonazione. Il `cliente_id` della join
        // basterebbe per il nome, non per un `Account` con i suoi membri.
        $idClienti = $strumenti->getCollection()->pluck('cliente_id')->filter()->unique()->values()->all();

        // ⚠️ Su una pagina vuota **non si interroga**: un `whereIn` su un array
        // vuoto è un giro dal database per sapere una cosa che si sa già.
        $clienti = $idClienti === []
            ? new Collection
            : ParcoClienti::clienti($perimetro)->whereIn('accounts.id', $idClienti)->get();

        $selezionabili = $this->selezionabili();

        // I clienti scelti **veri**: l'intersezione fra gli id arrivati dal
        // browser e la tendina, che è già in memoria — quindi zero query.
        //
        // ⚠️ Non `count($this->accountIds)`: quello è l'array grezzo. Un id
        // ripetuto conterebbe due volte, l'id di EasyLab o di un account
        // cestinato conterebbe uno, e il numero a schermo non corrisponderebbe
        // a nessuna riga della tabella — su una vista di sorveglianza è la
        // stessa forma di bugia del titolo statico.
        $clientiScelti = count(array_intersect($perimetro->accountIds, $selezionabili->pluck('id')->all()));

        return view('livewire.piattaforma.parco-globale', [
            'strumenti' => $strumenti,
            'righe' => RigheParcoStrumenti::perPagina($strumenti->getCollection(), $perimetro),
            'candidatiPerAccount' => $this->candidatiDellaPagina($clienti),
            'selezionabili' => $selezionabili,
            'piani' => Piani::codici(),
            'perimetro' => $perimetro,
            'titolo' => $this->titolo($perimetro, $clientiScelti),
            'clientiScelti' => $clientiScelti,
            'messaggioVuoto' => $this->messaggioElencoVuoto($perimetro, $clientiScelti),
            // ⚠️ **Non** `sortBy`/`sortDir`: Livewire passa alla vista le
            // property pubbliche del componente *e* i dati di `render()`, e in
            // caso di omonimia vince la property — cioè il valore GREZZO
            // arrivato dal browser. La freccia dell'intestazione avrebbe
            // indicato una colonna che la query non ha usato. Nomi diversi, e
            // il conflitto non è più esprimibile.
            'ordinaPer' => $sortBy,
            'ordinaDir' => $sortDir,
            // Il DETTAGLIO della colonna scadenza degrada senza
            // `garanzie.ricambio.view` (🔗 ADR-020); il pallino, che è
            // l'aggregato, non cambia per nessuno.
            'vedeGaranzieRicambio' => Gate::allows('view', Garanzia::class),
        ]);
    }
}

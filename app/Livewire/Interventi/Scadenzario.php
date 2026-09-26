<?php

namespace App\Livewire\Interventi;

use App\Enums\StatoIntervento;
use App\Enums\TipoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Support\Semaforo;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Scadenzario: gli interventi APERTI di tutte le macchine che l'utente vede,
 * in un elenco solo (ERD §5.2 — 🔗 ADR-005/007/011/018/030 — Wireframe §5).
 *
 * Chiude a metà la voce di roadmap `[V1.1] Elenco interventi e spostamenti
 * cross-macchina`: la metà «interventi» esiste da qui, gli **spostamenti**
 * restano senza vista cross-macchina, e la lacuna dichiarata in roadmap si
 * restringe senza chiudersi.
 *
 * ## Cosa mostra, e perché anche lo scaduto
 *
 * «Interventi futuri» è letto come «interventi **aperti**» (`stato =
 * non_fatto`), scaduti compresi. Uno scadenzario che nascondesse lo
 * scaduto-non-fatto direbbe «non c'è niente da fare» proprio sulla macchina che
 * sta accendendo l'arancione (ADR-005), e contraddirebbe `/campo` e il digest,
 * che lo mostrano. Le tre partizioni sono le **stesse due domande** del digest
 * (ADR-011 — `Scaduta` / `Imminente`) più il resto: nessuna quarta soglia
 * inventata.
 *
 * ## Chi vede cosa: nessuna regola nuova
 *
 * Si parte da `Intervento::query()` con **tutti i global scope addosso** e senza
 * un solo `withoutGlobalScope`: `TenantScope` (criterio Tecnico di ADR-007/030
 * incluso), `DepartmentThroughStrumentoScope` per il Responsabile Reparto e il
 * soft delete. Un utente autenticato **senza tenant** vede zero righe e zero su
 * tutti e tre i contatori — il fail-closed di ADR-018 — e non «tutto».
 *
 * ⚠️ **I contatori sono la superficie che si dimentica**: un `count()` fuori
 * dagli scope tornerebbe il numero di un altro cliente senza mostrarne una riga.
 * Qui passano dalla stessa `base()` delle righe, quindi non possono divergere.
 *
 * ## Costo
 *
 * (a) Si parte da `Intervento` e **non** da `Strumento`, quindi non c'è nessuna
 * sottoquery correlata per riga e nessun ordinamento su colonna derivata: il
 * debito OFFSET dichiarato in roadmap (S3, sotto la voce STRETCH) non peggiora.
 * La pagina costa **8 statement costanti** rispetto al numero di righe: 1 count
 * del paginatore + 1 pagina + 2 eager load + 1 `Strumento::mappaUbicazioni()` +
 * 3 count dei contatori. Le due sottoquery su `Strumento` — quella che tiene
 * fuori le macchine cestinate e quella della ricerca per nome — vivono dentro
 * questi statement e non ne aggiungono.
 *
 * L'unica eccezione è il rimbalzo di una pagina oltre l'ultima esistente (vedi
 * `render()`): lì il paginatore gira due volte, ed è il prezzo di non mostrare
 * un elenco vuoto sotto tile che dicono cinque.
 *
 * (b) Per il Responsabile Reparto ogni query scopata passa da
 * `DepartmentThroughStrumentoScope` → `AccessibleNodes::forCurrentUser()`,
 * memoizzata per richiesta da `App\Support\Tenancy\AccessibleNodesMemo` (S7):
 * paga pivot e albero una volta sola (47 statement sulla pagina prima della
 * memo, 13 dopo, `tests/Feature/Performance/QueryPagineTest.php`).
 *
 * ## Postura di autorizzazione
 *
 * La pagina è di **sola lettura**: nessuna azione di scrittura, nessun
 * `skipRender()`, nessuna chiusura di intervento da qui — quella vive nella
 * scheda, che è l'unica catena di autorizzazione per quel gesto (ADR-003).
 * Quindi il `can:interventi.view` sulla rotta più i global scope bastano; chi
 * aggiungerà un'azione dovrà portarsi il proprio `Gate::authorize()`.
 */
#[Layout('components.layouts.app')]
#[Title('Scadenzario — Easy Lab')]
class Scadenzario extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /**
     * Partizione selezionata. Valori ammessi in `STATI`: è input dell'utente
     * (arriva dalla query string), quindi si valida contro la whitelist a ogni
     * `render()` e non solo nell'azione.
     */
    #[Url]
    public ?string $stato = null;

    /** Filtro per tipo di attività (ADR-021): validato con `TipoIntervento::tryFrom()`. */
    #[Url]
    public ?string $tipo = null;

    /**
     * «Solo i miei»: `tecnico_id = auth()->id()`.
     *
     * Una spunta e non un select dell'assegnatario: un select richiederebbe
     * l'elenco degli assegnabili, cioè una seconda copia di
     * `SchedaStrumento::assegnabili()` e una superficie in più su cui sbagliare
     * i nomi cross-tenant (vedi `tecnicoLabel()` qui sotto).
     */
    #[Url]
    public bool $soloMiei = false;

    #[Url]
    public string $sortDir = 'asc';

    /**
     * Righe per pagina. Arriva dalla query string, quindi è input dell'utente e
     * va sempre rivalidato contro `PER_PAGE` — un `?perPage=999999` chiederebbe
     * al DB l'intero elenco.
     */
    #[Url]
    public int $perPage = self::PER_PAGE_DEFAULT;

    /**
     * Le tre partizioni, e sono tre: scaduto, entro la soglia «imminente» di
     * ADR-005, oltre. Nessuna quarta soglia.
     */
    private const STATI = ['scaduti', 'in_scadenza', 'oltre'];

    /** Scelte ammesse: il massimo è 100 righe per pagina. */
    private const PER_PAGE = [20, 50, 100];

    private const PER_PAGE_DEFAULT = 20;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStato(): void
    {
        $this->resetPage();
    }

    public function updatingTipo(): void
    {
        $this->resetPage();
    }

    public function updatingSoloMiei(): void
    {
        $this->resetPage();
    }

    /** Cambiando la dimensione di pagina, la pagina corrente non ha più senso. */
    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * Le tre tile in cima sono cliccabili e indirizzano, come i riquadri della
     * dashboard. `null` («Tutti») è un valore legittimo; qualunque altra cosa
     * ricade su `null` invece di produrre un elenco che non corrisponde a
     * nessuna etichetta.
     */
    public function filtra(?string $stato): void
    {
        $this->stato = in_array($stato, self::STATI, true) ? $stato : null;
        $this->resetPage();
    }

    /**
     * Inverte la direzione dell'ordinamento.
     *
     * `resetPage()` perché invertire l'ordine rimescola tutte le righe: restare
     * sulla pagina 2 farebbe atterrare a metà elenco (stessa ragione di
     * `ElencoStrumenti::sort()`).
     */
    public function invertiOrdine(): void
    {
        // ⛔ Si inverte a partire dalla direzione **effettivamente applicata**,
        // mai dal valore grezzo: `sortDir` arriva dalla query string e può
        // valere qualunque cosa. Con due copie della whitelist — una qui e una
        // in `render()` — un `?sortDir=inventato` faceva rendere 'asc' e al
        // primo clic riscriveva 'asc': l'elenco non si muoveva e la freccia non
        // cambiava. È la stessa ragione per cui `RegistroAudit::inverti()` parte
        // da `direzione()` e `ElencaClienti` da `ordinamentoEffettivo()`.
        $this->sortDir = $this->direzione() === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    /**
     * La direzione **effettivamente applicata**, e l'unico posto in cui la
     * whitelist di `sortDir` è scritta.
     *
     * La legge `render()` per ordinare, la legge `invertiOrdine()` per
     * invertire e la vista la riceve già normalizzata per disegnare la freccia:
     * una freccia che indica il contrario dell'ordine applicato è una bugia
     * piccola, e per questo credibile.
     *
     * `mb_strtolower()` perché un `?sortDir=DESC` scritto a mano — o incollato
     * da un altro sistema — è la stessa direzione, non un valore da scartare.
     *
     * `private`: la vista riceve il valore già normalizzato da `render()` e non
     * ha bisogno di chiamarlo, quindi non c'è ragione di aggiungere un metodo
     * invocabile dal browser — a differenza di `haFiltriAttivi()` e
     * `opzioniPerPage()`, che il Blade chiama davvero.
     */
    private function direzione(): string
    {
        return mb_strtolower(trim($this->sortDir)) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Almeno un filtro è attivo, cioè l'elenco è un SOTTOINSIEME degli aperti.
     *
     * 🔴 Esiste come metodo perché **la vista non deve riscrivere questa lista**:
     * è la lezione già scritta nel docblock di `ElencoStrumenti::haFiltriAttivi()`,
     * dove la condizione viveva in Blade, nominava due filtri su cinque e
     * mandava a togliere filtri che il componente aveva già scartato.
     *
     * ⚠️ Ogni ramo usa **lo stesso predicato con cui il filtro è applicato** in
     * `base()`/`render()`: la whitelist dove là c'è la whitelist, `tryFrom()`
     * dove là c'è `tryFrom()`. Un `?tipo=inventato` non è un filtro applicato,
     * quindi non è un filtro da annunciare.
     */
    public function haFiltriAttivi(): bool
    {
        return filled($this->search)
            || $this->tipoValido() !== null
            || $this->soloMiei
            || in_array($this->stato, self::STATI, true);
    }

    /** Opzioni del select, esposte alla view. @return list<int> */
    public function opzioniPerPage(): array
    {
        return self::PER_PAGE;
    }

    /**
     * Il termine di ricerca reso innocuo per un `LIKE`.
     *
     * ⛔ **I jolly si neutralizzano**: senza, `%` da solo restituisce ogni riga
     * mentre `haFiltriAttivi()` dice `true` — cioè un elenco non filtrato che si
     * annuncia filtrato, e un filtro che si aggira digitando un carattere — e
     * `_` diventa «un carattere qualunque», per cui «FILTRO_HEPA» trova anche
     * «FILTROXHEPA». Stessa forma, e per la stessa ragione, di
     * `RegistroAudit::jolly()` e di `ElencaClienti`.
     *
     * ⚠️ **`mb_strtolower()` e non `strtolower()`**, che è byte-wise: cercando
     * «SANITÀ» quest'ultimo lascia intatti i due byte di «À» e produce
     * `%sanitÀ%`, mentre su Postgres `LOWER(strumenti.nome)` è UTF-8-aware e
     * produce `sanità` — la macchina non si troverebbe. **In locale il difetto
     * non si vede**: il `LOWER()` di SQLite converte solo A–Z, quindi ago e
     * pagliaio restano d'accordo. È la direzione peggiore della divergenza
     * documentata in `RegistroAudit::jolly()`, perché un test sui dati
     * nascerebbe verde sulla macchina di sviluppo: la rete è perciò
     * un'asserzione sul **binding**, non sulle righe.
     */
    private function jolly(string $termine): string
    {
        return addcslashes(mb_strtolower(trim($termine)), '%_\\');
    }

    /** Il tipo selezionato se è un valore dell'enum, altrimenti nulla (ADR-021). */
    private function tipoValido(): ?TipoIntervento
    {
        return $this->tipo === null ? null : TipoIntervento::tryFrom($this->tipo);
    }

    /**
     * Gli interventi aperti visibili, con search/tipo/soloMiei applicati ma
     * **senza** la partizione: serve identica alle righe e ai tre contatori, che
     * altrimenti direbbero numeri diversi da ciò che l'elenco mostra.
     *
     * ⚠️ **Colonne sempre qualificate.** Quando l'utente è un Tecnico,
     * `AccessoTecnico::strumentiAssegnati()` innesta una sottoquery sulla
     * STESSA tabella `interventi` (con alias `interventi_assegnati` proprio per
     * questo): una colonna nuda diventerebbe ambigua su Postgres e non su
     * SQLite. Stessa disciplina di `NotificaScadenze`.
     *
     * @return Builder<Intervento>
     */
    private function base(): Builder
    {
        $query = Intervento::query()
            ->where('interventi.stato', StatoIntervento::NonFatto->value)
            // ⛔ **Solo gli interventi di una macchina ancora leggibile.**
            // `SchedaStrumento::delete()` cestina lo strumento e NON tocca gli
            // interventi — nessun hook `deleting`, nessun observer — e nessuno
            // scope di `Intervento` li nasconde: il `TenantScope` guarda
            // `interventi.tenant_id`, il soft delete `interventi.deleted_at`, e
            // `DepartmentThroughStrumentoScope` passa da
            // `AccessibleStrumenti::nei()`, che dichiara nel proprio docblock di
            // ignorare il cestino apposta. Su una **scheda** quella scelta è
            // giusta: lo storico di una macchina cestinata resta leggibile a chi
            // la riapre. Qui no: questo è un elenco di cose **da fare**, e una
            // riga così non si può chiudere da nessuna parte (la chiusura vive
            // nella scheda, che risponde 404 perché il binding implicito applica
            // il soft delete), non si trova con la ricerca per nome macchina —
            // quella sottoquery passa già da `Strumento::query()` — e gonfia per
            // sempre un contatore. È la stessa decisione già presa dal digest,
            // che salta le righe la cui macchina non è più leggibile
            // (`NotificaScadenze::strumentiDi()`).
            //
            // Sottoquery e non `whereHas`: costa zero statement in più, e
            // riapplica gli scope di `Strumento` — difesa in profondità, con la
            // stessa forma del ramo di ricerca qui sotto.
            ->whereIn('interventi.strumento_id', Strumento::query()->select('strumenti.id'));

        if (filled($this->search)) {
            $like = '%'.$this->jolly($this->search).'%';

            // La ricerca sul nome macchina passa da una **sottoquery** su
            // `Strumento` e non da una join: così riapplica gli scope di quel
            // modello (difesa in profondità) e non introduce colonne di una
            // seconda tabella nell'outer query.
            //
            // `ESCAPE` dichiarato su ENTRAMBI i rami, perché SQLite — a
            // differenza di Postgres — non ha un carattere di escape di
            // default: senza, il backslash che `jolly()` antepone resterebbe un
            // backslash qualunque e la neutralizzazione sarebbe fatta a metà.
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw("LOWER(interventi.descrizione) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereIn(
                        'interventi.strumento_id',
                        Strumento::query()->select('strumenti.id')
                            ->whereRaw("LOWER(strumenti.nome) LIKE ? ESCAPE '\\'", [$like])
                    );
            });
        }

        if (($tipo = $this->tipoValido()) !== null) {
            $query->where('interventi.tipo', $tipo->value);
        }

        if ($this->soloMiei) {
            $query->where('interventi.tecnico_id', auth()->id());
        }

        return $query;
    }

    /**
     * Applica una delle tre partizioni, o nessuna.
     *
     * 🔴 **Nessuna delle tre regole è riscritta qui.** «Scaduto-non-fatto» ha
     * una sola definizione — `Intervento::scopeScadute()`, gemello SQL di
     * `isScaduto()`, che un test tiene allineati — e il ramo la chiama invece di
     * ripeterla (che `scadute()` ribadisca `stato = non_fatto` è innocuo:
     * `base()` lo ha già imposto). Il confine «imminente» arriva da
     * `scopeApertiEntroSoglia()`, cioè da `Semaforo::giorniImminente()`, mai da
     * un 30 scritto a mano.
     *
     * ⚠️ `$oggi` è calcolato **una volta** e vale per tutto il metodo: `scadute()`
     * taglia a `< today()` e `in_scadenza` riprende da `>= $oggi`, quindi le due
     * metà non possono divergere di un giorno e un intervento che scade OGGI sta
     * fra i non scaduti — che è la regola di `isScaduto()`. È lo stesso taglio,
     * scritto nella stessa forma, di `NotificaScadenze::righeDaAvvisare()`, dove
     * è dichiarato «distinzione sua e non del semaforo».
     *
     * ⛔ Il confine superiore si esprime SEMPRE come `>= oggi+soglia+1` e mai
     * come `> oggi+soglia`: su SQLite le colonne `date` sono stringhe
     * `'Y-m-d 00:00:00'` e il confronto è lessicografico, quindi
     * `'2026-08-31 00:00:00' > '2026-08-31'` è vero mentre su Postgres è falso.
     * Con il giorno successivo i due driver dicono la stessa cosa. Le date si
     * passano come stringa (`->toDateString()`), mai come Carbon.
     *
     * @param  Builder<Intervento>  $query
     * @return Builder<Intervento>
     */
    private function partiziona(Builder $query, ?string $stato): Builder
    {
        $oggi = today()->toDateString();

        return match ($stato) {
            'scaduti' => $query->scadute(),
            'in_scadenza' => $query->apertiEntroSoglia()->where('interventi.data_scadenza', '>=', $oggi),
            'oltre' => $query->where(
                'interventi.data_scadenza',
                '>=',
                today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()
            ),
            default => $query,
        };
    }

    public function render()
    {
        // Rivalidati a ogni render, non solo negli hook: i valori possono
        // arrivare dalla query string senza passare da `updating*`.
        $direzione = $this->direzione();
        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : self::PER_PAGE_DEFAULT;
        $stato = in_array($this->stato, self::STATI, true) ? $this->stato : null;

        $pagina = fn () => $this->partiziona($this->base(), $stato)
            ->with(['strumento', 'tecnico'])
            // ⛔ **Il tie-break sull'id è l'ULTIMO criterio, sempre, in entrambe
            // le direzioni.** `data_scadenza` ha pochissimi valori distinti su
            // un parco vero — i pari sono quasi tutte le righe, cioè il caso
            // peggiore — e a parità di chiave l'ordine fra due pagine è una
            // **proprietà del motore**: SQLite scansiona in modo stabile,
            // Postgres può riordinare i pari fra la query di pagina 1 e quella
            // di pagina 2, e una riga esce da entrambe. Già pagato il 25 Ago
            // 2026 su `ElencoStrumenti`: 46 righe raccolte su 47, con SQLite
            // verde. Il test lo verifica **sull'SQL**, non sui dati.
            ->orderBy('interventi.data_scadenza', $direzione)
            ->orderBy('interventi.id')
            ->paginate($perPage)
            ->onEachSide(1);

        $interventi = $pagina();

        // ⚠️ `page` sta nella query string e nessuno lo riconduce da sé: una
        // pagina oltre l'ultima esistente torna ZERO righe mentre le tile e
        // `total()` restano corretti, e la tabella direbbe «Nessun intervento
        // aperto.» — il messaggio scritto apposta per non mandare a cercare
        // righe che non ci sono — davanti a un elenco che ne ha cinque una
        // pagina più indietro. Succede da sé: si condivide `?page=2`, un collega
        // chiude venticinque interventi, e chi riapre il link vede il vuoto.
        //
        // Si rimbalza sull'**ultima pagina esistente** e non sulla prima: è
        // quella più vicina a dove si stava guardando. Solo con righe da
        // mostrare: su un elenco davvero vuoto «pagina 3» è vuota per il motivo
        // giusto, e una seconda coppia di query direbbe la stessa cosa.
        if ($interventi->total() > 0 && $interventi->currentPage() > $interventi->lastPage()) {
            $this->setPage($interventi->lastPage());
            $interventi = $pagina();
        }

        // I contatori portano search/tipo/soloMiei ma **non** la partizione
        // selezionata: applicandola anche a loro, due tile su tre direbbero zero
        // appena se ne clicca una, e non si potrebbe più passare dall'una
        // all'altra leggendo i numeri.
        $contatori = [
            'scaduti' => $this->partiziona($this->base(), 'scaduti')->count(),
            'in_scadenza' => $this->partiziona($this->base(), 'in_scadenza')->count(),
            'oltre' => $this->partiziona($this->base(), 'oltre')->count(),
        ];

        return view('livewire.interventi.scadenzario', [
            'interventi' => $interventi,
            'contatori' => $contatori,
            // UNA query per tutte le ubicazioni delle righe in pagina, come in
            // `Campo\Home`: la risalita ne costava due per riga.
            'nodi' => Strumento::mappaUbicazioni($interventi->getCollection()->pluck('strumento')->filter()),
            // ⛔ **Il nome è `direzione` e non `sortDir`**: Livewire condivide con
            // la vista le proprietà pubbliche del componente, e lo fa DOPO i dati
            // passati a `view()`. Una chiave omonima di una property viene quindi
            // **sovrascritta dal valore grezzo**, e la freccia disegnerebbe
            // `?sortDir=inventato` — cioè indicherebbe il contrario dell'ordine
            // applicato, che è una bugia piccola e per questo credibile. Stessa
            // ragione, stesso nome, in `RegistroAudit`.
            'direzione' => $direzione,
            'statoAttivo' => $stato,
            // La soglia si mostra («entro N giorni») e non si riscrive: viene da
            // `Semaforo`, cioè dallo stesso posto da cui la legge la query.
            'soglia' => Semaforo::giorniImminente(),
            'tipi' => TipoIntervento::cases(),
        ]);
    }
}

<?php

namespace App\Livewire\Anagrafica;

use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Enums\VisibilitaGaranzieRicambio;
use App\Livewire\Concerns\ManagesStrumentoForm;
use App\Models\Account;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Notifiche\AvvisiObsolescenza;
use App\Support\Piani;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Tenancy\AccessoTecnico;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lab404\Impersonate\Services\ImpersonateManager;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Anagrafica gerarchica con navigazione drill-down (S2 punto 4/5).
 * Si entra in un nodo alla volta: breadcrumb in alto, card dei sotto-nodi e
 * lista strumenti del nodo corrente. CRUD nodi + create strumento in modale.
 * Isolamento e sotto-albero garantiti dai global scope (findOrFail fuori
 * scope → 404).
 */
#[Layout('components.layouts.app')]
class Albero extends Component
{
    use ManagesStrumentoForm;

    /** Nodo in cui siamo "dentro" (null = livello radice: elenco Enti/root). */
    public ?int $currentId = null;

    // Modale form nodo
    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $parentId = null;

    public string $tipo = '';

    public string $nome = '';

    public ?string $note = null;

    /** Soglia obsolescenza in anni: campo del solo nodo Ente (ADR-014). */
    public ?int $sogliaObsolescenzaAnni = null;

    /**
     * Visibilità delle garanzie ricambio per il Tenant di questo Ente (ADR-029).
     * Solo il Superadmin la vede e la scrive: è una clausola del rapporto
     * commerciale, non una preferenza interna dell'Ente.
     */
    public ?string $visibilitaGaranzieRicambio = null;

    // Conferma eliminazione nodo
    public ?int $deletingId = null;

    // Modale create strumento
    public bool $showStrumentoForm = false;

    // Provenienza esterna opzionale (ente off-platform) alla creazione.
    public ?string $provenienza = null;

    public ?string $notice = null;

    /** Modale «aggiungi una sede», il gesto del cliente su sé stesso. */
    public bool $showSedeForm = false;

    public string $nomeSede = '';

    public function mount(): void
    {
        // Se l'utente ha un'unica radice visibile (es. Admin con un Ente,
        // Responsabile con un reparto), entra subito senza far cliccare.
        $roots = $this->visibleRoots();
        if ($roots->count() === 1) {
            $this->currentId = $roots->first()->id;
        }
    }

    protected function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'tipo' => ['required', Rule::in([
                TipoUnitaOrganizzativa::Dipartimento->value,
                TipoUnitaOrganizzativa::Sottolaboratorio->value,
            ])],
        ];
    }

    /** Nodi visibili (già scopati a tenant + eventuale sotto-albero). */
    protected function visibleNodes(): Collection
    {
        return UnitaOrganizzativa::orderBy('nome')->get();
    }

    /** Radici di visualizzazione: nodi senza padre visibile. */
    protected function visibleRoots(): Collection
    {
        $nodes = $this->visibleNodes();
        $ids = $nodes->pluck('id')->all();

        return $nodes->filter(
            fn (UnitaOrganizzativa $n) => $n->parent_id === null || ! in_array($n->parent_id, $ids, true)
        )->values();
    }

    // --- Aggiungere una sede al proprio account (ADR-032) ---

    /**
     * 🔴 L'Account di chi guarda, se ne ha uno.
     *
     * Si deriva dall'Ente dell'utente e **mai** da un id che arriva dal
     * browser: è ciò che rende impossibile, per costruzione, aggiungere una
     * sede al contratto di qualcun altro.
     */
    private function mioAccount(): ?Account
    {
        return Auth::user()?->ente?->account;
    }

    /**
     * 🔴 **Nessun permesso nuovo, e la scelta è la parte importante.**
     *
     * Aggiungere una sede **consuma uno slot del piano**, cioè tocca il
     * contratto: la domanda giusta non è «sai amministrare l'anagrafica» ma
     * «questo account è tuo». È esattamente l'ability `manage` di
     * `AccountPolicy`, quella che la cabina già pretende quando aggancia una
     * sede a un cliente esistente — qui il cliente è sé stesso.
     *
     * ⛔ `tenants.provision` NON si allarga all'Admin, e non è pigrizia: è nel
     * **set bloccato** (🔗 ADR-016) e per definizione è cross-tenant, quindi
     * darglielo significherebbe dargli la piattaforma. E un permesso nuovo
     * costerebbe un riseeding, che cancella le personalizzazioni di runtime.
     */
    public function puoAggiungereSede(): bool
    {
        $account = $this->mioAccount();

        return $account !== null
            && Gate::allows('manage', $account)
            && $account->puoAggiungereEnte();
    }

    /**
     * Il tetto è pieno: la pagina lo dice invece di nascondere il bottone e
     * lasciare che il cliente si chieda perché.
     */
    public function tettoPieno(): bool
    {
        $account = $this->mioAccount();

        return $account !== null
            && Gate::allows('manage', $account)
            && ! $account->puoAggiungereEnte();
    }

    public function slotResidui(): ?int
    {
        return $this->mioAccount()?->slotEntiResidui();
    }

    /**
     * Le altre sedi dello stesso contratto, per nome.
     *
     * 🔴 Esiste perché senza di essa una sede appena creata **non si vede da
     * nessuna parte**: l'albero è scopato al proprio Ente (ADR-018), e le altre
     * sedi vivono solo dentro la tendina dello switcher — che durante
     * un'impersonazione è soppressa. Segnalato da Marco il 28 Ago 2026, subito
     * dopo aver creato la prima sede.
     *
     * ⚠️ Sono NOMI e non link: raggiungerle è un gesto dello switcher, con le
     * sue guardie. Qui si risponde alla domanda «esiste?», non «portami».
     *
     * @return Collection<int,string>
     */
    public function altreSediDelContratto(): Collection
    {
        $account = $this->mioAccount();

        if ($account === null || ! Gate::allows('manage', $account)) {
            return collect();
        }

        return $account->enti()
            // ⚠️ `CurrentTenant::id()` e non `tenant_id`: durante
            // un'impersonazione lo spostamento fra sedi è effimero e la colonna
            // resta ferma sulla sede di partenza. Leggendola, questa riga
            // elencava fra le «altre» proprio la sede che si sta guardando.
            ->where('id', '!=', CurrentTenant::id())
            ->orderBy('nome')
            ->pluck('nome');
    }

    /**
     * I numeri del tetto, per dirlo invece di limitarsi a negare.
     *
     * ⚠️ `puoVedereAbbonamento` è una domanda a parte da `tettoPieno()`: la
     * pagina dell'abbonamento vuole `manage` sull'account **e** non deve essere
     * offerta durante un'impersonazione, dove il portale di fatturazione è
     * chiuso apposta (aprirebbe la sessione sul customer di un altro).
     *
     * @return array{piano: string, max: int|null, puoVedereAbbonamento: bool}
     */
    public function tettoDelPiano(): array
    {
        $account = $this->mioAccount();

        return [
            'piano' => $account === null ? '—' : Piani::etichetta($account->piano),
            'max' => $account === null ? null : Piani::maxEnti($account->piano),
            'puoVedereAbbonamento' => $account !== null
                && Gate::allows('manage', $account)
                && ! app(ImpersonateManager::class)->isImpersonating(),
        ];
    }

    public function apriNuovaSede(): void
    {
        abort_unless($this->puoAggiungereSede(), 403);

        $this->resetForm();
        $this->nomeSede = '';
        $this->resetValidation();
        $this->showSedeForm = true;
    }

    public function chiudiNuovaSede(): void
    {
        $this->showSedeForm = false;
        $this->nomeSede = '';
        $this->resetValidation();
    }

    /**
     * ⛔ Ricontrolla TUTTO nell'azione, e non solo all'apertura: le property di
     * un componente Livewire sono pubbliche e `set` + `call` è a un `$wire` di
     * distanza. Il tetto in particolare va riletto qui — fra l'apertura della
     * modale e il salvataggio una sede può essere nata da un'altra scheda.
     */
    public function creaSede(): void
    {
        $account = $this->mioAccount();

        abort_if($account === null, 403);
        Gate::authorize('manage', $account);
        abort_unless($account->puoAggiungereEnte(), 403);

        $this->validate([
            'nomeSede' => ['required', 'string', 'max:255'],
        ]);

        $utente = Auth::user();

        // Si riusa `ProvisionaEnte`, la stessa classe del comando di console e
        // della cabina: la transazione «Ente + aggancio all'account + membro»
        // esiste già, e riscriverla qui vorrebbe dire tenerne due allineate.
        //
        // ⚠️ L'email è quella di chi sta chiedendo: l'utente esiste già,
        // quindi non ne nasce uno nuovo — resta sul proprio Ente e raggiunge
        // quello appena creato con lo switcher in testata.
        $esito = new ProvisionaEnte(
            nome: $this->nomeSede,
            adminEmail: $utente->email,
            adminName: $utente->name,
            accountId: $account->id,
        );

        $esito->esegui();

        $this->showSedeForm = false;
        $this->nomeSede = '';

        // ⛔ Un solo messaggio, e parla al CLIENTE. La prima stesura ne aveva
        // una seconda versione per chi impersona: è l'area del cliente, e non
        // ci si stampano avvisi di servizio per l'operatore (Marco, 28 Ago
        // 2026). Era anche diventata falsa — lo switcher funziona durante
        // l'impersonazione.
        $this->notice = 'Sede creata. La raggiungi dallo switcher in alto, accanto al nome dell\'Ente.';
    }

    // --- Navigazione ---

    public function open(int $id): void
    {
        $this->currentId = UnitaOrganizzativa::findOrFail($id)->id;
    }

    public function goTo(?int $id): void
    {
        $this->currentId = $id !== null ? UnitaOrganizzativa::findOrFail($id)->id : null;
    }

    // --- CRUD nodi ---

    public function addChild(int $parentId): void
    {
        $this->authorize('unita_organizzativa.create');
        $parent = UnitaOrganizzativa::findOrFail($parentId);

        $this->resetForm();
        $this->parentId = $parent->id;
        $this->tipo = $parent->tipo === TipoUnitaOrganizzativa::Ente
            ? TipoUnitaOrganizzativa::Dipartimento->value
            : TipoUnitaOrganizzativa::Sottolaboratorio->value;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('unita_organizzativa.update');
        $node = UnitaOrganizzativa::findOrFail($id);
        $this->rifiutaEnteAltrui($node);

        $this->resetForm();
        $this->editingId = $node->id;
        $this->parentId = $node->parent_id;
        $this->tipo = $node->tipo->value;
        $this->nome = $node->nome;
        $this->note = $node->note;
        $this->sogliaObsolescenzaAnni = $node->tipo === TipoUnitaOrganizzativa::Ente
            ? $node->soglia_obsolescenza_anni
            : null;
        $this->visibilitaGaranzieRicambio = $node->tipo === TipoUnitaOrganizzativa::Ente
            ? $node->visibilita_garanzie_ricambio->value
            : null;
        $this->showForm = true;
    }

    public function save(): void
    {
        if ($this->editingId !== null) {
            // In modifica si cambiano nome/note e, sul solo nodo Ente, la soglia
            // di obsolescenza (il tipo non si tocca: validarlo escluderebbe
            // l'Ente).
            $this->authorize('unita_organizzativa.update');

            // Il nodo si rilegge PRIMA di validare: se è un Ente lo decide il
            // DB, non lo stato del client — le proprietà Livewire arrivano dal
            // browser e sono manipolabili.
            $node = UnitaOrganizzativa::findOrFail($this->editingId);
            $this->rifiutaEnteAltrui($node);
            $isEnte = $node->tipo === TipoUnitaOrganizzativa::Ente;

            $regole = [
                'nome' => ['required', 'string', 'max:255'],
                'note' => ['nullable', 'string', 'max:1000'],
            ];
            if ($isEnte) {
                $regole['sogliaObsolescenzaAnni'] = ['required', 'integer', 'between:1,50'];
            }

            // La regola si aggiunge solo a chi il campo lo vede davvero: per
            // tutti gli altri la property resta quella caricata da `edit()` e
            // non viene mai riletta, quindi un valore forgiato dal browser non
            // ha dove attaccarsi (ed è comunque `fissa…()` a decidere).
            if ($isEnte && $this->puoGestireVisibilitaGaranzie()) {
                $regole['visibilitaGaranzieRicambio'] = ['required', Rule::enum(VisibilitaGaranzieRicambio::class)];
            }

            $validated = $this->validate($regole);

            $payload = ['nome' => $validated['nome'], 'note' => $validated['note']];
            if ($isEnte) {
                $payload['soglia_obsolescenza_anni'] = $validated['sogliaObsolescenzaAnni'];
            }

            $node->update($payload);

            // ADR-029. Fuori dal payload di `update()` di proposito: la colonna
            // è fuori da `$fillable` e si scrive solo dal metodo di dominio, che
            // è anche il punto in cui il controllo del ruolo si esercita. Il
            // permesso `unita_organizzativa.update` — che basta per nome, note e
            // soglia — qui NON basta: ce l'ha anche l'Admin dell'Ente, e questa
            // è una clausola che solo EasyLab decide.
            if ($isEnte && $this->puoGestireVisibilitaGaranzie()) {
                $node->fissaVisibilitaGaranzieRicambio(
                    VisibilitaGaranzieRicambio::from($validated['visibilitaGaranzieRicambio'])
                );
            }

            // 🔔 ADR-014: abbassare la soglia fa attraversare la linea dell'età
            // a delle macchine, e l'avviso parte **subito** invece di aspettare
            // il giro delle 06:15. Il comando notturno resta la garanzia — se la
            // soglia cambiasse da un altro punto (un tinker, una futura
            // schermata di piattaforma) l'invariante si ricompone comunque entro
            // ventiquattr'ore: questo è un acceleratore, non l'unica strada.
            //
            // `wasChanged` e NON `isDirty`: dopo `update()` gli attributi non
            // sono più dirty, quindi `isDirty` sarebbe sempre falso — una
            // guardia che sembra proteggere e in realtà spegne la funzione.
            //
            // Sincrono e non in coda, per `PermessiInCodaGuardrailTest`: la
            // scelta dei destinatari legge ruoli, e nel worker la cache dei
            // permessi può essere quella di un altro processo. Le email partono
            // comunque in coda, perché la Notification è `ShouldQueue`.
            //
            // ⚠️ E qui i global scope sono ATTIVI, al contrario di quando lo
            // stesso servizio gira da console: chi salva potrebbe essere
            // department-scoped e non vedere metà del parco. `AvvisiObsolescenza`
            // è scritto per reggere entrambi i contesti — il suo reset cancella
            // solo le righe di macchine che ha positivamente letto — e chi lo
            // modifica deve saperlo prima di accorciarlo.
            if ($isEnte && $node->wasChanged('soglia_obsolescenza_anni')) {
                AvvisiObsolescenza::perEnte($node);
            }
        } else {
            $this->authorize('unita_organizzativa.create');

            $validated = $this->validate();

            $parent = UnitaOrganizzativa::findOrFail($this->parentId);
            UnitaOrganizzativa::create([
                'tenant_id' => $parent->tenant_id,
                'parent_id' => $parent->id,
                'tipo' => $validated['tipo'],
                'nome' => $validated['nome'],
                'note' => $validated['note'],
            ]);
        }

        $this->closeForm();
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('unita_organizzativa.delete');
        $this->deletingId = UnitaOrganizzativa::findOrFail($id)->id;
    }

    public function delete(): void
    {
        $this->authorize('unita_organizzativa.delete');
        $node = UnitaOrganizzativa::findOrFail($this->deletingId);

        if ($node->tipo === TipoUnitaOrganizzativa::Ente) {
            $this->notice = 'L\'Ente non può essere eliminato.';
            $this->deletingId = null;

            return;
        }

        if ($node->children()->exists()) {
            $this->notice = 'Elimina prima le unità interne.';
            $this->deletingId = null;

            return;
        }

        if (Strumento::where('unita_organizzativa_id', $node->id)->exists()) {
            $this->notice = 'Sposta o elimina prima gli strumenti.';
            $this->deletingId = null;

            return;
        }

        $parentId = $node->parent_id;
        $node->delete();

        // Se stavamo guardando il nodo eliminato, risali al padre.
        if ($this->currentId === $node->id) {
            $this->currentId = $parentId;
        }
        $this->deletingId = null;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * Il nodo **Ente** non lo modifica chi lavora per portafoglio (ADR-046).
     *
     * Il Gestore ha `unita_organizzativa.update` per rinominare reparti e
     * sotto-laboratori dei clienti che segue. Il nodo Ente è un'altra cosa: il
     * suo nome è la sede del cliente, e la soglia di obsolescenza che porta con
     * sé fa partire avvisi a tutti i suoi utenti. Resta a chi di quell'Ente fa
     * parte, e al Superadmin.
     *
     * Per ruolo e non per permesso, perché il permesso è lo stesso: è il
     * *nodo* a fare la differenza, e la matrice non lo sa.
     */
    public function puoModificareEnte(): bool
    {
        return ! AccessoTecnico::siApplica();
    }

    private function rifiutaEnteAltrui(UnitaOrganizzativa $node): void
    {
        abort_if($node->tipo === TipoUnitaOrganizzativa::Ente && ! $this->puoModificareEnte(), 403);
    }

    /**
     * Chi può toccare la visibilità delle garanzie ricambio (ADR-029): il solo
     * Superadmin — e il Developer, che nel progetto vede e può tutto.
     *
     * ⚠️ **Non è `unita_organizzativa.update`**, che pure governa questo stesso
     * form: quel permesso ce l'ha l'Admin dell'Ente, e con `teams = false` i
     * ruoli sono globali, quindi un Admin tenant-bound finirebbe per decidere
     * una clausola del proprio contratto. È esattamente il caso in cui un
     * permesso «giusto per la schermata» sarebbe sbagliato per il campo.
     *
     * Si appoggia a `roles.manage`, che Schema Ruoli §7 assegna a
     * Developer/Superadmin ed è esso stesso bloccato per evitare auto-delega:
     * chi governa la matrice dei permessi è chi governa anche questa clausola.
     * Meglio di un `hasRole('Superadmin')`, che nominerebbe un secondo ruolo nel
     * codice — nel progetto ne esiste UNO solo, ed è nella Policy.
     */
    public function puoGestireVisibilitaGaranzie(): bool
    {
        return Gate::allows('roles.manage');
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'parentId', 'tipo', 'nome', 'note', 'sogliaObsolescenzaAnni', 'visibilitaGaranzieRicambio']);
        $this->resetValidation();
    }

    // --- Strumenti del nodo corrente ---

    public function addStrumento(): void
    {
        $this->authorize('strumenti.create');

        $node = UnitaOrganizzativa::findOrFail($this->currentId);
        if ($node->tipo === TipoUnitaOrganizzativa::Ente) {
            $this->notice = 'Aggiungi gli strumenti a un dipartimento o sotto-laboratorio, non all\'Ente.';

            return;
        }

        $this->provenienza = null;
        $this->resetStrumentoForm();
        $this->showStrumentoForm = true;
    }

    public function saveStrumento(): void
    {
        $this->authorize('strumenti.create');

        $node = UnitaOrganizzativa::findOrFail($this->currentId);
        abort_if($node->tipo === TipoUnitaOrganizzativa::Ente, 422);

        $this->validate(
            [
                ...$this->strumentoFormRules($node->tenant_id),
                'provenienza' => ['nullable', 'string', 'max:255'],
            ],
            attributes: [
                ...$this->strumentoFormAttributi(),
                'provenienza' => 'provenienza',
            ],
        );

        DB::transaction(function () use ($node) {
            $strumento = Strumento::create([
                'tenant_id' => $node->tenant_id,
                'unita_organizzativa_id' => $node->id,
                ...$this->strumentoPayload(),
            ]);

            // Provenienza esterna → registra un movimento di ingresso (ADR-015).
            if (filled($this->provenienza)) {
                SpostamentoStrumento::create([
                    'tenant_id' => $node->tenant_id,
                    'strumento_id' => $strumento->id,
                    'da_esterno' => trim($this->provenienza),
                    'a_nodo_id' => $node->id,
                    'tipo_spostamento' => TipoSpostamento::Ingresso,
                    'data' => now()->toDateString(),
                    'eseguito_da' => auth()->id(),
                ]);
            }
        });

        $this->closeStrumentoForm();
    }

    public function closeStrumentoForm(): void
    {
        $this->provenienza = null;
        $this->resetStrumentoForm();
        $this->showStrumentoForm = false;
    }

    public function render()
    {
        $nodes = $this->visibleNodes();
        $byId = $nodes->keyBy('id');
        $ids = $nodes->pluck('id')->all();
        $childrenByParent = $nodes->groupBy('parent_id');

        $roots = $nodes->filter(
            fn (UnitaOrganizzativa $n) => $n->parent_id === null || ! in_array($n->parent_id, $ids, true)
        )->values();

        $current = $this->currentId ? $byId->get($this->currentId) : null;

        // Breadcrumb: dalla radice visibile fino al nodo corrente.
        $breadcrumb = collect();
        $walk = $current;
        while ($walk !== null) {
            $breadcrumb->prepend($walk);
            $walk = ($walk->parent_id !== null && in_array($walk->parent_id, $ids, true))
                ? $byId->get($walk->parent_id)
                : null;
        }

        $children = $current
            ? $childrenByParent->get($current->id, collect())->values()
            : $roots;

        $strumenti = ($current && $current->tipo !== TipoUnitaOrganizzativa::Ente)
            ? Strumento::where('unita_organizzativa_id', $current->id)->orderBy('nome')->get()
            : collect();

        // Conteggi figli/strumenti per le card.
        $childCounts = $childrenByParent->map->count();
        $strumentiCounts = $children->isEmpty()
            ? collect()
            : Strumento::whereIn('unita_organizzativa_id', $children->pluck('id'))
                ->selectRaw('unita_organizzativa_id, count(*) as c')
                ->groupBy('unita_organizzativa_id')
                ->pluck('c', 'unita_organizzativa_id');

        return view('livewire.anagrafica.albero', [
            'current' => $current,
            'breadcrumb' => $breadcrumb,
            'children' => $children,
            'strumenti' => $strumenti,
            'childCounts' => $childCounts,
            'strumentiCounts' => $strumentiCounts,
            // La sede di chi guarda: il «Marchio email» si offre solo lì.
            'enteProprio' => CurrentTenant::id(),
            // Solo a modale aperta e con permesso: a modale chiusa zero query
            // in più, disciplina già seguita in SchedaStrumento::render().
            'fornitori' => $this->showStrumentoForm && $current && Gate::allows('fornitori.view')
                ? $this->fornitoriSelezionabili($current->tenant_id)->get()
                : collect(),
        ]);
    }
}

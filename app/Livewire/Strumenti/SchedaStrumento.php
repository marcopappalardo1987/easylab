<?php

namespace App\Livewire\Strumenti;

use App\Actions\Ricambi\RegistraRicambiIntervento;
use App\Enums\SoggettoGaranzia;
use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Enums\TipoIntervento;
use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Livewire\Concerns\ManagesDocumentiStrumento;
use App\Livewire\Concerns\ManagesRicambiStrumento;
use App\Livewire\Concerns\ManagesStrumentoForm;
use App\Livewire\Concerns\RiancoraStrumentoAlloScope;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Rules\NomeRicambio;
use App\Support\Tenancy\AccessoTecnico;
use App\Support\Utenti\Assegnabili;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Scheda strumento a tab (S2 punto 5). Anagrafica, Interventi, Garanzie,
 * Panoramica e — dal 15 Ago 2026 — **Ricambi** sono popolati; resta placeholder
 * il solo tab Documenti (S4). View + edit + delete del singolo strumento;
 * isolamento via route-model binding scopato (404 fuori Ente).
 *
 * Lo stato e le azioni del tab Ricambi vivono in `ManagesRicambiStrumento`, che
 * la vista campo del tecnico (blocco 10) erediterà invece di riscrivere.
 */
#[Layout('components.layouts.app')]
class SchedaStrumento extends Component
{
    use ManagesDocumentiStrumento, ManagesRicambiStrumento, ManagesStrumentoForm, RiancoraStrumentoAlloScope;

    public Strumento $strumento;

    public bool $showForm = false;

    public bool $confirmingDelete = false;

    // Modale "Sposta" (movimento interno nodo→nodo)
    public bool $showMoveForm = false;

    public ?int $destinazioneId = null;

    public ?string $dataSpostamento = null;

    public ?string $notaSpostamento = null;

    // Modali interventi (S3 punto 3)
    public bool $showInterventoForm = false;

    public ?int $editingInterventoId = null; // null = nuovo

    /** @var array{descrizione:string,tipo:string,data_scadenza:string,tecnico_id:?int,gia_eseguito:bool,data_esecuzione:string} */
    public array $interventoForm = [
        'descrizione' => '',
        'tipo' => '',
        'data_scadenza' => '',
        'tecnico_id' => null,
        'gia_eseguito' => false,
        'data_esecuzione' => '',
    ];

    // Ricambi dal form intervento (S4 — ADR-022, wireframe §2.1)
    /** Stato di UI, NON persistito: la verità è l'esistenza di righe ricambio_utilizzo. */
    public bool $ricambiEffettuati = false;

    /** @var list<array{nome:string,scadenza_garanzia:string}> */
    public array $ricambiNuovi = [];

    /** @var list<int> Rimozioni ESPLICITE, riga per riga: mai dedotte da un delta. */
    public array $ricambiRimossi = [];

    /** @var list<array{nome:string}> Suggerimenti del combobox per la riga attiva. */
    public array $suggerimenti = [];

    public ?int $ricambioAttivo = null;

    public bool $showCompletaForm = false;

    public ?int $completingInterventoId = null;

    public string $dataEsecuzione = '';

    /** Chiudendo una taratura si propone di pianificare la successiva (ADR-009). */
    public bool $pianificaProssimaTaratura = false;

    /** Periodicità in mesi: la chiede la modale, non è un default nascosto. */
    public ?int $mesiProssimaTaratura = null;

    /** Report di fine lavoro: cosa è stato trovato e cosa è stato fatto. */
    public string $reportFineLavoro = '';

    public ?int $deletingInterventoId = null; // modale conferma aperta se non null

    // Modale garanzie (S3 punto 7)
    public bool $showGaranziaForm = false;

    public ?int $editingGaranziaId = null; // null = nuova

    /** @var array{data_inizio:string,durata_mesi:?int} */
    public array $garanziaForm = [
        'data_inizio' => '',
        'durata_mesi' => null,
    ];

    public ?int $deletingGaranziaId = null; // modale conferma aperta se non null

    // Modale forzatura semaforo (S3 punto 5)
    public bool $showForzaForm = false;

    /** @var array{stato:string,motivo:string} */
    public array $forzaForm = ['stato' => '', 'motivo' => ''];

    /**
     * ⚠️ La traccia sta in `mount` e non in `render` (ADR-007/030): `render`
     * rigira a ogni interazione con la pagina — un filtro, l'apertura di una
     * modale — e produrrebbe decine di righe per una sola visita, rendendo il
     * registro illeggibile proprio a chi deve consultarlo. `mount` gira una
     * volta per apertura, che è l'evento che l'ADR vuole registrato.
     *
     * La chiamata non è condizionata: `tracciaAperturaScheda()` è no-op per
     * chiunque non sia un Tecnico, così il controllo di ruolo resta scritto in
     * un posto solo e il secondo punto d'ingresso (la scansione QR) potrà
     * chiamarla allo stesso modo.
     */
    public function mount(Strumento $strumento): void
    {
        $this->strumento = $strumento;

        AccessoTecnico::tracciaAperturaScheda($strumento);
    }

    public function edit(): void
    {
        $this->authorize('strumenti.update');
        $this->fillStrumentoForm($this->strumento);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('strumenti.update');
        $this->validate(
            $this->strumentoFormRules(
                $this->strumento->tenant_id,
                $this->strumento->fornitore_id, // riammette un fornitore cestinato già associato
            ),
            attributes: $this->strumentoFormAttributi(),
        );

        $this->strumento->update($this->strumentoPayload());

        $this->showForm = false;
    }

    public function closeForm(): void
    {
        $this->resetStrumentoForm();
        $this->showForm = false;
    }

    public function delete(): void
    {
        $this->authorize('strumenti.delete');
        $this->strumento->delete();

        $this->redirectRoute('anagrafica.index', navigate: true);
    }

    // --- Spostamento interno (nodo → nodo) ---

    public function openMove(): void
    {
        $this->authorize('strumenti.move');
        $this->reset(['destinazioneId', 'notaSpostamento']);
        $this->dataSpostamento = now()->toDateString();
        $this->showMoveForm = true;
    }

    public function move(): void
    {
        $this->authorize('strumenti.move');

        $this->validate([
            'destinazioneId' => ['required', 'integer', 'different:strumento.unita_organizzativa_id'],
            'dataSpostamento' => ['required', 'date'],
            'notaSpostamento' => ['nullable', 'string', 'max:1000'],
        ]);

        // In scope (tenant + eventuale sotto-albero); fuori scope → 404.
        $destinazione = UnitaOrganizzativa::findOrFail($this->destinazioneId);
        if ($destinazione->tipo === TipoUnitaOrganizzativa::Ente) {
            $this->addError('destinazioneId', 'Non puoi spostare uno strumento su un Ente.');

            return;
        }

        DB::transaction(function () use ($destinazione) {
            SpostamentoStrumento::create([
                'tenant_id' => $this->strumento->tenant_id,
                'strumento_id' => $this->strumento->id,
                'da_nodo_id' => $this->strumento->unita_organizzativa_id,
                'a_nodo_id' => $destinazione->id,
                'tipo_spostamento' => TipoSpostamento::Interno,
                'data' => $this->dataSpostamento,
                'eseguito_da' => auth()->id(),
                'nota' => $this->notaSpostamento,
            ]);

            $this->strumento->update(['unita_organizzativa_id' => $destinazione->id]);
        });

        $this->closeMove();
    }

    public function closeMove(): void
    {
        $this->reset(['destinazioneId', 'dataSpostamento', 'notaSpostamento']);
        $this->showMoveForm = false;
    }

    // --- Interventi (S3 punto 3): CRUD + spunta "Fatto" ---
    //
    // Ogni azione risolve l'intervento con `$this->strumento->interventi()
    // ->findOrFail($id)`: la relazione riapplica TenantScope e
    // DepartmentThroughStrumentoScope e vincola strumento_id, quindi un solo
    // idioma dà 404 per un intervento di un altro tenant, per il Responsabile
    // fuori sotto-albero e per un id di un altro strumento.

    public function openNuovoIntervento(): void
    {
        $this->authorize('interventi.create');
        $this->resetInterventoForm();
        $this->editingInterventoId = null;
        $this->showInterventoForm = true;
    }

    public function openModificaIntervento(int $id): void
    {
        $this->authorize('interventi.update');
        $intervento = $this->strumento->interventi()->findOrFail($id);

        $this->resetInterventoForm();
        $this->interventoForm = [
            'descrizione' => $intervento->descrizione,
            'tipo' => $intervento->tipo->value,
            'data_scadenza' => $intervento->data_scadenza->toDateString(),
            'tecnico_id' => $intervento->tecnico_id,
            'gia_eseguito' => false, // il blocco "già eseguito" esiste solo in create
            'data_esecuzione' => today()->toDateString(),
        ];
        $this->editingInterventoId = $id;

        // DOPO il reset, che la azzera. Il flag resta stato di UI e non un
        // campo persistito (ADR-022: la verità è l'esistenza delle righe), ma
        // il suo valore iniziale ora la RIFLETTE invece di contraddirla:
        // riaprendo un intervento con dei pezzi la checkbox era spenta e le
        // righe salvate risultavano invisibili, il che si legge come «i miei
        // dati sono spariti».
        $this->ricambiEffettuati = $intervento->ricambiUtilizzi()->exists();

        $this->showInterventoForm = true;
    }

    public function saveIntervento(): void
    {
        $this->authorize($this->editingInterventoId === null ? 'interventi.create' : 'interventi.update');
        $this->validate($this->interventoFormRules());

        // ⚠️ Tutto ciò che può fermare il salvataggio — authorize, addError,
        // early-return — sta QUI, prima della transazione: un `return` dentro
        // la closure di DB::transaction chiuderebbe solo la closure e la
        // transazione verrebbe COMMITTATA lo stesso.

        $righeNuove = $this->ricambiEffettuati ? $this->righeRicambiPulite() : [];

        if ($righeNuove !== [] && ! $this->ricambiSenzaDoppioni($righeNuove)) {
            return;
        }

        // A differenza di tecnico_id — metadato che si può scartare in silenzio —
        // qui scartare significherebbe «salvato» con i pezzi svaniti: la risposta
        // onesta a un payload forgiato è 403. Tre permessi perché la riga scrive
        // tre tabelle; `ricambi.create` sempre e non "solo se la voce è nuova",
        // perché un permesso che dipende dal contenuto del catalogo è
        // impossibile da spiegare all'utente.
        if ($righeNuove !== []) {
            $this->authorize('ricambio_utilizzo.create');
            $this->authorize('ricambi.create');
            // ADR-029: l'ability della Policy, NON il permesso nudo — che
            // spatie concederebbe prima che l'impostazione dell'Ente sia letta.
            $this->authorize('manage', Garanzia::class); // ogni riga scrive SEMPRE una garanzia (ADR-022)
        }

        if ($this->ricambiRimossi !== []) {
            $this->authorize('ricambio_utilizzo.delete');
            $this->authorize('manage', Garanzia::class); // si cestina anche la garanzia (ADR-029)
        }

        $payload = [
            // Con dei ricambi la descrizione è facoltativa (vedi le regole) e
            // il default lo mette qui l'applicazione: la colonna è NOT NULL
            // senza default, e una descrizione vuota lascerebbe celle bianche
            // in tabella e virgolette spaiate «» nelle modali di conferma.
            'descrizione' => filled($this->interventoForm['descrizione'])
                ? $this->interventoForm['descrizione']
                : 'Sostituzione ricambio',
            'tipo' => $this->interventoForm['tipo'],
            'data_scadenza' => $this->interventoForm['data_scadenza'],
        ];

        // tecnico_id: mai fidarsi del payload Livewire. Senza `interventi.assign`
        // la chiave non entra nel payload (create → null, update → invariato);
        // con il permesso, l'id deve appartenere alla stessa whitelist del select.
        if (Gate::allows('interventi.assign')) {
            $tecnicoId = $this->interventoForm['tecnico_id'] ?: null;
            // 🔗 ADR-038: la whitelist è `App\Support\Utenti\Assegnabili`,
            // la STESSA che riempie il select qui sotto in `render()`. Le due
            // non possono divergere — è l'intero motivo per cui la definizione
            // vive in un posto solo, fuori da questa classe.
            // ⚠️ **∪ l'assegnatario già sulla riga**, quando si modifica. La
            // regola nuova di ADR-038 restringe *chi si può assegnare*, non
            // *cosa si può conservare*: senza questa eccezione un intervento
            // storico diventava **non più salvabile** il giorno in cui il suo
            // tecnico veniva cestinato o usciva dal portafoglio — la modale lo
            // ricarica in `openModificaIntervento()`, la `<select>` non lo
            // offre più, `tecnico_id` è `required`, e correggere una
            // descrizione rispondeva «Assegnatario non valido» su un campo che
            // nessuno aveva toccato. È esattamente il contrario di ciò che
            // ADR-038 promette allo storico. Cambiarlo resta ristretto: solo
            // *conservarlo* è ammesso.
            if ($tecnicoId !== null
                && $tecnicoId !== $this->assegnatarioInEssere()?->id
                && ! Assegnabili::perStrumento($this->strumento)->whereKey($tecnicoId)->exists()) {
                $this->addError('interventoForm.tecnico_id', 'Assegnatario non valido.');

                return;
            }
            $payload['tecnico_id'] = $tecnicoId;
        }

        // Intervento e righe ricambio atomici (ADR-022). Il servizio apre a sua
        // volta una transazione propria: annidata → savepoint, costo nullo.
        DB::transaction(function () use ($payload, $righeNuove) {
            if ($this->editingInterventoId === null) {
                $intervento = new Intervento($payload + [
                    'tenant_id' => $this->strumento->tenant_id, // invariante interventi.tenant_id == strumenti.tenant_id
                    'strumento_id' => $this->strumento->id,     // reseller_id resta NULL (ADR-002)
                ]);
                if ($this->interventoForm['gia_eseguito']) {
                    // Inserimento storico in un passo: l'hook `saving` regge l'invariante.
                    $intervento->stato = StatoIntervento::Fatto;
                    $intervento->data_esecuzione = $this->interventoForm['data_esecuzione'];
                }
                $intervento->save();
            } else {
                $intervento = $this->strumento->interventi()->findOrFail($this->editingInterventoId);
                $intervento->update($payload);
            }

            if ($righeNuove !== [] || $this->ricambiRimossi !== []) {
                app(RegistraRicambiIntervento::class)
                    ->esegui($intervento, $righeNuove, array_map(intval(...), $this->ricambiRimossi));
            }
        });

        $this->closeInterventoForm();
    }

    // --- Repeater ricambi (ADR-022) — stesso pattern del repeater `parametri` ---

    public function addRicambio(): void
    {
        $this->ricambiNuovi[] = ['nome' => '', 'scadenza_garanzia' => ''];
    }

    public function removeRicambio(int $index): void
    {
        unset($this->ricambiNuovi[$index]);
        // array_values è OBBLIGATORIO: i wire:key sono per indice, e senza
        // ricompattare il patch DOM confonderebbe le righe (come per `parametri`).
        $this->ricambiNuovi = array_values($this->ricambiNuovi);
        $this->suggerimenti = [];
        $this->ricambioAttivo = null;
    }

    /** Segna una riga SALVATA per la rimozione: effettiva solo al salvataggio. */
    public function segnaRicambioRimosso(int $id): void
    {
        $this->strumento->ricambiUtilizzati()->findOrFail($id); // 404 fuori scope

        if (! in_array($id, array_map(intval(...), $this->ricambiRimossi), true)) {
            $this->ricambiRimossi[] = $id;
        }
    }

    public function annullaRimozioneRicambio(int $id): void
    {
        $this->ricambiRimossi = array_values(
            array_filter($this->ricambiRimossi, fn ($r) => (int) $r !== $id)
        );
    }

    /** Selezione dal combobox: è il percorso che anche Invio esegue (via click). */
    public function scegliRicambio(int $index, string $nome): void
    {
        if (isset($this->ricambiNuovi[$index])) {
            $this->ricambiNuovi[$index]['nome'] = $nome;
        }
        $this->suggerimenti = [];
        $this->ricambioAttivo = null;
    }

    /**
     * Ricerca del combobox, agganciata al wire:model.live della riga attiva:
     * nessun round-trip in più rispetto a quello che il binding già fa.
     */
    public function updated(string $property, mixed $value): void
    {
        if (! preg_match('/^ricambiNuovi\.(\d+)\.nome$/', $property, $m)) {
            return;
        }

        $this->ricambioAttivo = (int) $m[1];
        $this->suggerimenti = $this->cercaRicambi((string) $value);
    }

    /**
     * Suggerimenti dal catalogo dell'Ente (ADR-022): prefisso sul nome
     * normalizzato — MAI `%...%`, che non userebbe l'indice unique parziale
     * (il cui predicato `deleted_at is null` è lo stesso che SoftDeletes emette).
     *
     * ⚠️ `like` è case-insensitive su SQLite (ASCII) e case-SENSITIVE su
     * Postgres: qui è innocuo perché confronta due valori già passati da
     * normalizzaNome() (entrambi minuscoli), ma chi sostituisse la colonna con
     * `nome` avrebbe un autocomplete che funziona in locale e non in produzione.
     *
     * ⚠️ Il `where('tenant_id')` esplicito **oggi è ridondante** — il
     * TenantScope filtra già, perché qui si arriva solo da utente autenticato —
     * e infatti la mutazione che lo toglie non fa cadere nessun test. Non è
     * però la stessa ridondanza di `collegaOCrea` (che gira anche in console):
     * qui filtra sul tenant **dello strumento**, non su quello dell'utente, ed
     * è ciò che servirà al Tecnico di ADR-007 (S4 blocco 10), che ha
     * `tenant_id` NULL e lavora cross-tenant: per lui il TenantScope è
     * fail-closed e restituirebbe un catalogo vuoto. Consegna a quel blocco:
     * quando l'accesso del Tecnico esiste, questo filtro diventa falsificabile
     * e va coperto da un test.
     *
     * @return list<array{nome:string}>
     */
    private function cercaRicambi(string $digitato): array
    {
        // "Sto ancora digitando" non è un errore: il guard evita che un input di
        // soli spazi/NBSP finisca in normalizzaNome(), che lancerebbe.
        if (trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $digitato)) === '' || mb_strlen(trim($digitato)) < 2) {
            return [];
        }

        $prefisso = Ricambio::normalizzaNome($digitato);

        return Ricambio::query()
            ->where('tenant_id', $this->strumento->tenant_id)
            ->where('nome_normalizzato', 'like', $prefisso.'%')
            ->orderBy('nome_normalizzato')
            ->limit(8)
            ->get(['id', 'nome'])
            ->map(fn (Ricambio $r) => ['nome' => $r->nome])
            ->all();
    }

    /**
     * Righe compilate del repeater. Le righe interamente vuote si scartano
     * (come per `parametri`): una riga aggiunta e mai toccata non è un errore.
     *
     * @return list<array{nome:string,scadenza_garanzia:string}>
     */
    private function righeRicambiPulite(): array
    {
        return array_values(array_filter(
            $this->ricambiNuovi,
            fn (array $riga) => trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $riga['nome'] ?? '')) !== ''
                || trim($riga['scadenza_garanzia'] ?? '') !== ''
        ));
    }

    /**
     * Due righe che normalizzano uguale sono un refuso, non due pezzi: il form
     * non ha il campo quantità (wireframe §2.1), e "due guarnizioni" si
     * registra dal tab Ricambi con quantita = 2. `distinct:ignore_case` non
     * basterebbe: confronta i grezzi, e `Filtro HEPA` / `filtro  hepa`
     * passerebbero.
     *
     * @param  list<array{nome:string,scadenza_garanzia:string}>  $righe
     */
    private function ricambiSenzaDoppioni(array $righe): bool
    {
        $visti = [];

        foreach ($righe as $i => $riga) {
            $chiave = Ricambio::normalizzaNome($riga['nome']);
            if (isset($visti[$chiave])) {
                $this->addError("ricambiNuovi.{$i}.nome", 'Ricambio già presente in un\'altra riga: per più pezzi uguali usare il tab Ricambi.');

                return false;
            }
            $visti[$chiave] = true;
        }

        return true;
    }

    /**
     * Unica definizione, come `Assegnabili`: la usa il render (checkbox
     * visibile) e la usa il save (authorize). Il set completo dei tre permessi
     * è più stretto del solo `ricambio_utilizzo.create` del wireframe, ed è più
     * onesto: mostrare la checkbox a chi poi prende 403 è peggio che nasconderla.
     */
    private function puoRegistrareRicambi(): bool
    {
        return Gate::allows('ricambio_utilizzo.create')
            && Gate::allows('ricambi.create')
            && Gate::allows('manage', Garanzia::class);
    }

    public function closeInterventoForm(): void
    {
        $this->resetInterventoForm();
        $this->editingInterventoId = null;
        $this->showInterventoForm = false;
    }

    public function openCompleta(int $id): void
    {
        $this->authorize('interventi.complete');

        // Risolto una volta: serve sia come guardia (404 fuori scope) sia per
        // sapere se è una taratura.
        $intervento = $this->strumento->interventi()->findOrFail($id);

        $this->completingInterventoId = $id;
        $this->dataEsecuzione = today()->toDateString();
        // Preselezionata sulle tarature: è il caso in cui la prossima serve
        // quasi sempre. Resta una spunta, non un automatismo.
        $this->pianificaProssimaTaratura = $intervento->tipo === TipoIntervento::TaraturaECertificazione;
        $this->mesiProssimaTaratura = null;
        // Ri-chiudendo un intervento riaperto si ritrova ciò che si era scritto:
        // ripartire da vuoto farebbe credere che la nota sia andata persa.
        $this->reportFineLavoro = (string) $intervento->report_fine_lavoro;
        $this->resetValidation();
        $this->showCompletaForm = true;
    }

    public function completa(): void
    {
        $this->authorize('interventi.complete');

        $intervento = $this->strumento->interventi()->findOrFail($this->completingInterventoId);
        $eraTaratura = $intervento->tipo === TipoIntervento::TaraturaECertificazione;

        $this->validate([
            'dataEsecuzione' => ['required', 'date', 'before_or_equal:today'],
            // La periodicità si chiede solo dove ha senso, e solo se l'utente
            // ha scelto di pianificare: una regola incondizionata bloccherebbe
            // la chiusura di un intervento qualunque su un campo che non c'è.
            'mesiProssimaTaratura' => $eraTaratura && $this->pianificaProssimaTaratura
                ? ['required', 'integer', 'between:1,120']
                : ['nullable'],
            // Facoltativo: un lavoro fatto resta fatto anche senza nota, e
            // pretenderla qui significherebbe bloccare la chiusura di migliaia
            // di interventi storici che non ne hanno una.
            'reportFineLavoro' => ['nullable', 'string', 'max:5000'],
        ]);

        // Sempre il metodo di dominio, mai update by-query (invariante nel model).
        $intervento->segnaFatto(Carbon::parse($this->dataEsecuzione), $this->reportFineLavoro);

        // La successiva nasce DOPO la chiusura e fuori dalla sua transazione:
        // se fallisse, il lavoro fatto resterebbe registrato — chiudere una
        // taratura e pianificarne un'altra sono due gesti, e il primo non deve
        // dipendere dal secondo.
        if ($eraTaratura && $this->pianificaProssimaTaratura) {
            $intervento->pianificaTaraturaSuccessiva((int) $this->mesiProssimaTaratura);
        }

        $this->closeCompleta();
    }

    public function closeCompleta(): void
    {
        $this->reset(['completingInterventoId', 'dataEsecuzione', 'pianificaProssimaTaratura', 'mesiProssimaTaratura', 'reportFineLavoro']);
        $this->showCompletaForm = false;
    }

    // --- Garanzie (S3 punto 7, ADR-004/019) ---
    //
    // Stesso idioma degli interventi: `$this->strumento->garanzie()
    // ->findOrFail($id)` — la relazione riapplica TenantScope,
    // GaranziaDepartmentScope (dall'8 Ago 2026 al posto del trait generico:
    // sulle righe ricambio il sotto-albero passa da un doppio salto) e la
    // privacy sui ricambi, e vincola strumento_id: un solo idioma copre altro
    // tenant, fuori sotto-albero e id di un altro strumento.
    //
    // La relazione porta comunque le sole righe `macchina`, perché filtra su
    // `strumento_id`: le garanzie dei pezzi montati arrivano col tab Ricambi
    // (S4 blocco 5), non da qui.

    public function openNuovaGaranzia(): void
    {
        $this->authorize('garanzie.macchina.manage');
        $this->resetGaranziaForm();
        $this->editingGaranziaId = null;
        $this->showGaranziaForm = true;
    }

    public function openModificaGaranzia(int $id): void
    {
        $this->authorize('garanzie.macchina.manage');
        $garanzia = $this->strumento->garanzie()->findOrFail($id);

        $this->resetGaranziaForm();
        $this->garanziaForm = [
            'data_inizio' => $garanzia->data_inizio->toDateString(),
            'durata_mesi' => $garanzia->durata_mesi,
        ];
        $this->editingGaranziaId = $id;
        $this->showGaranziaForm = true;
    }

    public function saveGaranzia(): void
    {
        $this->authorize('garanzie.macchina.manage');
        $this->validate($this->garanziaFormRules());

        // `data_scadenza_effettiva` non compare MAI nel payload: la calcola il
        // model a ogni salvataggio (ed è fuori da $fillable).
        $payload = [
            'data_inizio' => $this->garanziaForm['data_inizio'],
            'durata_mesi' => $this->garanziaForm['durata_mesi'],
        ];

        if ($this->editingGaranziaId === null) {
            // soggetto/tenant/strumento dal contesto, mai dal payload.
            $this->strumento->garanzie()->create($payload + [
                'tenant_id' => $this->strumento->tenant_id,
                'soggetto' => SoggettoGaranzia::Macchina,
            ]);
        } else {
            $this->strumento->garanzie()->findOrFail($this->editingGaranziaId)->update($payload);
        }

        $this->closeGaranziaForm();
    }

    public function closeGaranziaForm(): void
    {
        $this->resetGaranziaForm();
        $this->showGaranziaForm = false;
    }

    public function openEliminaGaranzia(int $id): void
    {
        $this->authorize('garanzie.macchina.manage');
        $this->strumento->garanzie()->findOrFail($id);

        $this->deletingGaranziaId = $id;
    }

    public function eliminaGaranzia(): void
    {
        $this->authorize('garanzie.macchina.manage');
        $this->strumento->garanzie()->findOrFail($this->deletingGaranziaId)->delete();

        $this->deletingGaranziaId = null;
    }

    /**
     * Una sola forma di garanzia dopo ADR-019: inizio + durata in mesi.
     * `data_inizio` nel passato è permessa — registrare una garanzia già finita
     * è storico legittimo, come per `interventi.data_scadenza`.
     */
    protected function garanziaFormRules(): array
    {
        return [
            'garanziaForm.data_inizio' => ['required', 'date'],
            'garanziaForm.durata_mesi' => ['required', 'integer', 'min:1', 'max:600'],
        ];
    }

    protected function resetGaranziaForm(): void
    {
        $this->garanziaForm = [
            'data_inizio' => today()->toDateString(),
            'durata_mesi' => 24,
        ];
        $this->resetValidation();
    }

    // --- Forzatura semaforo (S3 punto 5, ADR-005) ---
    //
    // A differenza delle azioni sugli interventi, qui non serve un findOrFail
    // scopato: si agisce sullo strumento GIÀ bindato dalla rotta, quindi
    // l'isolamento è il route-model binding (404 fuori Ente/sotto-albero).

    public function openForza(): void
    {
        $this->authorize('semaforo.force');

        $this->forzaForm = [
            // Default Rosso: è il caso d'uso primario dell'ADR ("non idoneo"
            // dopo verifica). Se già forzato, si riparte da quella scelta.
            'stato' => $this->strumento->forced_state?->value ?? StatoSemaforo::Rosso->value,
            'motivo' => $this->strumento->forced_reason ?? '',
        ];
        $this->resetValidation();
        $this->showForzaForm = true;
    }

    public function forza(): void
    {
        $this->authorize('semaforo.force');
        $this->validate($this->forzaFormRules());

        // Lo stato passa da Rule::in nella validazione; il motivo obbligatorio
        // sul rosso è comunque riverificato dall'invariante nel model.
        $this->strumento->forzaSemaforo(
            StatoSemaforo::from($this->forzaForm['stato']),
            $this->forzaForm['motivo'] ?: null,
        );

        $this->closeForza();
    }

    /**
     * Rimozione senza conferma, come riapri(): è simmetrica e ripetibile (la
     * forzatura si ridà in due click), e la conferma resta alle distruttive.
     */
    public function rimuoviForzatura(): void
    {
        $this->authorize('semaforo.force');
        $this->strumento->rimuoviForzatura();

        $this->closeForza();
    }

    public function closeForza(): void
    {
        $this->reset(['forzaForm']);
        $this->resetValidation();
        $this->showForzaForm = false;
    }

    /** Il motivo è obbligatorio solo per il rosso (ADR-005 + decisione S3). */
    protected function forzaFormRules(): array
    {
        return [
            'forzaForm.stato' => ['required', Rule::in(array_map(fn (StatoSemaforo $c) => $c->value, StatoSemaforo::cases()))],
            'forzaForm.motivo' => $this->forzaForm['stato'] === StatoSemaforo::Rosso->value
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Riapertura senza conferma: azione simmetrica e ripetibile (la conferma
     * resta riservata alle azioni distruttive). Stesso permesso della spunta.
     */
    public function riapri(int $id): void
    {
        $this->authorize('interventi.complete');
        $this->strumento->interventi()->findOrFail($id)->riapri();
    }

    public function openEliminaIntervento(int $id): void
    {
        $this->authorize('interventi.delete');
        $this->strumento->interventi()->findOrFail($id);

        $this->deletingInterventoId = $id;
    }

    public function eliminaIntervento(): void
    {
        $this->authorize('interventi.delete');

        $intervento = $this->strumento->interventi()->findOrFail($this->deletingInterventoId);

        // Cancellare l'intervento cancella anche ciò che ha prodotto (decisione
        // del 15 Ago 2026). Prima non lo faceva, e il difetto era invisibile
        // perché il doppio salto di ADR-020 guarda `ricambio_utilizzo` e non
        // passa da `interventi`: la garanzia di un pezzo il cui intervento era
        // stato cestinato continuava ad accendere il semaforo, per un
        // montaggio che nella scheda non risultava più da nessuna parte.
        //
        // In transazione, perché la riga di montaggio e la sua garanzia devono
        // sparire insieme all'intervento o restare tutte: una cancellazione a
        // metà è esattamente lo stato che il difetto produceva.
        DB::transaction(function () use ($intervento): void {
            $intervento->ricambiUtilizzi()->get()
                ->each(fn (RicambioUtilizzo $utilizzo) => $utilizzo->cestinaConGaranzia());

            $intervento->delete();
        });

        $this->deletingInterventoId = null;
    }

    protected function interventoFormRules(): array
    {
        return [
            // Obbligatoria SOLO in assenza di ricambi: quando l'intervento
            // nasce per registrare un pezzo, la descrizione la mette il
            // salvataggio («Ricambio effettuato»). Stesso pattern condizionale
            // di `gia_eseguito` e delle righe ricambio.
            'interventoForm.descrizione' => $this->haRicambi()
                ? ['nullable', 'string', 'max:1000']
                : ['required', 'string', 'max:1000'],
            'interventoForm.tipo' => ['required', Rule::in(array_map(fn (TipoIntervento $c) => $c->value, TipoIntervento::cases()))],
            'interventoForm.data_scadenza' => ['required', 'date'], // passato permesso: lo storico è legittimo (ADR-005)
            // Un intervento è sempre assegnato a un tecnico (9 Ago 2026).
            //
            // **Obbligatorio nel form, nullable nello schema**: è il precedente
            // già ratificato da ADR-023 per il fornitore. Sul DB di sviluppo
            // 4178 interventi su 20672 non hanno assegnatario — righe storiche
            // che una FK NOT NULL renderebbe non salvabili, e a cui bisognerebbe
            // inventare un tecnico per farle passare. Un vincolo che costringe a
            // inventare un valore non protegge nulla.
            //
            // Condizionato al permesso, non incondizionato: chi non ha
            // `interventi.assign` non mette la chiave nel payload (vedi
            // saveIntervento), quindi un `required` lo bloccherebbe del tutto.
            // Oggi non c'è nessuno in quello stato — un meta-test lo congela —
            // ma la regola non deve dipendere da quel fatto per non esplodere.
            'interventoForm.tecnico_id' => Gate::allows('interventi.assign')
                ? ['required', 'integer']
                : ['nullable', 'integer'],
            'interventoForm.gia_eseguito' => ['boolean'],
            'interventoForm.data_esecuzione' => $this->interventoForm['gia_eseguito']
                ? ['required', 'date', 'before_or_equal:today']
                : ['nullable'],

            // Ricambi (ADR-022): stesso pattern condizionale di `gia_eseguito` —
            // la checkbox è wire:model.live, quindi le regole si ricalcolano a
            // ogni spunta e il repeater chiuso non blocca il salvataggio.
            'ricambiEffettuati' => ['boolean'],
            'ricambiNuovi' => ['array', 'max:20'],
            'ricambiNuovi.*.nome' => $this->ricambiEffettuati
                ? ['required', 'string', 'max:255', new NomeRicambio]
                : ['nullable'],
            // `after:` la data di MONTAGGIO e non `after:today`: con today un
            // inserimento storico (intervento eseguito a gennaio, garanzia fino
            // a febbraio) passerebbe la validazione e verrebbe rifiutato dal
            // model con un'eccezione. Guardia su due livelli sì, ma il livello
            // utente deve parlare la stessa lingua di quello di dominio.
            'ricambiNuovi.*.scadenza_garanzia' => $this->ricambiEffettuati
                ? ['required', 'date', 'after:'.$this->dataMontaggio()]
                : ['nullable'],
            'ricambiRimossi' => ['array'],
            'ricambiRimossi.*' => ['integer'],
        ];
    }

    /** La data che il servizio userà come montaggio e come `data_inizio` della garanzia. */
    private function dataMontaggio(): string
    {
        return ($this->interventoForm['gia_eseguito'] ? $this->interventoForm['data_esecuzione'] : null)
            ?: today()->toDateString();
    }

    /**
     * I nomi leggibili dei campi vivono in `lang/it/validation.php` sotto
     * `attributes`, non qui: valgono per tutti i form del progetto e non solo
     * per questo componente, e una seconda definizione locale li farebbe
     * divergere. (Qui c'era l'unico `validationAttributes()` del progetto, e
     * copriva due chiavi su decine.)
     */
    private function haRicambi(): bool
    {
        if ($this->ricambiEffettuati && $this->righeRicambiPulite() !== []) {
            return true;
        }

        // Anche le righe già salvate contano: in modifica, un intervento nato
        // per registrare un pezzo non deve tornare a pretendere la descrizione.
        return $this->editingInterventoId !== null
            && $this->strumento->interventi()->whereKey($this->editingInterventoId)
                ->whereHas('ricambiUtilizzi')->exists();
    }

    protected function resetInterventoForm(): void
    {
        $this->interventoForm = [
            'descrizione' => '',
            'tipo' => TipoIntervento::ManutenzioneOrdinaria->value,
            'data_scadenza' => today()->toDateString(),
            'tecnico_id' => null,
            'gia_eseguito' => false,
            'data_esecuzione' => today()->toDateString(),
        ];
        $this->ricambiEffettuati = false;
        $this->ricambiNuovi = [];
        $this->ricambiRimossi = [];
        $this->suggerimenti = [];
        $this->ricambioAttivo = null;
        $this->resetValidation();
    }

    /**
     * L'assegnatario **già scritto** sull'intervento in modifica, se c'è.
     *
     * `withTrashed()` di proposito: è la stessa lettura delle cinque relazioni
     * di attribuzione storica (🔗 ADR-038) — una persona cestinata resta
     * nominata da ciò che ha fatto, e resta quindi un valore legittimo da
     * riscrivere così com'è.
     */
    private function assegnatarioInEssere(): ?User
    {
        if ($this->editingInterventoId === null) {
            return null;
        }

        return $this->strumento->interventi()
            ->whereKey($this->editingInterventoId)
            ->first()?->tecnico;
    }

    /**
     * Le opzioni della tendina: `Assegnabili` ∪ chi è **già** sulla riga.
     *
     * Speculare esatto della validazione in `saveIntervento()` — è l'idioma di
     * questa classe, «una definizione sola per il select e per il save» — con
     * la stessa eccezione e per la stessa ragione: una tendina che non contiene
     * il valore corrente costringe a cambiarlo per poter salvare altro.
     *
     * @return Collection<int, User>
     */
    private function assegnatariProponibili(): Collection
    {
        $assegnabili = Assegnabili::perStrumento($this->strumento)->orderBy('name')->get();

        $inEssere = $this->assegnatarioInEssere();

        if ($inEssere === null || $assegnabili->contains('id', $inEssere->id)) {
            return $assegnabili;
        }

        return $assegnabili->push($inEssere)->sortBy('name')->values();
    }

    public function render()
    {

        // Destinazioni possibili: nodi non-Ente del proprio Ente (già scopati),
        // escluso il nodo attuale.
        $nodiDestinazione = UnitaOrganizzativa::where('tipo', '!=', TipoUnitaOrganizzativa::Ente->value)
            ->where('id', '!=', $this->strumento->unita_organizzativa_id)
            ->orderBy('nome')
            ->get();

        // Una sola volta: la Panoramica ricava prossimo/ultimo/statistiche da
        // QUESTA collection, senza tornare al DB (ADR-024 — la vista di sintesi
        // non deve costare query in più del tab che riassume).
        $interventi = $this->interventiPerUrgenza();

        // Stessa regola per ricambi e documenti (S4 blocco 7): la Panoramica
        // riassume un TAB, non ricalcola per conto proprio. I contatori sono
        // fold PHP su queste due collection — quelle che i rispettivi tab
        // caricano comunque — quindi il pannello di sintesi continua a costare
        // zero query in più di ciò che riassume, come vuole ADR-024.
        //
        // Conseguenza voluta: se un giorno il tab Documenti passasse a una
        // paginazione, il contatore diventerebbe "documenti in pagina" e
        // andrebbe rifatto con un `count()` aggregato. È il prezzo di non avere
        // due definizioni di "quali documenti sono di questa macchina".
        $ricambi = $this->ricambiMontati();
        $documenti = $this->documentiMostrati();

        return view('livewire.strumenti.scheda-strumento', [
            'percorso' => $this->strumento->percorsoUbicazione(),
            // Semaforo (ADR-005): sempre lo stato "effettivo" (forzato ?? calcolato).
            'semaforo' => $this->strumento->statoSemaforoEffettivo(),
            // Diagnosi (ADR-024): stato CALCOLATO + motivi, per il tab Panoramica.
            // Non è lo stato effettivo di proposito: quando c'è una forzatura la
            // Panoramica mostra entrambi, e il calcolato è quello da spiegare.
            'diagnosi' => $this->strumento->diagnosiSemaforo(),
            'prossimoIntervento' => $this->prossimoInterventoPianificato($interventi),
            'ultimoIntervento' => $this->ultimoInterventoEseguito($interventi),
            'statInterventi' => $this->statisticheInterventi($interventi),
            'interventi' => $interventi,
            // Solo a modale aperta e con permesso: a modale chiusa zero query
            // extra su users (il test N+1 del punto 2 lo congela).
            // Solo a modale aperta: a modale chiusa zero query in più (il test
            // N+1 sulla scheda lo congela).
            'fornitori' => $this->showForm && Gate::allows('fornitori.view')
                ? $this->fornitoriSelezionabili($this->strumento->tenant_id, $this->strumento->fornitore_id)->get()
                : collect(),
            // 🔗 ADR-038: le persone dell'Ente ∪ i tecnici EasyLab **in
            // portafoglio su questa sede** — non più «ogni tecnico ovunque».
            // Stessa sorgente della validazione in `saveIntervento()`.
            'assegnatari' => $this->showInterventoForm && Gate::allows('interventi.assign')
                ? $this->assegnatariProponibili()
                : collect(),
            // Gated: chi non ha il permesso non paga nemmeno la query.
            'garanzie' => Gate::allows('garanzie.macchina.view')
                ? $this->strumento->garanzie()->get()
                : collect(),
            // ADR-022: la checkbox "Ricambio effettuato" e il repeater.
            'puoRegistrareRicambi' => $this->puoRegistrareRicambi(),
            // Righe già salvate su QUESTO intervento: sola lettura + ✕ (la
            // correzione è del tab Ricambi, S4 blocco 5). Solo a modale aperta
            // in modifica: a modale chiusa zero query in più.
            'ricambiSalvati' => $this->showInterventoForm && $this->editingInterventoId !== null
                ? $this->strumento->interventi()->findOrFail($this->editingInterventoId)
                    ->ricambiUtilizzi()->with('ricambio')->get()
                : collect(),
            'ricambiMontati' => $ricambi,
            'statRicambi' => $this->statisticheRicambi($ricambi),
            'documenti' => $documenti,
            // Il fornitore nel blocco "In sintesi" (ADR-023/024). Gated qui e
            // non solo nella vista: chi non ha `fornitori.view` non paga la
            // query, ed è la stessa postura di `garanzie` qui sopra.
            //
            // La relazione ha `withTrashed()`: un fornitore cestinato torna
            // valorizzato e la vista lo etichetta, perché una cella vuota si
            // legge «mai inserito» e non «cestinato».
            'fornitore' => Gate::allows('fornitori.view')
                ? $this->strumento->fornitore
                : null,
            'certificati' => $this->certificatiPerIntervento(),
            'interventiAllegabili' => $this->interventiAllegabili(),
            'tipiDocumento' => $this->tipiDocumento(),
            'puoCorreggereRicambi' => $this->puoCorreggereRicambi(),
            'vedeGaranzieRicambio' => Gate::allows('view', Garanzia::class),
            'spostamenti' => $this->strumento->spostamenti()->with(['daNodo', 'aNodo', 'eseguitoBy'])->get(),
            'nodiDestinazione' => $nodiDestinazione,
        ]);
    }

    /**
     * Lista del tab Interventi (S3 punto 2), ordinata per urgenza: scaduti-non-
     * fatti, poi pianificati (scadenza più vicina in cima), infine lo storico.
     *
     * L'ordinamento è in PHP e non in SQL di proposito: un `orderByRaw` con un
     * CASE duplicherebbe in SQL la regola di `Intervento::isScaduto()` (due
     * copie del confine `< oggi` che possono divergere), e servirebbero
     * direzioni miste con NULL, il cui ordinamento cambia da un DB all'altro.
     * Qui il set è di un solo strumento: poche decine di righe già caricate.
     *
     * @return Collection<int, Intervento>
     */
    private function interventiPerUrgenza(): Collection
    {
        if (! Gate::allows('interventi.view')) {
            return collect();
        }

        return $this->strumento->interventi()
            ->with('tecnico') // NON `tecnico:id,name`: tecnicoLabel() legge tenant_id
            ->get()
            ->sortBy([
                fn (Intervento $a, Intervento $b) => $this->urgenza($a) <=> $this->urgenza($b),
                fn (Intervento $a, Intervento $b) => $this->urgenza($a) === 2
                    ? $b->data_esecuzione <=> $a->data_esecuzione // storico: eseguiti di recente in cima
                    : $a->data_scadenza <=> $b->data_scadenza,    // aperti: scadenza più vecchia/vicina in cima
            ])
            ->values();
    }

    /** 0 = scaduto-non-fatto · 1 = pianificato · 2 = fatto. */
    private function urgenza(Intervento $intervento): int
    {
        return match (true) {
            $intervento->isScaduto() => 0,
            $intervento->stato === StatoIntervento::NonFatto => 1,
            default => 2,
        };
    }

    // --- Sintesi per la Panoramica (S3-bis punto E, ADR-024) ---
    //
    // Tutte e tre lavorano sulla collection già caricata da
    // interventiPerUrgenza(): zero query aggiuntive, e il gate `interventi.view`
    // è già applicato là (senza permesso la collection è vuota, quindi qui esce
    // naturalmente "niente da mostrare" invece di un dato trapelato).
    // Le regole di dominio NON si riscrivono: `isScaduto()` è l'unica fonte.

    /**
     * Prossimo intervento **pianificato**: il più vicino fra gli aperti non
     * ancora scaduti. Gli scaduti non compaiono qui — sono motivi del semaforo,
     * e mostrarli come "prossimo" farebbe sembrare in programma ciò che è in
     * ritardo.
     *
     * @param  Collection<int, Intervento>  $interventi
     */
    private function prossimoInterventoPianificato(Collection $interventi): ?Intervento
    {
        return $interventi
            ->filter(fn (Intervento $i) => $i->stato === StatoIntervento::NonFatto && ! $i->isScaduto())
            ->sortBy([['data_scadenza', 'asc'], ['id', 'asc']])
            ->first();
    }

    /**
     * Ultimo intervento eseguito, per data di esecuzione.
     *
     * @param  Collection<int, Intervento>  $interventi
     */
    private function ultimoInterventoEseguito(Collection $interventi): ?Intervento
    {
        return $interventi
            ->filter(fn (Intervento $i) => $i->stato === StatoIntervento::Fatto)
            ->sortByDesc(fn (Intervento $i) => [$i->data_esecuzione?->getTimestamp() ?? 0, $i->id])
            ->first();
    }

    /**
     * Conteggi leggeri della macchina. "Ultimi 12 mesi" guarda la data di
     * ESECUZIONE, non la scadenza: la domanda è quanto si è lavorato su questa
     * macchina, non quanto era in programma.
     *
     * @param  Collection<int, Intervento>  $interventi
     * @return array{dodiciMesi:int, scadutiAperti:int}
     */
    private function statisticheInterventi(Collection $interventi): array
    {
        $limite = today()->subYear();

        return [
            'dodiciMesi' => $interventi
                ->filter(fn (Intervento $i) => $i->stato === StatoIntervento::Fatto
                    && $i->data_esecuzione !== null
                    && $i->data_esecuzione->gte($limite))
                ->count(),
            'scadutiAperti' => $interventi->filter(fn (Intervento $i) => $i->isScaduto())->count(),
        ];
    }

    /**
     * Conteggi dei ricambi per la Panoramica (S4 blocco 7 — ADR-020/024/029).
     *
     * Fold sulla collection di `ricambiMontati()`, per la ragione di
     * `statisticheInterventi()`: nessuna query in più del tab riassunto, e il
     * gate `ricambio_utilizzo.view` è già applicato là (senza permesso la
     * collection è vuota, quindi qui esce zero invece di un dato trapelato —
     * ed è la vista a far sparire il contatore).
     *
     * **`montati` e `inAttesa` sono due numeri e non uno**, ed è la decisione
     * del blocco: `ricambio_utilizzo.data` NULL vuol dire «registrato ma non
     * ancora montato» (ADR-020, la riga che per questo non pesa sul semaforo).
     * Sommarli direbbe che quei pezzi sono sulla macchina; ometterli direbbe
     * che non sono stati registrati. Entrambe le letture sarebbero false, e la
     * seconda porterebbe qualcuno a registrarli una seconda volta.
     *
     * **`copertiDaGaranzia` conta i pezzi COPERTI ADESSO**, non le righe
     * `garanzie` esistenti: una garanzia scaduta l'anno scorso non copre nulla,
     * e chiamarla copertura è esattamente l'errore che il contatore dovrebbe
     * aiutare a evitare. Il confine è `Garanzia::isScaduta()` — la sola
     * definizione del progetto, come impone ADR-024 ("la Panoramica non calcola
     * nulla di suo").
     *
     * ⚠️ La visibilità passa dalla relazione `garanzia`, che `ricambiMontati()`
     * popola **solo** per chi ha titolo a vederla secondo
     * `GaranziaRicambioPolicy::view()` (ability, mai il permesso nudo: spatie
     * registra un `Gate::before` che concede appena il permesso esiste sul
     * ruolo, e scavalcherebbe l'impostazione per-Ente di ADR-029). Per un
     * Tenant di un Ente `nascosta` la relazione è NULL su ogni riga, quindi
     * questo conteggio è naturalmente 0 — e la vista non disegna la riga.
     *
     * @param  Collection<int, RicambioUtilizzo>  $ricambi
     * @return array{montati:int, inAttesa:int, copertiDaGaranzia:int}
     */
    private function statisticheRicambi(Collection $ricambi): array
    {
        $montati = $ricambi->filter(fn (RicambioUtilizzo $r) => $r->data !== null);

        return [
            'montati' => $montati->count(),
            'inAttesa' => $ricambi->count() - $montati->count(),
            'copertiDaGaranzia' => $montati
                ->filter(fn (RicambioUtilizzo $r) => $r->garanzia !== null && ! $r->garanzia->isScaduta())
                ->count(),
        ];
    }
}

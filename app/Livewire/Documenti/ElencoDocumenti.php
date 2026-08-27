<?php

namespace App\Livewire\Documenti;

use App\Enums\TipoDocumento;
use App\Models\Documento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Documenti\FiltroDocumenti;
use App\Support\Tenancy\AccessoTecnico;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Archivio documentale d'Ente — l'elenco cross-macchina (🔗 ERD §8.1,
 * ADR-026/031, Elenco Funzionalità §4 «Gestione Documentale: area dedicata»).
 *
 * **Perché una rotta a sé e non un tab.** Sono due domande diverse: il tab
 * Documenti della scheda parte da *questa macchina* e ne mostra gli allegati;
 * questa pagina attraversa il parco e risponde a «dov'è finito il certificato di
 * marzo». È la stessa ragione per cui `/ricambi` è una pagina e non un tab.
 *
 * **Nessun permesso nuovo, e non è una scorciatoia.** `documenti.view` è
 * esattamente «puoi vedere i documenti»; *quali* lo decidono i global scope di
 * `Documento` (`TenantScope` + `DepartmentThroughStrumentoScope` via
 * `strumento_id`), non il permesso. Un `documenti.archivio` sarebbe costato un
 * `db:seed --class=RolesAndPermissionsSeeder` — cioè un `syncPermissions()` che
 * detacha e riattacca dalla config, cancellando ogni personalizzazione fatta da
 * `/piattaforma/ruoli` in entrambe le direzioni — per **non aggiungere nulla**:
 * questa pagina non mostra una sola riga in più di quelle che l'utente già vede
 * macchina per macchina. È la stessa query, senza il `where strumento_id = ?`.
 *
 * **Privacy (Registro dei trattamenti §2): nessuna riga nuova.** Non si tratta
 * un dato che non fosse già trattato — è l'unione del tab Documenti di ogni
 * macchina che l'utente già vede, con gli stessi scope. Ciò che cambia è la
 * *comodità* dell'aggregazione, non il perimetro. Detto qui invece di lasciarlo
 * dedurre.
 *
 * 🔴 **«Per Ente» non vale per il Tecnico, e la pagina lo dice.** Per lui il
 * confine Ente è *sostituito* da «portafoglio ∪ macchine assegnate»
 * (ADR-007/030), quindi questa lista può mescolare i documenti di clienti
 * diversi ordinati per data — e la comodità dell'aggregazione, senza una
 * colonna che nomini l'Ente, diventa la perdita del dato che tiene separati due
 * clienti. La colonna compare **solo** per chi attraversa gli Enti
 * (`AccessoTecnico::siApplica()`): per l'Admin ripeterebbe lo stesso nome su
 * ogni riga, cioè sarebbe rumore, e costerebbe una query in più a ogni render.
 *
 * **In sola lettura, di proposito**: niente upload e niente eliminazione. Quei
 * due gesti restano nel tab della scheda perché lì il soggetto (macchina o
 * intervento) è già scelto; un'azione Livewire che accetta un id di documento
 * fuori dal contesto della macchina sarebbe una **seconda superficie di
 * autorizzazione** da tenere allineata alla prima — e la seconda definizione è
 * quella che diverge. `documenti.upload` e `documenti.delete` non compaiono qui.
 *
 * ⛔ **Nessuna chiamata a `Documento::esisteSulDisco()` in questa pagina.** È
 * `Storage::disk('documenti')->exists()`, cioè una richiesta di rete a Backblaze
 * **per riga**: venticinque round-trip dentro un render, invisibili in sviluppo
 * perché il disco locale risponde in microsecondi. Il controllo di esistenza
 * vive in `ScaricaDocumento`, al momento del download, dov'è uno solo.
 */
#[Layout('components.layouts.app')]
class ElencoDocumenti extends Component
{
    use WithPagination;

    /** Valore di `TipoDocumento`, o `''` per tutti. */
    #[Url]
    public string $tipo = '';

    /**
     * Id della macchina, fra quelle che hanno almeno un documento visibile.
     *
     * ⚠️ **Il valore si passa a `FiltroDocumenti` così com'è, senza un secondo
     * controllo qui.** La coercizione di Livewire su una property `?int` accetta
     * `-5`, `+5` e `3.0`, e per un po' la porta del foglio li scartava invece
     * con `ctype_digit()`: per la **stessa** URL lo schermo scriveva «Nessun
     * documento con questi filtri» e il foglio stampava l'intero archivio
     * annunciando «Nessun filtro». La soglia è stata riportata dove doveva
     * stare — una sola, dentro `FiltroDocumenti::idMacchina()` — e un `(int)` o
     * un `if` in più scritti qui la spaccherebbero di nuovo in due.
     */
    #[Url]
    public ?int $strumentoId = null;

    /** Estremi sulla **data di caricamento**, `Y-m-d`, inclusivi entrambi. */
    #[Url]
    public string $dal = '';

    #[Url]
    public string $al = '';

    /** Testo libero sul nome del file. */
    #[Url]
    public string $cerca = '';

    /**
     * Righe per pagina: una **costante**, non una `#[Url]`.
     *
     * ⚠️ La superficie `?perPage=999999` semplicemente non esiste — è la
     * cicatrice di `ElencoStrumenti`, dove la property era esposta e andava
     * validata contro una whitelist a ogni render. Il modo più sicuro di
     * validare un input è non accettarlo.
     */
    private const PER_PAGE = 25;

    public function updatingTipo(): void
    {
        $this->resetPage();
    }

    public function updatingStrumentoId(): void
    {
        $this->resetPage();
    }

    public function updatingDal(): void
    {
        $this->resetPage();
    }

    public function updatingAl(): void
    {
        $this->resetPage();
    }

    public function updatingCerca(): void
    {
        $this->resetPage();
    }

    /**
     * Le voci del select «Tipo».
     *
     * Stessa forma di `ManagesDocumentiStrumento::tipiDocumento()`: l'enum è
     * l'unica fonte delle etichette (ADR-021), e una copia scritta a mano qui
     * divergerebbe alla prima voce nuova.
     *
     * @return array<string, string>
     */
    public function tipiDocumento(): array
    {
        return collect(TipoDocumento::cases())
            ->mapWithKeys(fn (TipoDocumento $t) => [$t->value => $t->label()])
            ->all();
    }

    public function render(): View
    {
        // ⚠️ Ridondante col `can:documenti.view` della rotta, e voluto:
        // `Livewire::test()` non esegue i middleware, quindi senza questa riga
        // il 403 non sarebbe provabile da lì. Regge anche il giorno in cui la
        // pagina avesse un'azione — che gira **prima** di `render()`, e con
        // `skipRender()` non ci arriverebbe affatto: è per quello che il `can:`
        // di rotta resta comunque. Idioma di `VistaPiattaforma` nel registro.
        Gate::authorize('documenti.view');

        $filtro = new FiltroDocumenti(
            tipo: $this->tipo ?: null,
            // Grezzo: normalizza `FiltroDocumenti`, esattamente come per il
            // foglio. Un `(int)` qui sarebbe la seconda definizione.
            strumentoId: $this->strumentoId,
            dal: $this->dal ?: null,
            al: $this->al ?: null,
            cerca: $this->cerca ?: null,
        );

        // ⚠️ `Documento::query()` porta già i tre scope — tenant, sotto-albero
        // via `strumento_id`, soft delete — e la pagina non ne riscrive
        // **nessuno**. Niente `where('tenant_id', …)` «per sicurezza»: sarebbe
        // una seconda definizione del confine.
        $documenti = $filtro
            ->applica(Documento::query()->with([
                // Le tre relazioni della riga, o sono tre query per documento:
                // con venticinque righe, settantacinque round-trip.
                'strumento:id,nome,matricola',
                'caricatoBy:id,name',
                'documentabile',
            ]))
            ->orderByDesc('created_at')
            // 🔴 **Il tie-break non è prudenza.** I documenti caricati nella
            // stessa richiesta hanno `created_at` identico al secondo, quindi
            // qui i pari sono la norma. A parità di chiave l'ordine fra due
            // pagine è una proprietà del motore: SQLite scansiona in modo
            // stabile, Postgres può riordinare i pari fra la query di pagina 1 e
            // quella di pagina 2, e una riga esce da **entrambe**. Su un
            // archivio, un documento che non compare in nessuna pagina è
            // precisamente ciò che non deve succedere — e in locale non si vede.
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->onEachSide(1);

        // Le opzioni del filtro macchina: **solo quelle che hanno davvero un
        // documento**, non l'intero parco (su un Ente reale, migliaia di voci).
        // Un'opzione che porta a zero risultati è un filtro che può solo
        // frustrare. Entrambe le query sono scopate — `whereIn` con un Eloquent
        // builder passa da `toBase()`, che applica i global scope alla
        // sottoquery — quindi nessun bypass, e costa una query costante.
        //
        // 🔴 **`withTrashed()`, e toglie una sola definizione di troppo.** I
        // documenti di una macchina cestinata **restano in elenco** — è la scelta
        // di `AccessibleStrumenti`, che il `SoftDeletingScope` non ce l'ha — ma
        // `Strumento::query()` sì: senza questa riga l'elenco mostrava le righe
        // di quella macchina mentre il `<select>` non ne aveva più l'opzione,
        // quindi con `?strumentoId=7` in URL il browser ripiegava sulla prima
        // voce e scriveva «Tutte» su una vista filtrata a una macchina sola —
        // un controllo che dice il contrario di ciò che la pagina sta facendo.
        // Le opzioni si prendono quindi dalla **stessa** definizione dell'elenco;
        // la sottoquery resta il confine, e una macchina senza documenti
        // visibili non compare comunque, cestinata o no.
        $macchine = Strumento::query()
            ->withTrashed()
            ->whereIn('id', Documento::query()->select('strumento_id'))
            ->orderBy('nome')
            ->orderBy('id')
            // `deleted_at` serve a `trashed()` nella vista: senza, l'etichetta
            // non saprebbe distinguere una macchina cestinata da una viva.
            ->get(['id', 'nome', 'matricola', 'deleted_at']);

        // 🔴 **La colonna Ente, e solo per chi ne attraversa più di uno.** Per il
        // Tecnico `TenantScope` non applica il confine Ente ma l'unione di
        // ADR-030, quindi la pagina può mescolare clienti diversi. Le etichette
        // si prendono da una query **scopata** (nessun `withoutGlobalScopes()`
        // «per comodità»): un Ente raggiunto solo da una macchina assegnata e
        // fuori portafoglio non è visibile come nodo — è l'esposizione minima
        // dichiarata in ADR-030 — e la riga lo dice invece di inventarne il nome.
        $mostraEnte = AccessoTecnico::siApplica();

        $enti = $mostraEnte
            ? UnitaOrganizzativa::query()
                ->whereIn('id', $documenti->pluck('tenant_id')->unique()->filter()->all())
                ->pluck('nome', 'id')
                ->all()
            : [];

        return view('livewire.documenti.elenco-documenti', [
            'documenti' => $documenti,
            'macchine' => $macchine,
            'mostraEnte' => $mostraEnte,
            'enti' => $enti,
            // Dopo `applica()`, che è ciò che lo valorizza.
            'filtriApplicati' => $filtro->haRistretto(),
            'parametriExport' => $filtro->parametri(),
        ]);
    }
}

<?php

namespace App\Livewire\Piattaforma;

use App\Models\Errore;
use App\Models\OccorrenzaErrore;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 🔴 L'error tracker interno — la scheda di una issue (S6).
 *
 * 🔗 `docs/Architettura/Error Tracker Interno (piano).md`, ADR-016 (RBAC),
 * ADR-018. È il dettaglio di `Errori`: lo stack trace, e le occorrenze
 * **conservate** con il contesto di ciascuna.
 *
 * ## Perché una rotta propria, e non un dettaglio espanso nell'elenco
 *
 * Non è simmetria con `RegistroAudit`, che il dettaglio lo espande davvero
 * dentro la tabella: qui le occorrenze sono **paginate**, e `WithPagination` ha
 * un `page` solo. Nello stesso componente le due paginazioni collidono — «pagina
 * 2» delle occorrenze sposterebbe anche l'elenco che le contiene, e nessuna
 * delle due schermate direbbe più cosa sta mostrando. È la stessa collisione
 * meccanica per cui il registro di audit non è un tab della cabina
 * (`ElencaClienti` dichiara già le proprie `#[Url]`).
 *
 * In più la proprietà di sicurezza è **per-URL**: questa rotta ha il proprio
 * `can:`, il proprio 403 e il proprio assert strutturale sui middleware. Un
 * dettaglio dentro l'elenco erediterebbe il gate del genitore, e il giorno in
 * cui qualcuno spostasse la scheda altrove se lo lascerebbe dietro.
 *
 * ## 🔴 Le tre azioni, e perché stanno QUI e non nell'elenco
 *
 * `risolvi()`, `ignora()` e `riapri()` sono i soli metodi di scrittura
 * dell'error tracker, e vivono sulla **scheda**: è la pagina in cui si è appena
 * letto lo stack trace, l'input e chi c'era: cioè il posto in cui si sa
 * abbastanza per decidere. Nell'elenco la stessa fila di pulsanti chiederebbe di
 * zittire una issue **senza averla aperta** — e `ignorato` è l'unico interruttore
 * di silenzio del tracker, il solo stato che non si riapre mai da sé.
 *
 * Ne segue anche una proprietà di sicurezza: le scritture sono su **una** rotta
 * sola, con un gate solo da provare, invece che su due superfici in cui
 * ricordarsi due volte della stessa guardia. `Errori` resta in sola lettura.
 *
 * **Niente modale di conferma**, a differenza dell'editor dei permessi: là il
 * gesto ricade su tutti gli utenti di un ruolo e si disfa a fatica, qui ricade su
 * una riga di diagnostica, è reversibile con un click dalla stessa pagina, e
 * **ogni gesto lascia la propria riga** nel registro. Una conferma qui sarebbe un
 * ostacolo senza una decisione dentro.
 *
 * ⚠️ **Nessuna delle tre fa `skipRender()`**, ed è deliberato: dopo il gesto la
 * scheda deve mostrare il badge nuovo e i due pulsanti che ora hanno senso. La
 * conseguenza va però detta per intero, perché è la trappola che questo blocco ha
 * pagato: `render()` gira **dopo** l'azione, quindi il suo `Gate::authorize()`
 * risponderebbe 403 **a scrittura già avvenuta**. Un `assertForbidden()` su una
 * chiamata Livewire non è quindi prova di rifiuto — il rifiuto si asserisce sul
 * **dato** (`$errore->fresh()->stato` e il conteggio delle righe di audit), ed è
 * ciò che fa `AzioniErroriTest`.
 *
 * Da cui il `Gate::authorize()` **in testa a ogni azione**, che non è ridondante
 * rispetto al `can:` di rotta: quello è la guardia larga e vive in
 * `routes/web.php` — un file che si modifica per ragioni che con questa pagina
 * non c'entrano — questo vive accanto al gesto ed è l'unico che regge sul
 * montaggio diretto, dove `Livewire::test()` **disabilita i middleware**.
 *
 * ⚠️ Il resto della pagina resta in sola lettura: i sette metodi di
 * `WithPagination` sono pubblici e raggiungibili, innocui ma esistenti, e il
 * dettaglio della singola occorrenza si apre con un `<details>` **nativo** e non
 * con un `wire:click` — niente round-trip, e la pagina resta leggibile anche
 * senza JavaScript. Una schermata che si consulta quando l'applicazione sta già
 * andando male è il posto sbagliato per dipendere da altro codice.
 *
 * ## Il gate, di nuovo e per intero
 *
 * `Gate::authorize()` in testa a **questo** `render()` e non solo su quello
 * dell'elenco: `Livewire::test(SchedaErrore::class, ['errore' => $e])` monta
 * questo componente **senza middleware**, quindi il `can:` di rotta non lo
 * incontrerebbe mai. I due test esistono in coppia apposta — quello di rotta
 * resta verde togliendo questa riga, quello sul montaggio diretto no, ed è
 * l'unico modo di sapere quale delle due guardie sta effettivamente rispondendo.
 *
 * ⚠️ Il permesso si legge da `Errori::PERMESSO` e non si riscrive: elenco e
 * scheda mostrano lo stesso dato, e due stringhe uguali in due file sono due
 * occasioni di gatarle su permessi diversi — cioè di lasciare la scheda aperta
 * a chi l'elenco rifiuta, per la strada che nessuno prova (l'URL diretto).
 *
 * ## Una issue può sparire mentre qualcuno ha il link aperto
 *
 * La potatura del blocco 7 cancella issue chiuse dopo 90 giorni e aperte dopo
 * 180. Il route-model binding risponde **404** su un id che non esiste più, ed è
 * il verso giusto: la pagina non deve inventare una scheda vuota per una riga
 * che non c'è.
 *
 * ⚠️ **Il file sta in `app/Livewire/Piattaforma/`, non in una sottocartella**:
 * `LocalizzazioneTest:77` globba `Livewire/**\/*.php` e in PHP `**` non è
 * ricorsivo — a profondità 3 questo file sfuggirebbe al meta-test in silenzio.
 */
#[Layout('components.layouts.app')]
class SchedaErrore extends Component
{
    use WithPagination;

    /**
     * La issue, dal route-model binding.
     *
     * `#[Locked]` esplicito: Livewire 3 blocca già da sé le property Eloquent,
     * ma qui la property **è** l'oggetto dell'autorizzazione — se il browser
     * potesse cambiarne l'id, il `can:` di rotta starebbe proteggendo un URL e
     * la pagina ne mostrerebbe un altro. Scriverlo costa una riga e toglie la
     * necessità di ricordarsi di una difesa del framework.
     */
    #[Locked]
    public Errore $errore;

    /**
     * Dieci e non venticinque: il tetto di contesti per issue è **venti**
     * (`config('easylab.errori.contesti_per_errore')`), quindi con venticinque
     * la paginazione non si presenterebbe mai — cioè non esisterebbe, e sarebbe
     * scoperta il giorno in cui qualcuno alza il tetto.
     */
    private const PER_PAGE = 10;

    public function mount(Errore $errore): void
    {
        $this->errore = $errore;
    }

    /**
     * «Credo di averlo sistemato».
     *
     * Non decide niente: lo stato, i timestamp e la riga di audit stanno tutti in
     * `Errore::risolvi()`, che è un metodo del **model** e non di un service — il
     * meta-test dei soggetti audit cerca le `activity()` leggendo il sorgente dei
     * model, e da un service non le vedrebbe.
     */
    public function risolvi(): void
    {
        Gate::authorize(Errori::PERMESSO);

        $this->errore->risolvi($this->chiAgisce());
    }

    /**
     * «So che c'è e non me ne importa»: l'unico interruttore di silenzio.
     *
     * ⚠️ È l'unico stato che **non si riapre mai** da solo, ed è la ragione per
     * cui questi pulsanti stanno sulla scheda e non nell'elenco: zittire una issue
     * senza averla aperta è il gesto che poi non si ricorda di aver fatto.
     */
    public function ignora(): void
    {
        Gate::authorize(Errori::PERMESSO);

        $this->errore->ignora($this->chiAgisce());
    }

    /** «Non è sistemato»: la issue torna in elenco, col budget dei contesti azzerato. */
    public function riapri(): void
    {
        Gate::authorize(Errori::PERMESSO);

        $this->errore->riapri($this->chiAgisce());
    }

    /**
     * Chi sta compiendo il gesto.
     *
     * ⚠️ Il `?User` di `auth()->user()` si restringe **dopo** il gate e non prima:
     * `Gate::authorize()` su un permesso nudo nega a un utente non autenticato,
     * quindi qui dentro non si arriva mai da ospiti. Scriverlo in un metodo solo
     * evita di ripetere tre volte una restrizione che vale per la stessa ragione.
     *
     * Durante un'impersonazione questo è l'utente **impersonato** — è quello che
     * la guard restituisce — e va bene così: chi c'era davvero dietro il gesto lo
     * dice `properties.impersonato_da`, timbrato dall'hook di
     * `AppServiceProvider` su ogni riga scritta dentro una richiesta.
     */
    private function chiAgisce(): User
    {
        $chi = auth()->user();

        if (! $chi instanceof User) {
            // Irraggiungibile passando dal gate qui sopra, e quindi **fail-closed
            // e rumoroso** invece di un `return` silenzioso: se un domani
            // qualcuno chiamasse queste azioni da un'altra strada, il gesto non
            // deve finire in `errori` con un autore inventato.
            abort(403);
        }

        return $chi;
    }

    public function render(): View
    {
        // Vedi il docblock: qui e non solo sulla rotta, o il montaggio diretto
        // non incontra nessuna guardia.
        Gate::authorize(Errori::PERMESSO);

        $occorrenze = $this->errore->occorrenzeErrore()
            // Dalla più recente, come l'elenco. Il tie-break sull'id per la
            // ragione di sempre: `avvenuta_at` è al secondo, e un loop caldo ne
            // mette più d'una nello stesso istante.
            ->orderByDesc('avvenuta_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->onEachSide(1);

        // 🔴 **`user_id` e `impersonato_da` insieme, in una query sola**, e per
        // due ragioni diverse. La prima è il costo: una query per occorrenza
        // sarebbe un N+1 su una pagina che ne mostra dieci.
        //
        // ⚠️ La seconda è che **`impersonato_da` può non risolvere**. `user_id`
        // è una FK `nullOnDelete` — o c'è o è `null` — mentre `impersonato_da` è
        // una colonna **nuda**, senza vincolo, scelta così perché sta sul
        // percorso caldo dentro il gestore delle eccezioni. E `users` **non ha
        // soft delete** (verificato: nessun `deleted_at`, nessun trait), quindi
        // quell'id può puntare a un utente cancellato davvero. La `pluck` non
        // lo trova, e la vista stampa il numero invece di cadere: chi legge deve
        // sapere che qualcuno c'era, anche quando non se ne può più dire il nome.
        $utenti = User::query()
            ->whereIn('id', $occorrenze->getCollection()
                ->flatMap(fn (OccorrenzaErrore $o): array => [$o->user_id, $o->impersonato_da])
                ->filter()
                ->unique()
                ->values())
            ->pluck('name', 'id');

        return view('livewire.piattaforma.scheda-errore', [
            'occorrenze' => $occorrenze,
            'utenti' => $utenti,
        ]);
    }
}

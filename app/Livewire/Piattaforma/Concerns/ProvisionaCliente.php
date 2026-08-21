<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Models\Account;
use App\Support\Piani;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Provisioning\ProvisioningRifiutato;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Support\Facades\Gate;

/**
 * La quarta leva: creare un cliente, o una sede in più (S6 — ADR-012, ADR-032).
 *
 * Chiude l'ultima riga aperta della DoD di S5, «Free chiavi in mano»: finora un
 * cliente nuovo nasceva solo da `easylab:provision-tenant`, cioè da un
 * terminale con accesso al server.
 *
 * **Non riscrive niente.** L'orchestrazione — risoluzione dell'account, limite
 * di piano prima della transazione, transazione, invito fuori — vive in
 * `App\Support\Provisioning\ProvisionaEnte`, estratta apposta nel blocco
 * precedente e già coperta dai test del comando. Qui c'è un form e la
 * traduzione dei rifiuti in errori di campo.
 *
 * ⚠️ **Nessun select dei piani, ed è una decisione.** I piani a pagamento
 * passano da Stripe (`easylab:abbona`), quindi un menù a tendina offrirebbe
 * un'opzione che fallirebbe al salvataggio — o, peggio, creerebbe un account
 * marcato `saas` senza subscription, cioè un cliente che risulta pagante e non
 * paga. Nasce **Free**, e la pagina lo dice invece di lasciarlo scoprire.
 *
 * ⚠️ **Due ability e non una.** `tenants.provision` apre il gesto; se si
 * aggancia una sede a un **account esistente** serve anche `manage` su
 * quell'account, perché aggiungere una sede consuma uno slot del suo piano —
 * cioè tocca il suo contratto.
 */
trait ProvisionaCliente
{
    public bool $provisioningAperto = false;

    /** L'account a cui agganciare la sede nuova; `null` = nasce un cliente nuovo. */
    public ?int $provisioningAccount = null;

    /** @var array<string,string> */
    public array $nuovo = [
        'nome' => '',
        'adminEmail' => '',
        'adminName' => '',
    ];

    private const CAMPI_NUOVO = ['nome', 'adminEmail', 'adminName'];

    public function apriProvisioning(?int $accountId = null): void
    {
        Gate::authorize('tenants.provision');

        if ($accountId !== null) {
            $account = VistaPiattaforma::accounts()->whereKey($accountId)->firstOrFail();
            Gate::authorize('manage', $account);
        }

        $this->nuovo = array_fill_keys(self::CAMPI_NUOVO, '');
        $this->resetValidation();

        // Una modale alla volta. Il componente è il solo che le conosce tutte.
        $this->chiudiOgniModale();

        $this->provisioningAccount = $accountId;
        $this->provisioningAperto = true;
    }

    public function chiudiProvisioning(): void
    {
        $this->provisioningAperto = false;
        $this->provisioningAccount = null;
        $this->nuovo = array_fill_keys(self::CAMPI_NUOVO, '');
        $this->resetValidation();
    }

    /**
     * Crea l'Ente, il suo Account (o lo aggancia a quello scelto) e l'Admin, e
     * lo invita.
     *
     * ⚠️ **L'autorizzazione si rifà qui**, e non ci si appoggia ad
     * `apriProvisioning()`: `provisioningAccount` è una property pubblica, quindi
     * il form si può apparecchiare su un account e inviare su un altro senza
     * passare dall'apertura. È lo stesso difetto che il confronto ha trovato
     * sulle tre leve precedenti — lì le azioni che scrivevano non rifacevano il
     * controllo, e la suite restava verde togliendolo.
     *
     * I rifiuti di dominio diventano **errori di campo** e non eccezioni: «il
     * piano non consente un'altra sede» è una cosa che l'operatore deve leggere
     * accanto al form, non una pagina di errore.
     */
    public function creaCliente(): void
    {
        Gate::authorize('tenants.provision');

        $account = null;

        if ($this->provisioningAccount !== null) {
            $account = VistaPiattaforma::accounts()->whereKey($this->provisioningAccount)->firstOrFail();
            Gate::authorize('manage', $account);
        }

        // ⚠️ **Si normalizza prima di validare.** Un'email incollata con uno
        // spazio in coda — il caso più comune di tutti — verrebbe rifiutata dalla
        // regola `email` con un messaggio che non dice cosa c'è di sbagliato,
        // perché a occhio l'indirizzo è giusto. E il minuscolo va imposto qui e
        // non al salvataggio: `ProvisionaEnte` cerca l'utente per email, quindi
        // due maiuscole in più creerebbero un **secondo utente per la stessa
        // persona** senza che nulla se ne accorga.
        // `is_string` e non solo `?? ''`: `nuovo` è un array pubblico, quindi un
        // update può consegnare un array annidato — e `trim(array)` è un
        // `TypeError`, cioè un 500 al posto di un errore di validazione. È la
        // variante «valore non scalare» del difetto che `salvaFiscali()` ha già
        // chiuso per le chiavi mancanti.
        $pulito = fn (string $campo) => is_string($this->nuovo[$campo] ?? null) ? trim($this->nuovo[$campo]) : '';

        $this->nuovo['nome'] = $pulito('nome');
        $this->nuovo['adminName'] = $pulito('adminName');
        $this->nuovo['adminEmail'] = mb_strtolower($pulito('adminEmail'));

        $dati = $this->validate([
            'nuovo.nome' => ['required', 'string', 'min:2', 'max:255'],
            'nuovo.adminEmail' => ['required', 'email', 'max:255'],
            'nuovo.adminName' => ['required', 'string', 'min:2', 'max:255'],
        ], [], [
            'nuovo.nome' => 'nome della sede',
            'nuovo.adminEmail' => 'email dell\'amministratore',
            'nuovo.adminName' => 'nome dell\'amministratore',
        ])['nuovo'];

        try {
            $esito = (new ProvisionaEnte(
                nome: $dati['nome'],
                adminEmail: $dati['adminEmail'],
                adminName: $dati['adminName'],
                accountId: $account?->id,
                // 🔴 Il flag che rende **diversi** i due gesti della pagina. Senza,
                // «Nuovo cliente» con l'email di qualcuno che amministra già un
                // contratto agganciava la sede a **quel** contratto — l'account di
                // piattaforma compreso — senza chiedere `manage` e senza passare
                // dalla porta, mostrando comunque un messaggio di successo.
                esigiAccountNuovo: $account === null,
            ))->esegui();
        } catch (ProvisioningRifiutato $rifiuto) {
            // Il messaggio è già in italiano e già destinato a un umano: si
            // mostra, non si reinterpreta.
            $this->addError('nuovo.nome', $rifiuto->getMessage());

            return;
        }

        $this->chiudiProvisioning();

        // ⚠️ **La riga nuova si va a cercare, non si spera.** La prima stesura
        // faceva solo `resetPage()` col commento «una creazione che non si vede
        // si ripete» — ma con l'ordinamento per ragione sociale il cliente appena
        // creato cade dove capita, e tornare a pagina 1 lo rende *meno*
        // probabile da vedere, non più; con un filtro attivo la pagina diceva
        // «Nessun cliente con questi filtri» sotto il messaggio di successo.
        // Si filtra sul cliente appena toccato: è l'unica cosa che garantisce
        // che si veda, e mostra esattamente ciò che si è fatto.
        $this->search = $esito->account->ragione_sociale;
        $this->piano = '';
        $this->stato = '';
        $this->resetPage();

        // ⚠️ **Si dice tutto ciò che l'esito porta.** Leggendo il solo
        // `invitoAccodato` (allora `invitoInviato`), un invito **fallito** dava lo stesso
        // messaggio del caso legittimo «l'utente esisteva già ed è attivo,
        // nessun invito da mandare»: l'operatore chiudeva convinto, e
        // l'amministratore aspettava una mail che non sarebbe arrivata. Il
        // comando in console avvisa da sempre — la UI era una regressione
        // rispetto all'altro chiamante.
        $cosa = $esito->accountNuovo
            ? "Cliente «{$esito->account->ragione_sociale}» creato"
            : "Sede «{$esito->ente->nome}» aggiunta a «{$esito->account->ragione_sociale}»";

        session()->flash('provisioning', match (true) {
            $esito->invitoFallito !== null => "{$cosa}, ma l'invito a {$esito->admin->email} NON è partito ({$esito->invitoFallito}): ripetere il gesto per riprovare.",
            // ⚠️ «In consegna» e non «inviato». La notifica è accodata: da qui
            // non si sa se partirà, e affermarlo sarebbe una bugia che nessuno
            // può più smentire guardando questa pagina. Un invito che poi non
            // parte lascia una riga nell'audit — `InvitoUtente::failed()`.
            $esito->invitoAccodato => "{$cosa}. Invito in consegna a {$esito->admin->email}.",
            default => "{$cosa} per {$esito->admin->email}, che ha già un accesso attivo.",
        });

        session()->flash('provisioningFallito', $esito->invitoFallito !== null);
    }

    /** Il cliente a cui si sta aggiungendo una sede, per il titolo della modale. */
    public function clienteDelProvisioning(): ?Account
    {
        if ($this->provisioningAccount === null || ! Gate::allows('tenants.provision')) {
            return null;
        }

        $account = VistaPiattaforma::accounts()->find($this->provisioningAccount);

        // La **stessa** ability dell'azione, richiesta anche in lettura: la
        // modale mostra ragione sociale, piano e conteggio sedi di un cliente.
        //
        // ⚠️ **Nessun test può renderla rossa, ed è dichiarato.** `tenants.provision`
        // ce l'hanno solo Developer e Superadmin, che hanno entrambi
        // `billing.manage_global` — quindi `manage` è sempre vero e togliendo
        // questa riga la suite resta verde. Non è codice morto: è la riga che
        // regge il giorno in cui un ruolo «commerciale» avrà il provisioning e
        // non il billing globale. Un test che la coprisse dovrebbe costruire un
        // ruolo che non esiste, cioè congelare un'ipotesi invece di una regola.
        return $account !== null && Gate::allows('manage', $account) ? $account : null;
    }

    /**
     * L'altra strada per puntare la modale su un account: la property.
     *
     * Senza questo hook, un id di piattaforma o cestinato faceva tornare `null`
     * da `clienteDelProvisioning()` e la modale si intitolava «Nuovo cliente»
     * mostrando «nasce sul piano Free» — mentre l'invio sarebbe finito in
     * `ModelNotFoundException`. La modale diceva una cosa e l'azione ne faceva
     * un'altra.
     */
    public function updatingProvisioningAccount(mixed $valore): void
    {
        if ($valore !== null) {
            Gate::authorize('tenants.provision');

            $account = VistaPiattaforma::accounts()->whereKey($valore)->firstOrFail();

            Gate::authorize('manage', $account);
        }
    }

    /** Quanti Enti consente il piano di quel cliente, per dirlo nel form. */
    public function slotDelPiano(Account $account): string
    {
        if (! Piani::esiste($account->piano)) {
            return 'piano fuori catalogo';
        }

        $max = Piani::maxEnti($account->piano);

        return $account->enti()->count().' / '.($max ?? '∞');
    }
}

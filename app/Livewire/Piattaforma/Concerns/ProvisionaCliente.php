<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Models\Account;
use App\Notifications\PropostaPiano;
use App\Support\Piani;
use App\Support\Provisioning\EsitoProvisioning;
use App\Support\Provisioning\PianiDiNascita;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Provisioning\ProvisioningRifiutato;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

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
 * ## 🔴 Il select dei piani c'è dal 3 Ott 2026, e NON marca nessuno pagante
 *
 * Fino ad allora la modale non offriva scelta, e la ragione era scritta qui:
 * un menù avrebbe creato un account marcato `saas` senza subscription, cioè un
 * cliente che risulta pagante e non paga — «il select arriva col flusso di
 * sottoscrizione, non col listino». Il flusso ora esiste (ADR-045: il cliente
 * attiva il piano dalla propria pagina «Abbonamento»), e il select è arrivato
 * con lui. **La ragione però regge ancora, ed è ciò che il select rispetta**:
 *
 * - un piano **gratuito** si assegna, e il cliente nasce su quello;
 * - un piano **a pagamento** si **propone**: il cliente nasce sul piano
 *   predefinito, `accounts.piano_proposto` dice cosa gli è stato chiesto di
 *   attivare, e una mail lo porta a pagarlo. `accounts.piano` lo scrive il
 *   webhook quando Stripe conferma l'incasso, come per ogni altro pagamento.
 *
 * Quali piani si possono scegliere lo decide `PianiDiNascita`, e solo sul
 * **cliente nuovo**: aggiungendo una sede a un account esistente il piano è
 * già quello del contratto, e un campo qui lo cambierebbe di nascosto.
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

    /**
     * ⚠️ **`nome` cambia significato fra i due gesti**, ed è la ragione per cui
     * `nomeSede` esiste solo nel primo: creando un **cliente nuovo** `nome` è la
     * **ragione sociale** dell'Account e `nomeSede` è il nome del suo primo Ente;
     * agganciando una sede a un account **esistente** `nome` È il nome della
     * sede, e un secondo campo chiederebbe due volte la stessa cosa. Le
     * etichette di validazione seguono il ramo, o direbbero il falso in uno dei
     * due (🔗 ADR-032).
     *
     * @var array<string,?string>
     */
    public array $nuovo = [
        'nome' => '',
        'nomeSede' => '',
        'adminEmail' => '',
        'adminName' => '',
        // Vuoto = il piano predefinito. Vale solo sul cliente nuovo (ADR-045).
        'piano' => '',
    ];

    private const CAMPI_NUOVO = ['nome', 'nomeSede', 'adminEmail', 'adminName', 'piano'];

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

        // ⚠️ **Vuoto = non scelto, e si normalizza PRIMA di validare.** Il campo
        // è facoltativo, quindi il caso normale è la stringa vuota: lasciata
        // tale, `min:2` la rifiuterebbe e il form si bloccherebbe su un campo
        // che nessuno era tenuto a compilare. `null` è l'unico valore che
        // `nullable` lascia passare — e più a valle è ciò che `ProvisionaEnte`
        // legge come «tieni la ragione sociale».
        //
        // Dalla **stessa** strada degli altri tre: `nuovo` è un array pubblico,
        // quindi un update può consegnare un array annidato e `trim(array)` è un
        // `TypeError`, cioè un 500 al posto di un errore di validazione.
        $sede = $pulito('nomeSede');
        $this->nuovo['nomeSede'] = $sede !== '' ? $sede : null;

        // 🔴 **Il piano si legge SOLO sul cliente nuovo.** Agganciando una sede a
        // un account esistente la modale non mostra il campo, ma `nuovo` è un
        // array pubblico: onorare qui un valore che l'operatore non ha visto
        // cambierebbe il piano di un contratto in essere. Si azzera prima di
        // validare, così non è nemmeno un errore: semplicemente non conta.
        //
        // Vuoto = non scelto = piano predefinito, come per la sede.
        $piano = $account === null ? $pulito('piano') : '';
        $this->nuovo['piano'] = $piano !== '' ? $piano : null;

        $dati = $this->validate([
            'nuovo.nome' => ['required', 'string', 'min:2', 'max:255'],
            'nuovo.nomeSede' => ['nullable', 'string', 'min:2', 'max:255'],
            'nuovo.adminEmail' => ['required', 'email', 'max:255'],
            'nuovo.adminName' => ['required', 'string', 'min:2', 'max:255'],
            // ⛔ `Rule::in` sulle voci di `PianiDiNascita`, rilette ora: fra
            // l'apertura della modale e l'invio un piano può essere archiviato,
            // e un codice forgiato non è passato da nessuna tendina.
            'nuovo.piano' => ['nullable', 'string', Rule::in(PianiDiNascita::codici())],
        ], [
            'nuovo.piano.in' => 'Il piano scelto non è più fra quelli disponibili: riapri la tendina e scegline uno.',
        ], [
            // L'etichetta dice il vero **nel ramo in cui si sta**: sul cliente
            // nuovo `nome` è la ragione sociale, e chiamarlo «nome della sede»
            // manderebbe l'operatore a correggere il campo sbagliato — accanto
            // a un campo che la sede la chiede davvero.
            'nuovo.nome' => $account === null ? 'ragione sociale' : 'nome della sede',
            'nuovo.nomeSede' => 'nome della prima sede',
            'nuovo.adminEmail' => 'email dell\'amministratore',
            'nuovo.adminName' => 'nome dell\'amministratore',
            'nuovo.piano' => 'piano',
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
                // Solo sul cliente nuovo: agganciando una sede a un account
                // esistente `nome` È già il nome della sede, e passare anche
                // questo significherebbe onorare un campo che la modale non
                // mostra — cioè lasciar decidere il nome del nodo a un valore
                // che l'operatore non ha visto (le property sono pubbliche).
                nomeSede: $account === null ? ($dati['nomeSede'] ?? null) : null,
            ))->esegui();
        } catch (ProvisioningRifiutato $rifiuto) {
            // Il messaggio è già in italiano e già destinato a un umano: si
            // mostra, non si reinterpreta.
            $this->addError('nuovo.nome', $rifiuto->getMessage());

            return;
        }

        // ⚠️ **DOPO `esegui()` e non prima**, come fa il self-signup pubblico:
        // l'account deve esistere, e il limite di piano del provisioning gira
        // su un account che ancora non c'è.
        $notaPiano = $this->applicaIlPianoScelto($esito, $dati['piano'] ?? null);

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
        }.$notaPiano);

        session()->flash('provisioningFallito', $esito->invitoFallito !== null);
    }

    /**
     * Il piano scelto nella modale, applicato al cliente appena nato (ADR-045).
     * Restituisce la frase da accodare al messaggio per l'operatore, o `''`.
     *
     * 🔴 **Un piano a pagamento NON si scrive su `accounts.piano`.** Si propone:
     * il cliente resta sul piano con cui è nato finché Stripe non conferma
     * l'incasso, e a scrivere il piano vero è il webhook. È la riga che tiene
     * vera la regola di sempre — nessun account pagante senza subscription.
     *
     * ⚠️ **La mail è dentro un `try`, e l'esito si dice**, come per l'invito: il
     * cliente esiste e la proposta è scritta, quindi un SMTP giù non deve
     * trasformare un gesto riuscito in una pagina di errore — ma nemmeno in un
     * «in consegna» che non è vero. Con la coda attiva questo `try` vede solo
     * il fallimento della consegna alla coda; quello successivo lo racconta
     * `PropostaPiano::failed()`, sul registro di audit.
     */
    private function applicaIlPianoScelto(EsitoProvisioning $esito, ?string $piano): string
    {
        // Solo su un account NATO da questo gesto: con `esigiAccountNuovo` è
        // sempre così, ma il piano di un contratto in essere non si tocca
        // nemmeno per errore.
        if ($piano === null || ! $esito->accountNuovo || $piano === $esito->account->piano) {
            return '';
        }

        if (Piani::eGratuito($piano)) {
            $esito->account->cambiaPiano($piano);

            return ' Piano: «'.Piani::etichetta($piano).'».';
        }

        $esito->account->proponiPiano($piano);

        try {
            $esito->admin->notify(new PropostaPiano(
                ragioneSociale: $esito->account->ragione_sociale,
                etichettaPiano: Piani::etichetta($piano),
                importoCent: (int) Piani::importoCorrenteCent($piano),
                destinatario: $esito->admin->email,
            ));

            // ⚠️ «In consegna» e non «inviata», per la stessa ragione dell'invito.
            $consegna = 'in consegna via email';
        } catch (Throwable) {
            $consegna = 'ma la mail che glielo dice NON è partita';
        }

        return ' Piano «'.Piani::etichetta($piano)."» proposto, {$consegna}: il cliente resta su «"
            .Piani::etichetta($esito->account->piano).'» finché non lo attiva dalla sua pagina Abbonamento.';
    }

    /**
     * Le voci del select dei piani, per la modale del cliente nuovo.
     *
     * Dietro la **stessa** ability dell'azione: a chi non può creare clienti
     * non serve sapere cosa si potrebbe scegliere.
     *
     * @return list<array{codice: string, etichetta: string, gratuito: bool, predefinito: bool, importoCent: int|null}>
     */
    public function pianiDiNascita(): array
    {
        return Gate::allows('tenants.provision') ? PianiDiNascita::opzioni() : [];
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

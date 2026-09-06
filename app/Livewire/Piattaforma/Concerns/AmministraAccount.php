<?php

namespace App\Livewire\Piattaforma\Concerns;

use App\Models\Account;
use App\Support\Piattaforma\EliminaCliente;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Support\Facades\Gate;

/**
 * Le due leve che agiscono sull'**Account**: lockout e dati fiscali (S6).
 *
 * Principio unico del blocco: **la UI chiama il gesto di dominio che esiste
 * già**, e non riscrive né la regola né l'audit. `blocca()`, `sblocca()` e il
 * trait `AuditsDomainWrites` sono già stati scritti, testati e verificati su
 * staging dal blocco Cashier: qui si aggiunge una superficie, non una seconda
 * implementazione.
 *
 * ⚠️ **Il lockout sono due interruttori, mai uno.** Quello manuale si scrive;
 * quello di Stripe è **sola lettura**, e `sbloccaPerStripe()` non è esposto in
 * nessuna forma. Il suo inverso è un evento di pagamento: un umano che
 * dichiarasse «ha pagato» verrebbe smentito dal webhook successivo, e nel
 * frattempo il cliente sarebbe rientrato senza pagare. `is_locked` significa
 * «almeno una sorgente accesa» — un interruttore unico in UI ricrea in mano a
 * una persona il difetto che la separazione dei due campi esiste per impedire
 * (ADR-013).
 */
trait AmministraAccount
{
    /** L'account su cui è aperto un pannello, o `null`. */
    public ?int $accountInLavorazione = null;

    /** Quale pannello: `lockout`, `fiscali` o `eliminazione`. Vuoto = nessuno. */
    public string $pannello = '';

    public string $motivoLockout = '';

    /**
     * Ciò che si deve riscrivere per abilitare l'eliminazione (🔗 ADR-040).
     *
     * ⚠️ **Non è teatro.** Protegge dal click sulla riga sbagliata e dal doppio
     * invio, che è il modo in cui questi incidenti accadono davvero — non da un
     * amministratore che non ha capito. Per questo la modale il nome lo
     * **mostra**: nascondere il dato renderebbe il gesto una caccia al tesoro
     * senza renderlo più sicuro.
     */
    public string $confermaEliminazione = '';

    /**
     * Il guasto di un servizio esterno che **non** ha impedito l'eliminazione.
     *
     * ⚠️ Fuori dalla modale, che a quel punto è chiusa: se restasse dentro
     * sparirebbe insieme a lei, e con lui la sola riga che dice quale
     * subscription resta aperta su Stripe.
     */
    public ?string $erroreEliminazione = null;

    /**
     * I campi fiscali, **nominati una volta sola**.
     *
     * ⚠️ Non si ricavano da `array_keys($this->fiscali)`: quella è una property
     * pubblica, cioè esattamente ciò che il browser può consegnare monca. Un
     * elenco derivato dal dato che deve difendere non difende niente.
     */
    private const CAMPI_FISCALI = [
        'ragione_sociale',
        'partita_iva',
        'codice_fiscale',
        'pec',
        'codice_destinatario_sdi',
    ];

    /** @var array<string,string> */
    public array $fiscali = [
        'ragione_sociale' => '',
        'partita_iva' => '',
        'codice_fiscale' => '',
        'pec' => '',
        'codice_destinatario_sdi' => '',
    ];

    public function apriLockout(int $accountId): void
    {
        $account = $this->accountAmministrabile($accountId, 'lockout');

        $this->motivoLockout = '';
        $this->resetValidation();
        $this->chiudiOgniModale();
        $this->accountInLavorazione = $account->id;
        $this->pannello = 'lockout';
    }

    public function apriFiscali(int $accountId): void
    {
        $account = $this->accountAmministrabile($accountId, 'manage');

        $this->fiscali = [
            'ragione_sociale' => (string) $account->ragione_sociale,
            'partita_iva' => (string) $account->partita_iva,
            'codice_fiscale' => (string) $account->codice_fiscale,
            'pec' => (string) $account->pec,
            'codice_destinatario_sdi' => (string) $account->codice_destinatario_sdi,
        ];

        $this->resetValidation();
        $this->chiudiOgniModale();
        $this->accountInLavorazione = $account->id;
        $this->pannello = 'fiscali';
    }

    public function chiudiPannello(): void
    {
        $this->accountInLavorazione = null;
        $this->pannello = '';
        $this->motivoLockout = '';
        // ⚠️ **Anche `fiscali`, e non solo il motivo.** L'asimmetria non era
        // innocua: chiuso il pannello di A e riaperto quello di B via property,
        // i dati fiscali di A restavano in memoria e finivano su B al primo
        // salvataggio. Chi lo fa è autorizzato su entrambi, quindi non è
        // un'escalation — è però esattamente «scrittura sulla riga sbagliata»,
        // e la riga che la impedisce esisteva già per l'altro campo.
        $this->fiscali = array_fill_keys(self::CAMPI_FISCALI, '');
        $this->resetValidation();
    }

    /**
     * Chiude la porta a mano (ADR-013).
     *
     * Il motivo è **obbligatorio** e non è burocrazia: è ciò che l'account
     * bloccato NON vedrà — `/bloccato` mostra apposta una pagina muta — ma che
     * chiunque riapra questa schermata fra sei mesi deve poter leggere per
     * sapere se il blocco è ancora dovuto. Un lockout senza motivo si trasforma
     * in un cliente dimenticato.
     */
    public function bloccaAccount(): void
    {
        $account = $this->accountAmministrabile($this->accountInLavorazione, 'lockout');

        // Si normalizza **prima** di validare: `min:5` su `'  ab  '` passerebbe
        // (sei caratteri) e a DB finirebbe `'ab'`, cioè un motivo più corto del
        // minimo che la regola esiste per garantire.
        $this->motivoLockout = trim($this->motivoLockout);

        $this->validate(
            ['motivoLockout' => ['required', 'string', 'min:5', 'max:255']],
            ['motivoLockout.required' => 'Serve un motivo: è ciò che si legge quando si riapre il caso.'],
        );

        $account->blocca($this->motivoLockout);

        $this->chiudiPannello();
    }

    /**
     * Riapre la **sola** porta manuale.
     *
     * Se Stripe tiene ancora chiuso per insoluto, l'account resta chiuso: è
     * `sblocca()` a deciderlo, e non questa UI — la regola vive nel dominio,
     * qui c'è solo il pulsante.
     */
    public function sbloccaAccount(): void
    {
        $account = $this->accountAmministrabile($this->accountInLavorazione, 'lockout');

        $account->sblocca();

        $this->chiudiPannello();
    }

    /**
     * Salva i dati fiscali.
     *
     * I campi sono già in `$fillable` e il trait `AuditsDomainWrites` traccia da
     * sé: nessun `activity()` qui, o il guardrail trait-vs-esplicita diventa
     * rosso — e giustamente, perché due tracciamenti sullo stesso gesto
     * producono due righe che raccontano la stessa cosa in due modi.
     *
     * ⚠️ **Gap dichiarato**: `stripeMetadata()` non viene riallineato adesso. Si
     * riallinea alla prossima operazione Cashier, perché da un ciclo di render
     * non si chiama la rete — un timeout di Stripe farebbe fallire il
     * salvataggio di un dato che a DB era già scritto.
     */
    public function salvaFiscali(): void
    {
        $account = $this->accountAmministrabile($this->accountInLavorazione, 'manage');

        $dati = $this->validate([
            'fiscali.ragione_sociale' => ['required', 'string', 'max:255'],
            'fiscali.partita_iva' => ['nullable', 'string', 'max:20'],
            'fiscali.codice_fiscale' => ['nullable', 'string', 'max:20'],
            'fiscali.pec' => ['nullable', 'email', 'max:255'],
            // ⚠️ **Sei caratteri o sette, e non «da 6 a 7»**: sono due codici
            // diversi con lo stesso nome. Sei = Pubblica Amministrazione (codice
            // univoco IPA), sette = privati (codice destinatario SDI). Un
            // `between:6,7` accetterebbe entrambi e nessuno dei due, cioè
            // farebbe passare una lunghezza che non esiste in nessuno dei due
            // sistemi. Nessun checksum: lo SDI non ne pubblica uno.
            'fiscali.codice_destinatario_sdi' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{6}$|^[A-Za-z0-9]{7}$/'],
        ], [
            'fiscali.codice_destinatario_sdi.regex' => 'Il codice destinatario è di 6 caratteri (PA) o 7 (privati).',
        ])['fiscali'];

        // ⚠️ `validate()` restituisce **solo le chiavi presenti**, e con regole
        // `nullable` una chiave assente passa senza comparire nel risultato.
        // `$fiscali` è una property pubblica settabile in blocco: un payload con
        // un campo solo produceva `Undefined array key`, cioè un 500 raggiungibile
        // dalla superficie invece di un errore di validazione.
        $dati = array_merge(array_fill_keys(self::CAMPI_FISCALI, ''), $dati);

        $account->update([
            'ragione_sociale' => $dati['ragione_sociale'],
            'partita_iva' => $dati['partita_iva'] ?: null,
            'codice_fiscale' => $dati['codice_fiscale'] ?: null,
            'pec' => $dati['pec'] ?: null,
            'codice_destinatario_sdi' => $dati['codice_destinatario_sdi'] ?: null,
        ]);

        $this->chiudiPannello();
    }

    /**
     * 🔴 Apre la conferma dell'eliminazione **definitiva** (🔗 ADR-040).
     *
     * Non elimina niente: qui si contano le conseguenze e si mostra cosa
     * sparirebbe. Il gesto vero è `eliminaAccount()`, dietro la riscrittura del
     * nome.
     */
    public function apriEliminazione(int $accountId): void
    {
        $account = $this->accountAmministrabile($accountId, 'elimina');

        $this->confermaEliminazione = '';
        $this->resetValidation();
        $this->chiudiOgniModale();
        $this->accountInLavorazione = $account->id;
        $this->pannello = 'eliminazione';
    }

    /**
     * ⛔ **Elimina tutto, e non c'è ripristino.**
     *
     * Le guardie sono tre e in quest'ordine: l'autorizzazione (che rilegge
     * dalla porta, quindi esclude l'account di piattaforma e i cestinati), la
     * riscrittura del nome, e solo allora l'azione.
     *
     * ⚠️ **Il confronto è `trim()` + case-insensitive**: pretendere la maiuscola
     * esatta punirebbe un copia-incolla corretto senza proteggere da niente, e
     * la protezione qui è «hai guardato quale riga stai eliminando», non «sai
     * scrivere».
     *
     * ⚠️ E la ragione sociale può essere **vuota** — un account nato da un
     * Payment Link la prende da un campo di Stripe — quindi in quel caso si
     * chiede l'email di un membro. La regola resta una: si riscrive qualcosa
     * che identifica *questo* cliente e nessun altro.
     */
    public function eliminaAccount(): void
    {
        $account = $this->accountAmministrabile($this->accountInLavorazione, 'elimina');

        $atteso = self::parolaDiConferma($account);

        if (mb_strtolower(trim($this->confermaEliminazione)) !== mb_strtolower($atteso)) {
            $this->addError('confermaEliminazione', "Per procedere riscrivi «{$atteso}».");

            return;
        }

        $guasti = app(EliminaCliente::class)->esegui($account, auth()->user());

        $this->chiudiPannello();

        // ⚠️ I guasti dei servizi esterni **non** hanno impedito l'eliminazione:
        // vanno detti, o resterebbero una subscription aperta su Stripe e dei
        // file su Backblaze che nessuno sa di dover chiudere a mano.
        $this->erroreEliminazione = $guasti === [] ? null : implode(' ', $guasti);
    }

    /**
     * Cosa si deve riscrivere: la ragione sociale, o l'email di un membro se
     * quella manca.
     *
     * ⚠️ Statica e pubblica perché la usa **anche il Blade**, che deve mostrare
     * esattamente la stringa che l'azione confronterà. Due modi di calcolarla
     * divergerebbero al primo account senza ragione sociale — cioè proprio nel
     * caso raro, dove nessuno se ne accorgerebbe prima di trovarsi bloccato.
     */
    public static function parolaDiConferma(Account $account): string
    {
        $ragioneSociale = trim((string) $account->ragione_sociale);

        return $ragioneSociale !== ''
            ? $ragioneSociale
            : (string) $account->membri()->value('email');
    }

    /** L'account su cui è aperto un pannello, per la vista. */
    public function accountAperto(): ?Account
    {
        if ($this->accountInLavorazione === null || $this->pannello === '') {
            return null;
        }

        $account = VistaPiattaforma::accounts()->find($this->accountInLavorazione);

        if ($account === null) {
            return null;
        }

        // La stessa ability dell'azione, richiesta anche in **lettura**: il
        // pannello mostra `locked_reason` — il dato che ADR-013 tiene fuori da
        // `/bloccato` apposta — e i dati fiscali, che non sono meno riservati del
        // gesto che li cambia.
        //
        // ⚠️ `match` con `default` e non un ternario: un ternario mappa **ogni**
        // valore sconosciuto su `manage`, e il pannello del provisioning
        // erediterebbe in silenzio l'ability sbagliata (gli serve
        // `tenants.provision`). Costa una riga e toglie la trappola prima che
        // qualcuno ci cada.
        $ability = match ($this->pannello) {
            'lockout' => 'lockout',
            'fiscali' => 'manage',
            'eliminazione' => 'elimina',
            default => null,
        };

        if ($ability === null) {
            return null;
        }

        return Gate::allows($ability, $account) ? $account : null;
    }

    /**
     * Rilegge l'account **dalla porta** e verifica l'ability, a ogni azione.
     *
     * ⚠️ Due controlli e non uno, perché rispondono a due domande diverse.
     * `VistaPiattaforma::accounts()` chiede `tenants.view_all` e restringe ai
     * **clienti** (niente EasyLab, niente cestinati); `Gate::authorize()` chiede
     * se **questo** utente può fare **questa** cosa su **questo** account. Il
     * permesso nudo (`billing.lockout`, `billing.manage_own`) non si usa mai: è
     * globale, quindi concederebbe su qualunque riga — ed esiste già un
     * guardrail che rende rossa la stringa nuda fuori dalla Policy.
     */
    private function accountAmministrabile(?int $accountId, string $ability): Account
    {
        abort_if($accountId === null, 404);

        $account = VistaPiattaforma::accounts()->whereKey($accountId)->firstOrFail();

        Gate::authorize($ability, $account);

        return $account;
    }
}

<?php

namespace App\Livewire\Utenti;

use App\Models\Account;
use App\Models\Scopes\DepartmentScope;
use App\Models\Scopes\TenantScope;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\AuditLog;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Utenti\InvitaUtente;
use App\Support\Utenti\InvitoRifiutato;
use App\Support\Utenti\RuoliAssegnabili;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

/**
 * Le **persone dell'Ente**: chi le aggiunge, chi le toglie (🔗 ADR-038).
 *
 * Fino al 29 Ago 2026 un cliente non poteva aggiungere nessuno: gli utenti
 * nascevano solo dal provisioning, dal self-signup e dai seeder, e in tutti e
 * tre i casi nasceva **un Admin e uno solo**. I permessi `utenti.*` erano a
 * catalogo dal primo giorno, l'Admin li aveva tutti, e nessuna riga di codice
 * li consumava. Il difetto si è visto da fuori: la tendina «Assegnatario» di
 * una sede nuova era vuota, con il campo obbligatorio.
 *
 * ## ⛔ Qui il confine di tenancy si scrive a mano, e non c'è rete
 *
 * `User` **non ha `TenantScope`**: è l'identità, non il dominio (🔗 ADR-018), e
 * un global scope su `users` filtrerebbe anche il provider di autenticazione.
 * Quindi «le persone del **mio** Ente» è una condizione che questa classe
 * scrive esplicitamente, in **un punto solo** — `personeDellEnte()` — e da cui
 * passano sia l'elenco sia ogni rilettura di una singola riga. Un id di
 * un'altra sede non trova nulla e finisce in 404 (`ModelNotFoundException`),
 * che è la risposta giusta: «non esiste per te» e non «non ti è permesso».
 *
 * ## Le property pubbliche arrivano dal browser
 *
 * Ogni azione **riautorizza** e **rilegge dal database**: non ci si appoggia a
 * ciò che l'apertura della modale ha impostato, perché `set` + `call` è a un
 * `$wire` di distanza. E ogni property che indica una riga porta il suo hook
 * `updating*` (il precedente è `Piattaforma\Concerns\ProvisionaCliente`),
 * perché `$wire.set()` non passa dall'azione: senza l'hook la modale si
 * intitolerebbe col nome di una persona che l'azione poi non troverebbe.
 *
 * ## Lo stato non è una colonna
 *
 * «Invitato» è `email_verified_at IS NULL` più una password tappo di 64
 * caratteri che nessuno vedrà mai (🔗 ADR-012, `ImpostaPasswordInvito`);
 * «cestinato» è `deleted_at` (🔗 ADR-038). Nessun `users.is_active`: sarebbe la
 * terza sorgente di verità su «questa persona può entrare?».
 *
 * ## Cosa questa schermata non fa, di proposito
 *
 * - **non scrive `tenant_id`**: è fuori dal `$fillable` (🔗 ADR-032) e la sua
 *   unica via è `User::passaAllEnte()`. Spostare qualcuno da un Ente a un altro
 *   è un'altra funzione, e non è questa;
 * - **non confersice Superadmin né Developer**: il rifiuto vive nel codice
 *   (`RuoliAssegnabili::ammesso()`), non nell'assenza dalla tendina;
 * - **non scrive mai il pivot dei ruoli**: solo `assignRole()`/`syncRoles()`
 *   invalidano `spatie.permission.cache`, che è **condivisa fra i processi** —
 *   `ScrittureRbacGuardrailTest` lo rende rosso e il difetto che previene dura
 *   fino a 24 ore.
 */
#[Layout('components.layouts.app')]
class ElencoUtenti extends Component
{
    use WithPagination;

    /**
     * Dieci per pagina, come `Piattaforma\Tecnici`, che è la schermata sorella.
     *
     * ⚠️ Prima non paginava affatto: un `get()` rendeva **tutte** le persone
     * dell'Ente in una schermata sola. Con venti righe non si nota; un ospedale
     * con qualche centinaio di persone caricava tutto a ogni update di Livewire.
     */
    private const PER_PAGE = 10;

    /**
     * `utenti.view`, che esiste a catalogo dal primo giorno ed è già dell'Admin:
     * nessun permesso nuovo, quindi **nessun riseeding** di `config/rbac.php` —
     * che da S6 è un'arma carica.
     *
     * ⚠️ Non è nel set bloccato, quindi l'editor dei ruoli può concederlo ad
     * altri: è una scelta consapevole, e il rovescio è scritto in ADR-038.
     */
    public const PERMESSO = 'utenti.view';

    // --- Modale «invita una persona» ---

    public bool $showInvito = false;

    public string $nome = '';

    public string $email = '';

    public string $ruolo = '';

    /**
     * L'id della persona **cestinata** del proprio Ente che occupa l'indirizzo
     * appena rifiutato, quando c'è.
     *
     * ⚠️ Serve a offrire il **ripristino** invece di un errore secco: chi ha
     * digitato quell'email non vede quella persona in elenco — il cestino la
     * nasconde — e un messaggio che non lo dicesse la manderebbe a cercare un
     * difetto. È il motivo per cui `InvitoRifiutato` porta un `codice` distinto
     * (`UTENTE_CESTINATO`) e non solo un messaggio.
     */
    public ?int $ripristinabile = null;

    // --- Modale «cambia ruolo» ---

    public ?int $utenteRuolo = null;

    public string $nuovoRuolo = '';

    // --- Conferma «cestina» ---

    public ?int $utenteCestino = null;

    // --- Esiti in pagina ---

    public ?string $notice = null;

    public ?string $errore = null;

    // =========================================================================
    // Il confine: le persone del proprio Ente, e nient'altro
    // =========================================================================

    /**
     * L'Ente corrente.
     *
     * Si legge da `CurrentTenant` e non da `Auth::user()->tenant_id` perché chi
     * impersona un cliente con più sedi può essersi spostato: lo spostamento
     * vive nella sessione e non tocca `users.tenant_id` (🔗 `CurrentTenant`),
     * quindi il `tenant_id` nudo direbbe la sede sbagliata.
     *
     * ⚠️ Fail-closed: senza Ente non si elenca nessuno. Un utente autenticato
     * senza `tenant_id` è un tecnico di piattaforma (🔗 ADR-030), e le sue
     * persone non sono «quelle senza Ente» — sono nessuna.
     */
    private function enteId(): int
    {
        $id = CurrentTenant::id();

        abort_if($id === null, 403);

        return $id;
    }

    /**
     * 🔴 **Il confine, in un punto solo.** Include le cestinate, perché
     * «cestinato» è uno degli stati che questa schermata mostra e da cui si
     * torna indietro.
     *
     * @return Builder<User>
     */
    private function personeDellEnte(): Builder
    {
        return User::withTrashed()->where('tenant_id', $this->enteId());
    }

    /**
     * Una persona, riletta dal database **dentro il confine**.
     *
     * ⛔ Ogni azione passa da qui e non dalla property: l'id arriva dal browser
     * e vale quanto ciò che il browser vale. Fuori dal proprio Ente non c'è un
     * 403 ma un 404, ed è deliberato — un «non ti è permesso» confermerebbe
     * l'esistenza della persona di qualcun altro.
     */
    private function personaDellEnte(mixed $id, bool $cestinate = false): User
    {
        $persona = $this->personeDellEnte()
            ->when($cestinate,
                fn (Builder $q) => $q->whereNotNull('deleted_at'),
                fn (Builder $q) => $q->whereNull('deleted_at'),
            )
            ->whereKey($id)
            ->firstOrFail();

        // 🔴 **Ciò che questa schermata non sa conferire, non lo sa nemmeno
        // togliere.** `RuoliAssegnabili::ammesso()` guarda il ruolo di
        // *destinazione* e ferma chi prova a **darsi** Superadmin; non ferma
        // chi prova a **toglierlo**. Un `syncRoles(['Tenant'])` puntato sul
        // Developer passa quella guardia senza un sussulto, e un `cestina()`
        // non le passa nemmeno accanto.
        //
        // ⛔ Non è un caso di scuola: il Superadmin è tenant-bound sull'Ente di
        // piattaforma (🔗 ADR-018) insieme al Developer, e ha tutti e quattro
        // gli `utenti.*` — quindi si trova il Developer in elenco sulla propria
        // `/utenti`, coi bottoni accanto. Il Developer è la **chiave di
        // riserva** (🔗 ADR-016): non c'è nessun `Gate::before` da super-admin
        // che lo rimetta dentro dopo.
        //
        // 403 e non 404: la persona è del proprio Ente e in elenco si vede — un
        // «non esiste» sarebbe una bugia verificabile guardando la riga sopra.
        abort_unless(RuoliAssegnabili::amministrabile($persona), 403);

        return $persona;
    }

    /**
     * La stessa regola, per la vista: non offrire un gesto che l'azione
     * rifiuterebbe. **Non autorizza niente** — la guardia è in
     * `personaDellEnte()`, che rilegge dal database.
     */
    private function amministrabile(User $persona): bool
    {
        return RuoliAssegnabili::amministrabile($persona);
    }

    /** L'Ente come modello: serve all'invito, che ci scrive dentro la persona. */
    private function ente(): UnitaOrganizzativa
    {
        // 🔴 I due scope di tenancy tolti **per nome**, e il soft delete no.
        //
        // Per nome perché il bypass nudo toglierebbe anche `SoftDeletes`, e un
        // Ente cestinato tornerebbe a essere un posto in cui si invitano
        // persone (`BypassNudiGuardrailTest`). Tolti perché qui si risponde a
        // «di chi è questa schermata», e la risposta non può dipendere dal
        // sotto-albero di chi guarda: un Responsabile Reparto a cui l'editor
        // dei ruoli avesse dato `utenti.*` non vedrebbe la radice del proprio
        // Ente e prenderebbe un 404 al posto della propria pagina.
        //
        // ⚠️ Il confine non si perde: l'id viene da `CurrentTenant`, non dal
        // browser — è la stessa disciplina di `User::ente()`.
        return UnitaOrganizzativa::withoutGlobalScopes([TenantScope::class, DepartmentScope::class])
            ->findOrFail($this->enteId());
    }

    /** L'Account del contratto di questo Ente, se ne ha uno. */
    private function accountDellEnte(): ?Account
    {
        return $this->ente()->account;
    }

    // =========================================================================
    // Lettura
    // =========================================================================

    /** @return list<string> */
    public function ruoliConferibili(): array
    {
        return RuoliAssegnabili::perCliente();
    }

    /** Il ruolo scelto imporrà il secondo fattore a chi lo riceve? (🔗 ADR-016) */
    public function imponeSecondoFattore(string $ruolo): bool
    {
        return $ruolo !== '' && RuoliAssegnabili::imponeSecondoFattore($ruolo);
    }

    /**
     * Lo stato di una persona, che **non è una colonna**: si compone da
     * `deleted_at` e da `email_verified_at` (🔗 ADR-038, ADR-012).
     *
     * @return array{testo: string, variante: string}
     */
    private function stato(User $persona): array
    {
        if ($persona->trashed()) {
            return ['testo' => 'Cestinata', 'variante' => 'danger'];
        }

        if ($persona->email_verified_at === null) {
            return ['testo' => 'Invitata, mai entrata', 'variante' => 'warning'];
        }

        return ['testo' => 'Attiva', 'variante' => 'success'];
    }

    // =========================================================================
    // Invitare
    // =========================================================================

    public function apriInvito(): void
    {
        Gate::authorize('utenti.create');

        $this->resetForm();
        $this->showInvito = true;
    }

    public function chiudiInvito(): void
    {
        $this->showInvito = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->nome = '';
        $this->email = '';
        $this->ruolo = '';
        $this->ripristinabile = null;
        $this->errore = null;
        $this->resetValidation();
    }

    /**
     * ⛔ Riautorizza e **rivalida il ruolo nell'azione che scrive**, non solo
     * dove si disegna la tendina: una `<select>` che non mostra `Superadmin`
     * non impedisce a nessuno di mandarlo — e quel ruolo porta
     * `tenants.view_all`, cioè la lettura sulle righe di *tutti* i clienti.
     */
    public function invita(): void
    {
        Gate::authorize('utenti.create');

        $this->notice = null;
        $this->errore = null;
        $this->ripristinabile = null;

        // ⚠️ Si toglie lo spazio **prima** di validare, non dopo: un indirizzo
        // incollato dalla posta si porta dietro uno spazio in coda, e la regola
        // `email` lo rifiuterebbe con «non è un indirizzo valido» — un errore
        // che accusa chi legge di aver scritto male ciò che ha scritto bene.
        // `InvitaUtente` normalizza per conto suo, ma quella normalizzazione
        // arriva dopo questa validazione e non la salverebbe.
        $this->nome = trim($this->nome);
        $this->email = trim($this->email);

        $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'ruolo' => ['required', 'string'],
        ], attributes: [
            'nome' => 'nome',
            'email' => 'indirizzo email',
            'ruolo' => 'ruolo',
        ]);

        // 🔴 La whitelist sta **qui e solo qui**, e non anche in un `Rule::in`:
        // due dichiarazioni della stessa regola sono due regole, e la seconda
        // renderebbe la prima non più falsificabile — togliendo questa riga la
        // suite resterebbe verde perché a rifiutare sarebbe la validazione.
        // La forma («c'è un ruolo?») la controlla `validate`, la sostanza
        // («è conferibile?») la controlla `ammesso()`.
        abort_unless(RuoliAssegnabili::ammesso($this->ruolo), 403);

        try {
            $esito = (new InvitaUtente($this->nome, $this->email, $this->ruolo, $this->ente()))->esegui();
        } catch (InvitoRifiutato $e) {
            $this->rifiuta($e);

            return;
        }

        // 🔴 Un Admin è anche **chi amministra il contratto** (🔗 ADR-032), ed è
        // la stessa condizione con cui `ProvisionaEnte` crea la *prima*
        // appartenenza e con cui `ripristina()` la rimette. Senza questa riga
        // le tre strade divergevano, e la divergenza era un **vicolo cieco**:
        // il secondo Admin invitato da qui non era membro, quindi cestinare il
        // primo sbatteva su «è l'unica persona che amministra il contratto:
        // aggiungine un'altra» — un consiglio che nessuna schermata sapeva
        // eseguire.
        $this->aggiornaAppartenenza($esito->utente);

        $this->showInvito = false;
        $nomePersona = $esito->utente->name;
        $this->resetForm();

        // ⚠️ «In consegna», mai «inviato»: la notifica è `ShouldQueue`, quindi
        // chi chiama l'ha consegnata alla coda e **non può sapere** se è
        // partita (🔗 `EsitoInvito`). Dire «inviato» sarebbe una bugia non più
        // verificabile proprio sul gesto la cui unica prova, per chi lo compie,
        // è la frase che legge subito dopo.
        $this->notice = $esito->invitoAccodato
            ? "Invito in consegna a {$nomePersona}."
            : "{$nomePersona} è stata creata, ma l'invito non è partito: ripeti il gesto per riprovare a consegnarlo.";

        if ($this->imponeSecondoFattore($esito->utente->getRoleNames()->first() ?? '')) {
            $this->notice .= ' Al primo accesso le sarà chiesto di configurare il secondo fattore, e non potrà andare altrove finché non l\'avrà fatto.';
        }
    }

    /**
     * Il rifiuto dell'invito, detto in modo che si possa fare qualcosa.
     *
     * 🔴 Il caso «cestinata» si offre come **ripristino**, ma solo se quella
     * persona è del **proprio Ente**: `users.email` è unique su tutta la
     * piattaforma, quindi l'indirizzo può appartenere alla persona cestinata di
     * un altro cliente. Proporre lì un ripristino sarebbe un gesto cross-tenant
     * a partire da un indirizzo indovinato; e ripetere il messaggio
     * dell'eccezione, che nomina il caso, direbbe a un estraneo che quella
     * persona esiste. Fuori dal proprio Ente la risposta è la stessa che si dà
     * per un indirizzo già in uso.
     */
    private function rifiuta(InvitoRifiutato $e): void
    {
        if ($e->codice !== InvitoRifiutato::UTENTE_CESTINATO) {
            $this->errore = $e->getMessage();

            return;
        }

        $cestinata = $this->personeDellEnte()
            ->whereNotNull('deleted_at')
            ->where('email', mb_strtolower(trim($this->email)))
            ->first();

        if ($cestinata === null) {
            $this->errore = "L'indirizzo ".mb_strtolower(trim($this->email)).' è già di una persona di questa piattaforma.';

            return;
        }

        $this->ripristinabile = $cestinata->id;
        $this->errore = "{$cestinata->name} è già qui, ma è nel cestino: non compare in elenco per questo. Ripristinala invece di crearne una nuova.";
    }

    /**
     * Reinviare l'invito a chi non è mai entrato.
     *
     * ⚠️ Chiede `utenti.create` e non `utenti.update`: non si sta modificando
     * una persona, si sta **consegnando di nuovo una credenziale d'accesso** —
     * è lo stesso gesto dell'invito, ripetuto. Il link è firmato e la firma
     * nasce dentro la notifica, quindi ogni reinvio produce sette giorni
     * freschi (🔗 ADR-012).
     *
     * La rilettura pretende `email_verified_at IS NULL`: reinvitare chi è già
     * entrato non è un errore da spiegare, è una riga che non esiste.
     */
    public function reinvia(int $id): void
    {
        Gate::authorize('utenti.create');

        $this->notice = null;
        $this->errore = null;

        $persona = $this->personeDellEnte()
            ->whereNull('deleted_at')
            ->whereNull('email_verified_at')
            ->whereKey($id)
            ->firstOrFail();

        // Stessa regola delle altre azioni: questa rilettura ha condizioni
        // proprie e non passa da `personaDellEnte()`, quindi la guardia si
        // ripete qui invece di essere dedotta.
        abort_unless(RuoliAssegnabili::amministrabile($persona), 403);

        $ente = $this->ente();

        try {
            $persona->notify(new InvitoUtente($ente->nome, $persona->email, $ente->id));
            $this->notice = "Invito di nuovo in consegna a {$persona->name}.";
        } catch (Throwable $e) {
            $this->errore = "L'invito a {$persona->name} non è partito: riprova fra poco.";
        }

        // `User` è **esente** da `AuditsDomainWrites` per scelta dichiarata: le
        // sue righe si scrivono a mano, come fa `passaAllEnte()`.
        activity(AuditLog::NAME)
            ->causedBy(Auth::user())
            ->performedOn($persona)
            ->withProperties(['email' => $persona->email, 'tenant_id' => $ente->id])
            ->log('Invito reinviato');
    }

    // =========================================================================
    // Cambiare ruolo
    // =========================================================================

    public function apriRuolo(int $id): void
    {
        Gate::authorize('utenti.update');

        $persona = $this->personaDellEnte($id);

        $this->utenteRuolo = $persona->id;
        $this->nuovoRuolo = $persona->getRoleNames()->first() ?? '';
        $this->errore = null;
        $this->resetValidation();
    }

    public function chiudiRuolo(): void
    {
        $this->utenteRuolo = null;
        $this->nuovoRuolo = '';
        $this->resetValidation();
    }

    /**
     * L'altra strada per puntare la modale su una persona: la property.
     *
     * Senza questo hook un id di un altro Ente aprirebbe la modale — che
     * mostrerebbe il nome letto altrove, o nessun nome — e solo l'invio
     * fallirebbe. La modale direbbe una cosa e l'azione ne farebbe un'altra.
     */
    public function updatingUtenteRuolo(mixed $valore): void
    {
        if ($valore !== null && $valore !== '') {
            Gate::authorize('utenti.update');
            $this->personaDellEnte($valore);
        }
    }

    /**
     * ⛔ Il ruolo si scrive con `syncRoles()`, mai sul pivot.
     *
     * E non si sfila l'ultimo Admin: sarebbe la stessa cosa che cestinarlo —
     * un Ente senza nessuno che possa amministrarlo — ottenuta dall'altra
     * porta. Le due guardie stanno insieme perché il difetto è uno solo.
     */
    public function cambiaRuolo(): void
    {
        Gate::authorize('utenti.update');

        $this->notice = null;
        $this->errore = null;

        $this->validate([
            'nuovoRuolo' => ['required', 'string'],
        ], attributes: ['nuovoRuolo' => 'ruolo']);

        abort_unless(RuoliAssegnabili::ammesso($this->nuovoRuolo), 403);

        $esito = DB::transaction(function (): ?array {
            $this->bloccaPersoneAttiveDellEnte();
            $persona = $this->personaDellEnte($this->utenteRuolo);

            if ($persona->hasRole('Admin') && $this->nuovoRuolo !== 'Admin' && $this->ultimoAdmin($persona)) {
                $this->errore = "{$persona->name} è l'unico Admin di questo Ente: nominane un altro prima di cambiarle ruolo.";

                return null;
            }

            $precedenti = $persona->getRoleNames()->all();
            $persona->syncRoles([$this->nuovoRuolo]);

            // Come nell'invito: chi diventa Admin entra fra i membri
            // dell'account. Chi smette di esserlo non ne esce: i permessi li
            // porta il ruolo, mentre l'uscita dal contratto passa dal cestino.
            $this->aggiornaAppartenenza($persona);

            return [$persona, $precedenti];
        });

        if ($esito === null) {
            return;
        }

        [$persona, $precedenti] = $esito;

        activity(AuditLog::NAME)
            ->causedBy(Auth::user())
            ->performedOn($persona)
            ->withProperties([
                'da' => $precedenti,
                'a' => $this->nuovoRuolo,
                'tenant_id' => $persona->tenant_id,
            ])
            ->log('Ruolo cambiato');

        $ruolo = $this->nuovoRuolo;
        $this->notice = "{$persona->name} ora è {$ruolo}.";

        if ($this->imponeSecondoFattore($ruolo)) {
            $this->notice .= ' Al prossimo accesso le sarà chiesto di configurare il secondo fattore, e non potrà andare altrove finché non l\'avrà fatto.';
        }

        $this->chiudiRuolo();
    }

    // =========================================================================
    // Cestinare e ripristinare
    // =========================================================================

    public function confermaCestino(int $id): void
    {
        Gate::authorize('utenti.delete');

        $this->utenteCestino = $this->personaDellEnte($id)->id;
        $this->errore = null;
    }

    public function annullaCestino(): void
    {
        $this->utenteCestino = null;
    }

    /** Stessa ragione di `updatingUtenteRuolo`: `$wire.set()` non passa dall'azione. */
    public function updatingUtenteCestino(mixed $valore): void
    {
        if ($valore !== null && $valore !== '') {
            Gate::authorize('utenti.delete');
            $this->personaDellEnte($valore);
        }
    }

    /** Stessa ragione, sul ramo del ripristino: la riga è cestinata. */
    public function updatingRipristinabile(mixed $valore): void
    {
        if ($valore !== null && $valore !== '') {
            Gate::authorize('utenti.delete');
            $this->personaDellEnte($valore, cestinate: true);
        }
    }

    /**
     * Cestinare **non è cancellare** (🔗 ADR-038 alternativa (a)): metà delle FK
     * verso `users` è `cascadeOnDelete`, e una cancellazione vera porterebbe via
     * appartenenze e portafoglio e lascerebbe ogni intervento storico senza
     * assegnatario. Qui la riga resta, e lo storico continua a nominarla.
     *
     * Tre rifiuti, e nessuno dei tre è un 403: sono regole di dominio, e chi le
     * incontra ha bisogno di sapere **cosa fare dopo**, non che non gli è
     * permesso.
     */
    public function cestina(): void
    {
        Gate::authorize('utenti.delete');

        $this->notice = null;
        $this->errore = null;

        $persona = $this->personaDellEnte($this->utenteCestino);

        // 1. Sé stessi, mai: chi si cestina perde l'accesso nell'atto stesso di
        //    perderlo, e non ha più la schermata da cui rimediare.
        if ($persona->id === Auth::id()) {
            $this->errore = 'Non puoi cestinare te stesso: chiedi a un altro Admin di questo Ente.';

            return;
        }

        $ultimoAdmin = false;
        try {
            DB::transaction(function () use (&$persona, &$ultimoAdmin) {
                // 2. L'ultimo Admin, mai. Il lock rende vera la frase anche
                // con due richieste simultanee: senza, ciascuna potrebbe
                // vedere l'altra e cestinarle entrambe.
                $this->bloccaPersoneAttiveDellEnte();
                $persona = $this->personaDellEnte($this->utenteCestino);

                if ($persona->hasRole('Admin') && $this->ultimoAdmin($persona)) {
                    $ultimoAdmin = true;

                    return;
                }

                // 3. L'ultimo membro dell'account, mai. `rimuoviMembro()`
                //    **lancia** (🔗 ADR-032: ogni account ha sempre almeno un
                //    membro), e va intercettato qui: la pagina che esplode non
                //    dice a nessuno cosa fare.
                //
                //    ⚠️ Il distacco serve davvero: `Account::membri()` è una
                //    belongsToMany verso `User`, quindi applica il global scope
                //    del cestino — una persona cestinata **sparirebbe dai
                //    membri** senza che l'invariante se ne accorga.
                $this->accountDellEnte()?->rimuoviMembro($persona);

                $persona->delete();
            });
        } catch (RuntimeException $e) {
            $this->errore = "{$persona->name} è l'unica persona che amministra il contratto di questo Ente: aggiungine un'altra prima di cestinarla.";

            return;
        }

        if ($ultimoAdmin) {
            $this->errore = "{$persona->name} è l'unico Admin di questo Ente: nominane un altro prima di cestinarla.";

            return;
        }

        activity(AuditLog::NAME)
            ->causedBy(Auth::user())
            ->performedOn($persona)
            ->withProperties(['email' => $persona->email, 'tenant_id' => $persona->tenant_id])
            ->log('Persona cestinata');

        $this->utenteCestino = null;
        $this->notice = "{$persona->name} è nel cestino: non entra più e non compare nelle tendine, ma lo storico continua a nominarla.";
    }

    /**
     * Il ripristino, che è l'inverso esatto del cestino — e chiede lo **stesso**
     * permesso: `utenti.delete` è l'interruttore, non la direzione. Legarlo a
     * `utenti.update` vorrebbe dire che qualcuno può togliere e non rimettere.
     */
    public function ripristina(int $id): void
    {
        Gate::authorize('utenti.delete');

        $this->notice = null;
        $this->errore = null;

        $persona = $this->personaDellEnte($id, cestinate: true);

        DB::transaction(function () use ($persona) {
            $persona->restore();

            // Simmetrico al distacco del cestino, e con la stessa condizione con
            // cui il provisioning crea la **prima** appartenenza: è l'Admin che
            // amministra il contratto (🔗 ADR-032).
            if ($persona->hasRole('Admin')) {
                $this->accountDellEnte()?->aggiungiMembro($persona);
            }
        });

        activity(AuditLog::NAME)
            ->causedBy(Auth::user())
            ->performedOn($persona)
            ->withProperties(['email' => $persona->email, 'tenant_id' => $persona->tenant_id])
            ->log('Persona ripristinata');

        $this->ripristinabile = null;
        $this->showInvito = false;
        $this->notice = "{$persona->name} è di nuovo attiva. Se non era mai entrata, reinviale l'invito.";
    }

    /**
     * Un Admin è anche un membro dell'Account (🔗 ADR-032).
     *
     * Solo in aggiunta: l'uscita dal contratto passa da `rimuoviMembro()`, che
     * difende l'invariante e vive nel cestino.
     */
    private function aggiornaAppartenenza(User $persona): void
    {
        if ($persona->hasRole('Admin')) {
            $this->accountDellEnte()?->aggiungiMembro($persona);
        }
    }

    /**
     * Serializza i due gesti che possono togliere un Admin: cambio ruolo e
     * cestino. Il solo `exists()` di `ultimoAdmin()` non basta sotto
     * concorrenza: due richieste possono vedere ciascuna l'altra Admin ancora
     * attiva e rimuoverle entrambe. Il tie-break evita ordini di lock diversi.
     *
     * Va chiamato solo dentro una transazione.
     */
    private function bloccaPersoneAttiveDellEnte(): void
    {
        $this->personeDellEnte()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['users.id']);
    }

    /**
     * È l'unica Admin **non cestinata** dell'Ente?
     *
     * Si conta sul database e non sulla collezione già caricata: fra il render
     * e l'azione un altro Admin può essere sparito da un'altra scheda.
     */
    private function ultimoAdmin(User $persona): bool
    {
        return ! $this->personeDellEnte()
            ->whereNull('deleted_at')
            ->whereKeyNot($persona->getKey())
            ->whereHas('roles', fn ($q) => $q->where('name', 'Admin'))
            ->exists();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    private function persone(): LengthAwarePaginator
    {
        // `with('roles')`: due query in tutto, qualunque sia il numero di
        // persone. Il ruolo è una colonna dell'elenco, e senza l'eager load
        // sarebbe una query per riga — il difetto che `TabellaClientiTest` ha
        // già congelato per il Parco clienti.
        //
        // ⚠️ Il tie-break sull'id non è decorativo: a parità di nome l'ordine
        // fra due righe è una proprietà del motore, e SQLite e Postgres non la
        // decidono allo stesso modo.
        return $this->personeDellEnte()
            ->with('roles')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Quanti Admin **attivi** ha l'Ente, contati dal database.
     *
     * 🔴 **Contarli sulla pagina sarebbe un difetto, non un'ottimizzazione.**
     * Il numero governa il marcatore «ultimo Admin», cioè quali comandi la
     * tabella mostra: su una pagina che ne contiene uno solo, ogni Admin
     * sembrerebbe l'ultimo e i suoi comandi sparirebbero — mentre l'Ente ne ha
     * altri due nella pagina dopo. Prima della paginazione la collezione era
     * l'elenco intero e la distinzione non esisteva.
     *
     * ⚠️ Resta comunque **solo cosa mostrare**: la guardia vera è
     * `ultimoAdmin()`, che rilegge dal database dentro l'azione.
     */
    private function adminAttivi(): int
    {
        return $this->personeDellEnte()
            ->whereNull('deleted_at')
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'Admin'))
            ->count();
    }

    public function render(): View
    {
        $persone = $this->persone();

        return view('livewire.utenti.elenco-utenti', [
            'persone' => $persone,
            'adminAttivi' => $this->adminAttivi(),
            // Calcolati server-side e indicizzati per id: i due helper restano
            // privati. Se fossero pubblici, Livewire li esporrebbe come azioni
            // e il model binding su `User` (che non e tenant-scoped) potrebbe
            // diventare un oracle sull'esistenza di persone di altri Enti.
            'stati' => $persone->mapWithKeys(fn (User $u) => [$u->id => $this->stato($u)]),
            'amministrabili' => $persone->mapWithKeys(fn (User $u) => [$u->id => $this->amministrabile($u)]),
            'inCestino' => $this->utenteCestino !== null
                ? $persone->firstWhere('id', $this->utenteCestino)
                : null,
            'inModifica' => $this->utenteRuolo !== null
                ? $persone->firstWhere('id', $this->utenteRuolo)
                : null,
        ]);
    }
}

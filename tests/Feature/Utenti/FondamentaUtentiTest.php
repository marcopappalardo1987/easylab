<?php

use App\Models\Account;
use App\Models\Intervento;
use App\Models\Registrazione;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Provisioning\ProvisioningRifiutato;
use App\Support\Utenti\Assegnabili;
use App\Support\Utenti\InvitaUtente;
use App\Support\Utenti\InvitoRifiutato;
use App\Support\Utenti\RuoliAssegnabili;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BancoRegistrazione;

/**
 * 🔴 Le **fondamenta** della gestione persone (🔗 ADR-038), area rossa.
 *
 * Qui vivono le tre cose su cui le due schermate poggiano, e che nessuna delle
 * due può correggere se sono sbagliate:
 *
 *   1. **il cestino** — `SoftDeletes` su `users`, che è una guardia di accesso
 *      travestita da colonna: chi è cestinato non entra, non compare nelle
 *      tendine e non riceve email, **ma continua a essere nominato dallo
 *      storico**;
 *   2. **l'email unique contro il cestino** — `users.email` è unique *senza
 *      condizione*, quindi una riga cestinata occupa l'indirizzo: senza cura
 *      diventa un 500 sulla registrazione pubblica, che non è autenticata;
 *   3. **la tendina dell'assegnatario**, che passa dal ruolo Tecnico e dal
 *      portafoglio per il personale EasyLab.
 *
 * I negativi contano più dei positivi: ognuna di queste tre è una porta, e una
 * porta si prova provando a passarci attraverso.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $this->sede = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Rossi']);

    $this->admin = User::factory()->create([
        'name' => 'Anna Rossi',
        'tenant_id' => $this->sede->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');
    $this->account->aggiungiMembro($this->admin);
});

// ─── 1. Il cestino: chi è dentro non entra, ma resta nominato ────────────────

it('keeps a binned person out of the login, without a single new if', function () {
    // 🔴 Il provider di autenticazione costruisce la query dal model, quindi
    // applica i global scope: è il `SoftDeletes` a negare l'accesso, non un
    // controllo scritto a mano da qualche parte. È la ragione per cui ADR-038
    // ha scelto il cestino invece del flag che ADR-012 aveva rifiutato.
    $utente = User::factory()->create([
        'email' => 'uscito@example.test',
        'password' => bcrypt('password-vera'),
        'tenant_id' => $this->sede->id,
    ]);
    $utente->assignRole('Tenant');

    expect(auth()->attempt(['email' => 'uscito@example.test', 'password' => 'password-vera']))->toBeTrue();
    auth()->logout();

    $utente->delete();

    expect(auth()->attempt(['email' => 'uscito@example.test', 'password' => 'password-vera']))->toBeFalse();
});

it('still names a binned person in the history that mentions them', function () {
    // ⛔ Il rovescio del cestino, e la ragione delle cinque `withTrashed()`:
    // chi ha fatto una cosa l'ha fatta anche dopo essersene andato. Senza,
    // cestinare una persona **riscriverebbe il passato** in «—».
    $tecnico = User::factory()->create(['name' => 'Luca Bianchi', 'tenant_id' => $this->sede->id]);
    $tecnico->assignRole('Tecnico');

    $strumento = Strumento::factory()->forNode($this->sede)->create(['nome' => 'Autoclave']);
    $intervento = Intervento::factory()->forStrumento($strumento)->create(['tecnico_id' => $tecnico->id]);

    $tecnico->delete();

    expect($intervento->fresh()->tecnicoLabel())->toBe('Luca Bianchi');
});

it('drops a binned person from the assignee dropdown', function () {
    $tecnico = User::factory()->create(['name' => 'Luca Bianchi', 'tenant_id' => $this->sede->id]);
    $tecnico->assignRole('Tecnico');

    expect(Assegnabili::perSede($this->sede->id)->pluck('id')->all())->toContain($tecnico->id);

    $tecnico->delete();

    expect(Assegnabili::perSede($this->sede->id)->pluck('id')->all())->not->toContain($tecnico->id);
});

// ─── 2. L'email unique contro il cestino ─────────────────────────────────────

it('refuses to provision on an address held by a binned person, instead of dying on the unique', function () {
    // 🔴 `users.email` è unique **senza condizione**: la riga cestinata occupa
    // l'indirizzo. Senza la guardia, `firstOrCreate` non la troverebbe (lo
    // scope la nasconde), tenterebbe l'INSERT e sbatterebbe sul vincolo — un
    // 500 al posto di un rifiuto, su un percorso che parte anche dalla
    // registrazione pubblica.
    $uscito = User::factory()->create(['email' => 'uscito@example.test']);
    $uscito->delete();

    $provisioning = new ProvisionaEnte('Nuovo Cliente', 'uscito@example.test', 'Chi Entra');

    expect(fn () => $provisioning->esegui())
        ->toThrow(ProvisioningRifiutato::class);

    // E il rifiuto è **prima di scrivere**: nessun Ente, nessun account.
    expect(UnitaOrganizzativa::withoutGlobalScopes()->where('nome', 'Nuovo Cliente')->exists())->toBeFalse();
});

it('names the binned case with its own code, so the caller can offer the restore', function () {
    // Il messaggio non è un'API: chi deve ramificare — «offro il ripristino?» —
    // ramifica sul codice. È la lezione già pagata dal comando di provisioning,
    // che ramificava con `str_contains()` su una frase poi riscritta.
    $uscito = User::factory()->create(['email' => 'uscito@example.test']);
    $uscito->delete();

    try {
        (new ProvisionaEnte('Nuovo Cliente', 'uscito@example.test', 'Chi Entra'))->esegui();
        $this->fail('Il provisioning doveva rifiutare.');
    } catch (ProvisioningRifiutato $e) {
        expect($e->codice)->toBe(ProvisioningRifiutato::UTENTE_CESTINATO);
    }
});

it('guards the branch that --account= takes, which skips the email lookup entirely', function () {
    // ⚠️ Il difetto che questo test esiste per chiudere: con un account
    // esplicito `risolviAccount()` **ritorna prima** di guardare l'email, e la
    // guardia messa lì dentro avrebbe coperto solo metà dei casi.
    $uscito = User::factory()->create(['email' => 'uscito@example.test']);
    $uscito->delete();

    $provisioning = new ProvisionaEnte('Sede Nuova', 'uscito@example.test', 'Chi Entra', $this->account->id);

    expect(fn () => $provisioning->esegui())->toThrow(ProvisioningRifiutato::class);
});

it('does not let the public sign-up reach the unique constraint', function () {
    // Il percorso pubblico **non è autenticato**: qui un'eccezione sull'indice
    // unique sarebbe una pagina di errore visibile a chiunque conosca
    // l'indirizzo di una persona che se n'è andata.
    //
    // ⚠️ Il banco serve perché una registrazione non parte da un database
    // appena migrato: senza un piano davvero vendibile il modulo risponde
    // «chiuso per guasto», e il test sarebbe verde per la ragione sbagliata.
    BancoRegistrazione::apri();

    $uscito = User::factory()->create(['email' => 'uscito@example.test']);
    $uscito->delete();

    $this->post(route('registrazione.avvia'), [
        'nome_ente' => 'Laboratorio Nuovo',
        'nome_referente' => 'Chi Entra',
        'email' => 'uscito@example.test',
        'piano' => 'saas',
        'password' => 'Password!2345',
        'password_confirmation' => 'Password!2345',
    ])->assertRedirect(route('registrazione.controlla-email'));

    // La risposta è **identica** a quella del percorso felice — il ramo è
    // anti-enumerazione e deve restarlo — ma nessuna riga viene scritta.
    expect(Registrazione::query()->count())->toBe(0);
});

it('does not tell a binned person they already have an account, because they do not', function () {
    // ⚠️ La differenza rispetto al caso «persona attiva»: l'avviso alla casella
    // parte solo per chi un accesso ce l'ha davvero. Chi guarda da fuori non
    // distingue i due casi — stessa risposta HTTP, nessuna riga scritta.
    Notification::fake();
    BancoRegistrazione::apri();

    $uscito = User::factory()->create(['email' => 'uscito@example.test']);
    $uscito->delete();

    $this->post(route('registrazione.avvia'), [
        'nome_ente' => 'Laboratorio Nuovo',
        'nome_referente' => 'Chi Entra',
        'email' => 'uscito@example.test',
        'piano' => 'saas',
        'password' => 'Password!2345',
        'password_confirmation' => 'Password!2345',
    ]);

    Notification::assertNothingSent();
});

// ─── 3. `InvitaUtente`: la persona nasce invitata ────────────────────────────

it('creates an invited person: no verified email, a role, and a mail on its way', function () {
    Notification::fake();

    $esito = (new InvitaUtente('Giulia Verdi', '  Giulia@Example.TEST ', 'Tenant', $this->sede))->esegui();

    // ⚠️ «Invitato» non è una colonna: è `email_verified_at IS NULL` più una
    // password tappo che nessuno vedrà mai (🔗 ADR-012).
    expect($esito->utente->email_verified_at)->toBeNull()
        ->and($esito->utente->tenant_id)->toBe($this->sede->id)
        ->and($esito->utente->hasRole('Tenant'))->toBeTrue()
        // Normalizzata prima di scrivere: due maiuscole in più creerebbero una
        // seconda persona per lo stesso indirizzo.
        ->and($esito->utente->email)->toBe('giulia@example.test')
        ->and($esito->invitoAccodato)->toBeTrue();

    Notification::assertSentTo($esito->utente, InvitoUtente::class);
});

it('creates the EasyLab shape of a tecnico: a role, and no Ente at all', function () {
    // 🔴 La forma «esterna» di ADR-030. Un tecnico creato *dentro* l'Ente
    // EasyLab non funzionerebbe cross-cliente: `Assegnabili` non lo
    // proporrebbe e `tecnicoLabel()` lo renderebbe «—».
    Notification::fake();

    $esito = (new InvitaUtente('Marco Tecnico', 'marco@easylab.test', 'Tecnico'))->esegui();

    expect($esito->utente->tenant_id)->toBeNull()
        ->and($esito->utente->hasRole('Tecnico'))->toBeTrue();
});

it('refuses an address that is already taken, and says which of the two cases it is', function (bool $cestinato, string $codice) {
    $esistente = User::factory()->create(['email' => 'occupata@example.test']);

    if ($cestinato) {
        $esistente->delete();
    }

    try {
        (new InvitaUtente('Doppione', 'occupata@example.test', 'Tenant', $this->sede))->esegui();
        $this->fail("L'invito doveva rifiutare.");
    } catch (InvitoRifiutato $e) {
        expect($e->codice)->toBe($codice);
    }

    // In nessuno dei due casi si scrive: il rifiuto è prima della transazione.
    expect(User::withTrashed()->where('email', 'occupata@example.test')->count())->toBe(1);
})->with([
    'persona attiva' => [false, InvitoRifiutato::EMAIL_GIA_USATA],
    'persona cestinata' => [true, InvitoRifiutato::UTENTE_CESTINATO],
]);
it('recovers an address won by a concurrent invitation instead of returning a database error', function () {
    // Il concorrente si inserisce DOPO la SELECT iniziale e PRIMA del
    // savepoint della transazione di scrittura. Un listener `creating` sarebbe
    // dentro il savepoint e verrebbe annullato insieme all'INSERT perdente.
    $inserito = false;

    DB::connection()->beforeStartingTransaction(function ($connection) use (&$inserito): void {
        if ($inserito || $connection->transactionLevel() < 1) {
            return;
        }

        $inserito = true;
        DB::table('users')->insert([
            'name' => 'Vincitore concorrente',
            'email' => 'corsa@example.test',
            'password' => bcrypt('tappo'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        (new InvitaUtente('Invito perdente', 'corsa@example.test', 'Tenant', $this->sede))->esegui();
        $this->fail('La collisione concorrente doveva essere tradotta in un rifiuto.');
    } catch (InvitoRifiutato $e) {
        expect($e->codice)->toBe(InvitoRifiutato::EMAIL_GIA_USATA);
    }

    expect(User::withTrashed()->where('email', 'corsa@example.test')->count())->toBe(1);
});

// ─── 4. `RuoliAssegnabili`: due ruoli non si conferiscono da nessuna UI ──────

it('never lets an interface confer a platform role', function (string $ruolo) {
    // ⛔ Non «non compaiono nella tendina»: **rifiutati**. Un ruolo assente da
    // una `<select>` è a un `$wire.set()` di distanza, e questi due portano
    // `tenants.view_all`, cioè la lettura sulle righe di tutti i clienti.
    expect(RuoliAssegnabili::ammesso($ruolo))->toBeFalse()
        ->and(RuoliAssegnabili::ammesso($ruolo, piattaforma: true))->toBeFalse();
})->with(['Superadmin', 'Developer']);

it('offers the four client roles, and only the tecnico on the platform side', function () {
    expect(RuoliAssegnabili::perCliente())
        ->toEqualCanonicalizing(['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'])
        ->and(RuoliAssegnabili::perPiattaforma())->toBe(['Tecnico']);
});

it('warns about the second factor exactly for the roles that impose it', function () {
    // La lista si legge da `config/rbac.php` e non si ribatte: due copie sono
    // due liste, e questa serve a dire il vero a chi conferisce il ruolo.
    expect(RuoliAssegnabili::imponeSecondoFattore('Admin'))->toBeTrue()
        ->and(RuoliAssegnabili::imponeSecondoFattore('Tenant'))->toBeFalse()
        ->and(RuoliAssegnabili::imponeSecondoFattore('Tecnico'))->toBeFalse();
});

// ─── 5. La tendina: ruolo Tecnico e portafoglio ─────────────────────────────

it('offers an EasyLab tecnico only on the clients they actually work on', function () {
    // 🔴 Il cambio del 29 Ago 2026. Prima: ogni tecnico di piattaforma era
    // assegnabile su ogni cliente, e con dieci tecnici veri ogni Admin cliente
    // leggeva l'organigramma di EasyLab in una `<select>`.
    $tecnico = User::factory()->create(['name' => 'Marco Tecnico', 'tenant_id' => null]);
    $tecnico->assignRole('Tecnico');

    $altraSede = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Lab Bianchi']);

    expect(Assegnabili::perSede($this->sede->id)->pluck('id')->all())->not->toContain($tecnico->id);

    $tecnico->portafoglioClienti()->attach($this->sede->id);

    expect(Assegnabili::perSede($this->sede->id)->pluck('id')->all())->toContain($tecnico->id)
        // E **solo** lì: il portafoglio è per sede, non per piattaforma.
        ->and(Assegnabili::perSede($altraSede->id)->pluck('id')->all())->not->toContain($tecnico->id);
});

it('offers only Tecnici inside the Ente too', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->sede->id]);
    $tecnico->assignRole('Tecnico');

    expect(Assegnabili::perSede($this->sede->id)->pluck('id')->all())
        ->toContain($tecnico->id)
        ->not->toContain($this->admin->id);
});

it('never lets a tenant-less user without the Tecnico role in through the portfolio', function () {
    // L'unione è sul **ruolo**, non sul solo tenant nullo: un utente di
    // piattaforma senza ruolo Tecnico non è un assegnatario, nemmeno se
    // qualcuno gli scrivesse una riga di portafoglio.
    $estraneo = User::factory()->create(['tenant_id' => null]);
    $estraneo->portafoglioClienti()->attach($this->sede->id);

    expect(Assegnabili::perSede($this->sede->id)->pluck('id')->all())->not->toContain($estraneo->id);
});
it('returns nobody when the assignee boundary has no Ente', function () {
    // `where('tenant_id', null)` è `IS NULL`: senza il ramo fail-closed qui
    // entrerebbero tutte le identità di piattaforma dal primo lato dell'unione.
    $identitaDiPiattaforma = User::factory()->create(['tenant_id' => null]);

    expect(Assegnabili::perSede(null)->pluck('id')->all())->toBe([])
        ->and(User::whereKey($identitaDiPiattaforma->id)->exists())->toBeTrue();
});

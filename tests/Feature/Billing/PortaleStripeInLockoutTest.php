<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Billing\PortaleStripe;
use App\Support\Rbac;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

/**
 * 🔴 **La decisione portante di questa feature, isolata in un file suo perché
 * è quella che un refactoring futuro romperebbe per distrazione.**
 *
 * ADR-013 chiama il lockout «leva di pagamento forte». Una leva ha bisogno di
 * uno scatto di rilascio: se l'unico modo di aggiornare una carta scaduta
 * stesse *dietro* il blocco, la leva sarebbe una porta murata e il moroso non
 * avrebbe alcun modo di sbloccarsi da solo. Le due rotte dell'abbonamento
 * stanno quindi nel gruppo `auth` **nudo**, accanto a `/bloccato`, e la
 * collocazione è **posizionale** — mai un'esclusione `routeIs` dentro
 * `EnforceAccountLockout`, che sugli update Livewire (rotta sempre
 * `/livewire/update`) non varrebbe.
 *
 * E fuori **anche** da `two-factor.enforce`, di conseguenza: in routes/web.php
 * il lockout parla PRIMA del 2FA di proposito, quindi un Admin di account
 * bloccato non viene mai spinto al setup — finisce su /bloccato. Enforcere il
 * 2FA qui creerebbe un vicolo cieco (/abbonamento → settings.security →
 * account.lockout → /bloccato) per esattamente la popolazione che questa
 * decisione esiste per servire.
 *
 * Ciascuno dei test qui sotto diventa rosso se qualcuno sposta le due rotte
 * dentro il gruppo `['auth', 'account.lockout', 'two-factor.enforce']`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    config(['cashier.secret' => 'sk_test_finta']);

    $this->account = Account::factory()->saas()->conStripe()->bloccato()->create([
        'ragione_sociale' => 'Gruppo Moroso',
    ]);
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Morosa']);

    $this->membro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->membro->assignRole('Admin');
    $this->account->aggiungiMembro($this->membro);
});

it('lets a member of a locked account open the page instead of bouncing to /bloccato', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Gruppo Moroso');
});

it('lets a member of a locked account open the portal', function () {
    $fake = new class extends PortaleStripe
    {
        /** @var list<string> */
        public array $invocazioni = [];

        public function url(Account $account, string $returnUrl): string
        {
            $this->invocazioni[] = $returnUrl;

            return 'https://billing.stripe.test/sessione-finta';
        }
    };
    app()->instance(PortaleStripe::class, $fake);

    $this->actingAs($this->membro)
        ->post(route('abbonamento.portale'))
        ->assertRedirect('https://billing.stripe.test/sessione-finta');

    expect($fake->invocazioni)->toBe([route('abbonamento.index')]);
});

it('lets a locked Admin without 2FA reach the page anyway', function () {
    // Il vicolo cieco che questo test esiste per rendere impossibile: il
    // lockout parla prima del 2FA, quindi questo Admin non viene MAI spinto al
    // setup — se /abbonamento fosse dentro `two-factor.enforce` non potrebbe
    // pagare né attivare il 2FA. Il costo dichiarato è che un Admin senza 2FA
    // apre questa pagina: è l'obbligo di *attivarlo* che si sospende, non
    // l'autenticazione, che è già avvenuta.
    expect($this->membro->two_factor_confirmed_at)->toBeNull()
        ->and(Rbac::twoFactorRequiredRoles())->toContain('Admin');

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk();
});

it('keeps both routes out of the lockout and the 2FA groups, by position', function () {
    // 🛡️ Guardrail STRUTTURALE accanto a quelli di comportamento: dice a chi
    // legge il diff *quale* decisione sta disfacendo, invece di lasciargli
    // dedurre da un 302 inatteso che «un test si è rotto».
    //
    // ⚠️ La collocazione fuori dai due gruppi è **posizionale** — le due rotte
    // stanno fisicamente accanto a /bloccato, e non c'è nessuna esclusione
    // `routeIs` dentro `EnforceAccountLockout`: quella forma non varrebbe sugli
    // update Livewire, dove la rotta è sempre /livewire/update.
    $middleware = fn (string $nome) => collect(Route::getRoutes()->getByName($nome)->gatherMiddleware());

    // `abbonamento.piano` (ADR-045) sta nello stesso ciclo per la stessa
    // ragione: chi è chiuso per disdetta riattiva da lì.
    foreach (['abbonamento.index', 'abbonamento.portale', 'abbonamento.piano'] as $nome) {
        expect($middleware($nome))->toContain('auth')
            ->and($middleware($nome))->not->toContain('account.lockout')
            ->and($middleware($nome))->not->toContain('two-factor.enforce');
    }

    // ⛔ E il POST porta il suo THROTTLE, che è l'unica difesa rimasta: ogni
    // richiesta crea una sessione di portale **su Stripe**, quindi senza limite
    // un doppio clic ostinato — o uno script — ne genererebbe a raffica per lo
    // stesso Account. Perso in un refactoring che sposta le due rotte in un
    // sotto-gruppo, nient'altro se ne accorgerebbe: la riga sta qui, nello
    // stesso ciclo, perché chi tocca le altre tre la legge per forza.
    expect($middleware('abbonamento.portale'))->toContain('throttle:10,1');
});

it('stops the eleventh portal request in the same minute', function () {
    // Il guardrail strutturale dice «c'è la riga»; questo dice «la riga
    // morde». Undici POST nello stesso minuto: il decimo passa, l'undicesimo
    // no, e Stripe riceve dieci sessioni e non undici.
    $fake = new class extends PortaleStripe
    {
        public int $aperture = 0;

        public function url(Account $account, string $returnUrl): string
        {
            $this->aperture++;

            return 'https://billing.stripe.test/sessione-finta';
        }
    };
    app()->instance(PortaleStripe::class, $fake);

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->membro)->post(route('abbonamento.portale'))->assertRedirect();
    }

    $this->actingAs($this->membro)
        ->post(route('abbonamento.portale'))
        ->assertStatus(429);

    expect($fake->aperture)->toBe(10);
});

it('still bounces the same user away from a protected page', function () {
    // La controprova che il lockout NON è stato spento: se questo test
    // diventasse verde su un redirect mancante, l'esenzione avrebbe smesso di
    // essere chirurgica.
    $this->actingAs($this->membro)
        ->get(route('dashboard'))
        ->assertRedirect(route('bloccato'));
});

it('offers the way out to pay on the /bloccato page', function () {
    $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertSee('Regolarizza il pagamento')
        ->assertSee(route('abbonamento.portale'));
});

it('never offers the way out to someone who does not administer the contract', function () {
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    $this->account->aggiungiMembro($tenant);

    $this->actingAs($tenant)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('Regolarizza il pagamento');
});

it('never offers the way out when the account has no Stripe customer', function () {
    // Un bottone che porta a un errore è peggio di nessun bottone: il piano
    // Free non ha customer per definizione (ADR-002).
    $this->account->forceFill(['stripe_id' => null])->save();

    $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('Regolarizza il pagamento');
});

it('never offers the way out to a Superadmin who is impersonating the member', function () {
    // 🔴 `Gate::forUser($user)` risponde sull'IMPERSONATO, quindi direbbe di
    // sì: senza la condizione esplicita, /bloccato offrirebbe allo staff un
    // bottone che `AperturaPortaleStripe` rifiuta — un vicolo cieco su una
    // pagina che esiste per indicare l'uscita.
    $staff = Account::factory()->create(['ragione_sociale' => 'EasyLab']);
    $sedeStaff = UnitaOrganizzativa::factory()->ente()->perAccount($staff)->create(['nome' => 'Sede Staff']);
    $super = User::factory()->create(['tenant_id' => $sedeStaff->id, 'two_factor_confirmed_at' => now()]);
    $super->assignRole('Superadmin');
    $staff->aggiungiMembro($super);

    $this->actingAs($super)->get(route('impersonate', $this->membro->id));

    $this->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('Regolarizza il pagamento');
});

it('never offers the way out on an environment without the Stripe secret', function () {
    config(['cashier.secret' => null]);

    $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('Regolarizza il pagamento');
});

/*
 |--------------------------------------------------------------------------
 | La copy del banner: è il CONTENUTO dell'esenzione, non una decorazione
 |--------------------------------------------------------------------------
 |
 | Prima del 28 Ago 2026 l'unico test che rendeva la pagina da bloccati
 | asseriva `assertOk()` + la ragione sociale — che compare comunque fuori dal
 | banner. Cancellando l'intero blocco `@if ($sospeso)` i test restavano tutti
 | verdi, e con loro spariva l'unica riga che dice al cliente che pagare non
 | riapre l'accesso nell'istante del pagamento.
 |
 | ⚠️ Aghi senza APOSTROFI: `assertSee` escapa l'ago, quindi «L'accesso è
 | sospeso» non si può cercare così com'è.
 */

it('warns the locked customer that paying does not reopen the door immediately', function () {
    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertSee('Il rientro non è immediato')
        ->assertSee('il pagamento da solo non la revoca');
});

it('never shows that warning to an account that is not suspended', function () {
    // La controprova: il banner è legato allo STATO, non stampato sempre. Senza
    // di essa, un `@if` cancellato del tutto passerebbe il test qui sopra.
    $this->account->sblocca();

    $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->assertDontSee('Il rientro non è immediato');
});

/*
 |--------------------------------------------------------------------------
 | 🔴 Il flash di errore deve essere RESO da /bloccato
 |--------------------------------------------------------------------------
 */

it('shows the failure on /bloccato, where the paying customer actually comes back', function () {
    // `AperturaPortaleStripe` torna con `back()`, e per il moroso «back» è
    // /bloccato — non /abbonamento. Prima del 28 Ago 2026 quella vista non
    // rendeva `erroreAbbonamento` da nessuna parte: il cliente premeva il
    // bottone, la pagina lampeggiava e tornava identica, senza una parola. Ed
    // è l'unica via d'uscita che ha, perché la dashboard lo rimbalza qui.
    app()->instance(PortaleStripe::class, new class extends PortaleStripe
    {
        public function url(Account $account, string $returnUrl): string
        {
            throw new RuntimeException('Stripe non risponde');
        }
    });

    $this->actingAs($this->membro)
        ->from(route('bloccato'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('bloccato'));

    $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertSee('Non è stato possibile aprire il portale di fatturazione');
});

it('shows the failure on /bloccato even when the button has meanwhile disappeared', function () {
    // Il ramo «customer sparito fra il render e il POST»: al ritorno
    // `$puoPagare` è falso, quindi un messaggio scritto DENTRO `@if
    // ($puoPagare)` sparirebbe proprio nel caso che lo rende necessario.
    $this->account->forceFill(['stripe_id' => null])->save();

    $this->actingAs($this->membro)
        ->from(route('bloccato'))
        ->post(route('abbonamento.portale'))
        ->assertRedirect(route('bloccato'));

    $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('Regolarizza il pagamento')
        ->assertSee('Il portale di fatturazione non è disponibile per questo account');
});

/*
 |--------------------------------------------------------------------------
 | 🔴 L'esenzione dal lockout non deve estendersi a ciò che il LAYOUT monta
 |--------------------------------------------------------------------------
 |
 | Livewire scrive nel `memo` dello snapshot il PATH della richiesta e, sugli
 | update, ricostruisce da quel path i middleware *persistenti*. `/abbonamento`
 | sta nel gruppo `auth` nudo, quindi quei middleware sono `['web','auth']` e
 | `EnforceAccountLockout` — registrato persistente in `AppServiceProvider`
 | proprio perché «senza, ogni azione Livewire aggirerebbe il blocco» — NON
 | gira per nessuno degli snapshot nati qui.
 |
 | Riprodotto il 28 Ago 2026 con il layout dell'app: POST su /livewire/update
 | con lo snapshot di `notifiche.campanella` preso da /abbonamento →
 | `segnaTutteLette` eseguito con HTTP 200 su un account BLOCCATO; stesso
 | componente e stessa azione con lo snapshot preso da /dashboard → 302 verso
 | /bloccato. Il rimedio è quello che `/bloccato` applica da sempre: sulla
 | pagina esente non si monta niente che si possa azionare.
 */

it('mounts nothing from the layout on the page the lockout does not guard', function () {
    $html = $this->actingAs($this->membro)
        ->get(route('abbonamento.index'))
        ->assertOk()
        ->getContent();

    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $trovati);

    $componenti = collect($trovati[1])
        ->map(fn (string $grezzo) => json_decode(html_entity_decode($grezzo, ENT_QUOTES), true)['memo']['name'] ?? '?')
        ->all();

    // Un solo componente: la pagina stessa, che non ha azioni (v. il suo
    // docblock). Con il layout dell'app ce n'erano QUATTRO, e tre di quelli
    // scrivono.
    expect($componenti)->toBe(['billing.pagina-abbonamento']);
});

it('mounts no Livewire component at all on /bloccato', function () {
    // La stessa regola sull'altra pagina esente, che la rispetta per
    // costruzione (x-guest-layout): scritta perché smetta di essere solo un
    // commento nel docblock di `PaginaBloccato`.
    $html = $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('wire:snapshot');
});

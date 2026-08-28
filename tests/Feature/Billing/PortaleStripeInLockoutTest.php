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

    foreach (['abbonamento.index', 'abbonamento.portale'] as $nome) {
        expect($middleware($nome))->toContain('auth')
            ->and($middleware($nome))->not->toContain('account.lockout')
            ->and($middleware($nome))->not->toContain('two-factor.enforce');
    }
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

it('never offers the way out on an environment without the Stripe secret', function () {
    config(['cashier.secret' => null]);

    $this->actingAs($this->membro)
        ->get(route('bloccato'))
        ->assertOk()
        ->assertDontSee('Regolarizza il pagamento');
});

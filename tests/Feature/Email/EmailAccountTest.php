<?php

use App\Livewire\Piattaforma\Cabina;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\AccountBloccato;
use App\Notifications\PianoCambiato;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Piani;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\BancoAbbonamento;

/**
 * Le due email sull'account: il blocco e il cambio di piano (🔗 ADR-047;
 * ADR-013 il lockout, ADR-045 il piano).
 *
 * Partono dai metodi di dominio di `Account`, quindi da **ogni** strada che
 * produce il fatto — la cabina, il webhook di Stripe, un comando. Per questo i
 * casi qui chiamano quei metodi e non una schermata.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create(['ragione_sociale' => 'Gruppo Rossi']);
    $ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede di Milano']);

    $membro = function (string $nome) use ($ente): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => $ente->id]);
        $u->assignRole('Admin');
        $this->account->aggiungiMembro($u);

        return $u->fresh();
    };

    $this->titolare = $membro('Rosa Rossi');
    $this->socio = $membro('Remo Rossi');

    // Un Tenant dell'Ente che NON è membro dell'account: il piano e il blocco
    // non sono affar suo.
    $this->tenant = User::factory()->create(['tenant_id' => $ente->id]);
    $this->tenant->assignRole('Tenant');

    Notification::fake();
});

// ─── Bloccato ────────────────────────────────────────────────────────────────

it('sends nothing about a block while the email is off', function () {
    $this->account->blocca('Fattura scaduta da 60 giorni');
    $this->account->bloccaPerStripe('invoice.payment_failed');

    Notification::assertNothingSent();
});

it('tells the members of the account that the access is suspended', function (string $sorgente) {
    InterruttoriEmail::imposta(CatalogoEmail::ACCOUNT_BLOCCATO, true);

    $sorgente === 'a mano'
        ? $this->account->blocca('Fattura scaduta da 60 giorni')
        : $this->account->bloccaPerStripe('invoice.payment_failed');

    Notification::assertSentTo([$this->titolare, $this->socio], fn (AccountBloccato $n) => $n->ragioneSociale === 'Gruppo Rossi');
    Notification::assertNotSentTo($this->tenant, AccountBloccato::class);
})->with(['a mano', 'da Stripe']);

it('never writes the reason of the block in the email', function () {
    // 🔗 ADR-013: il motivo è un'annotazione interna, e nemmeno `/bloccato` lo mostra.
    InterruttoriEmail::imposta(CatalogoEmail::ACCOUNT_BLOCCATO, true);

    $this->account->blocca('Contenzioso aperto con lo studio legale');

    Notification::assertSentTo($this->titolare, function (AccountBloccato $n) {
        $html = (string) $n->toMail($this->titolare)->render();

        return ! str_contains($html, 'Contenzioso') && str_contains($html, 'Gruppo Rossi');
    });
});

it('writes once when the door closes, not again when the second source arrives', function () {
    // L'account è già chiuso: per chi vi lavora non cambia nulla, e una
    // seconda email sarebbe rumore.
    InterruttoriEmail::imposta(CatalogoEmail::ACCOUNT_BLOCCATO, true);

    $this->account->blocca('Fattura scaduta da 60 giorni');
    $this->account->bloccaPerStripe('invoice.payment_failed');
    $this->account->blocca('Ripetuto');

    Notification::assertSentToTimes($this->titolare, AccountBloccato::class, 1);
});

it('writes again when the account is reopened and then closed a second time', function () {
    InterruttoriEmail::imposta(CatalogoEmail::ACCOUNT_BLOCCATO, true);

    $this->account->blocca('Primo insoluto');
    $this->account->sblocca();
    $this->account->blocca('Secondo insoluto');

    Notification::assertSentToTimes($this->titolare, AccountBloccato::class, 2);
});

it('never writes about the platform account', function () {
    InterruttoriEmail::imposta(CatalogoEmail::ACCOUNT_BLOCCATO, true);
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);

    $easylab = Account::factory()->diPiattaforma()->create();
    $direzione = User::factory()->create();
    $easylab->aggiungiMembro($direzione);

    $easylab->blocca('Per errore');
    $easylab->cambiaPiano('saas');

    Notification::assertNothingSent();
});

// ─── Piano cambiato ──────────────────────────────────────────────────────────

it('sends nothing about a plan change while the email is off', function () {
    $this->account->cambiaPiano('saas');

    Notification::assertNothingSent();
});

it('tells the members of the account that the plan changed, from what to what', function () {
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);

    $this->account->cambiaPiano('saas');

    Notification::assertSentTo(
        [$this->titolare, $this->socio],
        fn (PianoCambiato $n) => $n->ragioneSociale === 'Gruppo Rossi'
            && $n->da === Piani::etichetta('free')
            && $n->a === Piani::etichetta('saas'),
    );
    Notification::assertNotSentTo($this->tenant, PianoCambiato::class);
});

it('says nothing when the plan stays the same', function () {
    // Un webhook ripetuto non deve scrivere due volte.
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);

    $this->account->cambiaPiano('saas');
    $this->account->cambiaPiano('saas');

    Notification::assertSentToTimes($this->titolare, PianoCambiato::class, 1);
});

it('says nothing at the birth of the account, where the plan does not change', function () {
    // A dirlo sono già l'invito o il benvenuto.
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);

    $this->account->cambiaPiano('saas', annuncia: false);

    expect($this->account->fresh()->piano)->toBe('saas');

    Notification::assertNothingSent();
});

it('does not announce a plan change to a client created from the cabina with another free plan', function () {
    // La strada vera della nascita: la modale «Nuovo cliente» con un piano
    // gratuito diverso dal predefinito.
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);

    BancoAbbonamento::pianoSenzaPrice('convenzione', 0, ['gratuito' => true, 'etichetta' => 'Convenzione', 'max_enti' => 3]);

    $superadmin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $superadmin->assignRole('Superadmin');
    $this->actingAs($superadmin->fresh());

    Livewire::test(Cabina::class)
        ->call('apriProvisioning')
        ->set('nuovo.nome', 'Laboratori Verdi')
        ->set('nuovo.adminEmail', 'admin@verdi.test')
        ->set('nuovo.adminName', 'Anna Verdi')
        ->set('nuovo.piano', 'convenzione')
        ->call('creaCliente')
        ->assertHasNoErrors();

    expect(Account::where('ragione_sociale', 'Laboratori Verdi')->firstOrFail()->piano)->toBe('convenzione');

    Notification::assertNotSentTo(User::where('email', 'admin@verdi.test')->firstOrFail(), PianoCambiato::class);
});

// ─── Ciò che non deve dipendere dall'email ───────────────────────────────────

it('blocks the account and changes the plan even when the email cannot be queued', function () {
    // 🔴 Qui arriva anche il webhook di Stripe: una coda irraggiungibile non
    // deve fargli rispondere 500, o Stripe riproverebbe un blocco già scritto.
    InterruttoriEmail::imposta(CatalogoEmail::ACCOUNT_BLOCCATO, true);
    InterruttoriEmail::imposta(CatalogoEmail::PIANO_CAMBIATO, true);

    $this->app->bind(Dispatcher::class, fn () => new class
    {
        public function send(mixed ...$argomenti): void
        {
            throw new RuntimeException('coda irraggiungibile');
        }
    });

    $this->account->bloccaPerStripe('invoice.payment_failed');
    $this->account->cambiaPiano('saas');

    expect($this->account->fresh()->is_locked)->toBeTrue()
        ->and($this->account->fresh()->piano)->toBe('saas');
});

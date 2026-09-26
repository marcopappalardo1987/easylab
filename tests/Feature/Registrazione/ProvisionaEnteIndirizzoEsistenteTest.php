<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Provisioning\ProvisioningRifiutato;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * 🔴 «Voglio un cliente nuovo» non promuove nessuno che esista già
 * (🔗 ADR-012, ADR-018, ADR-032; caccia T3, A2 e A9).
 *
 * Il self-signup e il Payment Link chiamano `ProvisionaEnte` con
 * `esigiAccountNuovo: true`. Un utente esistente senza account (un Tecnico, un
 * Referente) passava il ramo «amministra già», e `assignRole('Admin')` lo
 * faceva Admin del tenant in cui vive. Ogni confronto d'indirizzo è
 * indifferente alle maiuscole, come il login di Fortify.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->enteBeta = UnitaOrganizzativa::factory()->ente()
        ->perAccount(Account::factory()->saas()->create(['ragione_sociale' => 'Laboratorio Beta']))
        ->create(['nome' => 'Beta']);

    $this->tecnico = User::factory()->create(['email' => 'luca@laboratorio-beta.it']);
    $this->tecnico->forceFill(['tenant_id' => $this->enteBeta->id])->save();
    $this->tecnico->assignRole('Tecnico');
});

function nuovoClientePer(string $email): ProvisionaEnte
{
    return new ProvisionaEnte(
        nome: 'Ditta Luca',
        adminEmail: $email,
        adminName: 'Luca Verdi',
        esigiAccountNuovo: true,
        passwordHash: bcrypt('PasswordScelta!1'),
    );
}

it('refuses a new customer whose admin address already belongs to a plain user', function (string $email) {
    $account = Account::query()->count();
    $enti = UnitaOrganizzativa::query()->count();

    expect(fn () => nuovoClientePer($email)->esegui())->toThrow(ProvisioningRifiutato::class);

    expect($this->tecnico->fresh()->hasRole('Admin'))->toBeFalse()
        ->and($this->tecnico->fresh()->tenant_id)->toBe($this->enteBeta->id)
        ->and(Account::query()->count())->toBe($account)
        ->and(UnitaOrganizzativa::query()->count())->toBe($enti)
        ->and(User::query()->count())->toBe(1);
})->with([
    'stesso indirizzo' => ['luca@laboratorio-beta.it'],
    'maiuscole e spazi' => ['  Luca@Laboratorio-Beta.IT '],
]);

it('refuses a new customer whose admin address belongs to a trashed user, whatever its case', function () {
    $this->tecnico->delete();

    expect(fn () => nuovoClientePer('LUCA@laboratorio-beta.it')->esegui())
        ->toThrow(ProvisioningRifiutato::class);

    expect(User::withTrashed()->count())->toBe(1);
});

it('attaches a sede to the existing admin whatever the case of the address, without a twin user', function () {
    // Il gesto «aggiungi una sede» (senza `esigiAccountNuovo`) resta quello di
    // sempre: l'Admin esistente si riusa col suo account, e in maiuscolo è la
    // stessa persona.
    $accountBeta = $this->enteBeta->account;
    $anna = User::factory()->create(['email' => 'anna@laboratorio-beta.it']);
    $anna->forceFill(['tenant_id' => $this->enteBeta->id])->save();
    $anna->assignRole('Admin');
    $accountBeta->aggiungiMembro($anna);

    $esito = (new ProvisionaEnte(
        nome: 'Laboratorio Beta',
        adminEmail: 'Anna@Laboratorio-Beta.it',
        adminName: 'Anna Neri',
        nomeSede: 'Beta Due',
    ))->esegui();

    expect($esito->admin->is($anna))->toBeTrue()
        ->and($esito->account->is($accountBeta))->toBeTrue()
        ->and(User::query()->where('email', 'like', '%anna%')->count())->toBe(1);
});

it('stores a newly born admin with the address in lowercase', function () {
    $esito = nuovoClientePer('Marta@Laboratorio-Aurora.it')->esegui();

    expect($esito->admin->email)->toBe('marta@laboratorio-aurora.it');
});

// ─── 🔴 «Aggiungi una sede» (accountId) non promuove chi c'è già (caccia T1aB) ─

function sedePerAccount(Account $account, string $email): ProvisionaEnte
{
    return new ProvisionaEnte(
        nome: $account->ragione_sociale,
        adminEmail: $email,
        adminName: 'Chiunque',
        accountId: $account->id,
        nomeSede: 'Sede di Bergamo',
    );
}

it('refuses a sede whose admin address belongs to someone who is not an Admin of that customer', function (string $chi) {
    $clienteX = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($clienteX)->create(['nome' => 'Sede di Milano']);

    $utente = match ($chi) {
        // Un utente Tenant del cliente Y: diventava Admin in Y e membro di X.
        'tenant di Y' => tap(User::factory()->create(['tenant_id' => $this->enteBeta->id]))
            ->assignRole(User::TENANT_ROLE),
        // Il Superadmin con la propria email: membro di X, dentro dallo switcher.
        'superadmin' => tap(User::factory()->create())->assignRole('Superadmin'),
        // Un Admin, ma di un altro cliente.
        'admin di Y' => tap(User::factory()->create(['tenant_id' => $this->enteBeta->id]), function (User $u) {
            $u->assignRole('Admin');
            $this->enteBeta->account->aggiungiMembro($u);
        }),
        // Membro di X ma senza il ruolo Admin: nessuna promozione.
        'tecnico membro di X' => tap(User::factory()->create(), function (User $u) use ($clienteX) {
            $u->assignRole('Tecnico');
            $clienteX->aggiungiMembro($u);
        }),
    };

    $ruoliPrima = $utente->getRoleNames()->all();
    $entiPrima = UnitaOrganizzativa::query()->count();

    expect(fn () => sedePerAccount($clienteX, strtoupper($utente->email))->esegui())
        ->toThrow(ProvisioningRifiutato::class);

    expect($utente->fresh()->getRoleNames()->all())->toBe($ruoliPrima)
        ->and($utente->fresh()->hasRole('Admin'))->toBe($chi === 'admin di Y')
        ->and($clienteX->membri()->whereKey($utente->id)->exists())->toBe($chi === 'tecnico membro di X')
        ->and(UnitaOrganizzativa::query()->count())->toBe($entiPrima);
})->with(['tenant di Y', 'superadmin', 'admin di Y', 'tecnico membro di X']);

it('adds the sede when the address belongs to an Admin already member of that customer', function () {
    $clienteX = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($clienteX)->create(['nome' => 'Sede di Milano']);

    $admin = User::factory()->create(['email' => 'anna@rossi.test']);
    $admin->assignRole('Admin');
    $clienteX->aggiungiMembro($admin);

    $esito = sedePerAccount($clienteX, 'Anna@Rossi.test')->esegui();

    expect($esito->admin->is($admin))->toBeTrue()
        ->and($esito->account->is($clienteX))->toBeTrue()
        ->and($esito->ente->nome)->toBe('Sede di Bergamo');
});

it('adds the sede with a brand new admin when the address is unknown', function () {
    $clienteX = Account::factory()->saas()->create(['ragione_sociale' => 'Gruppo Rossi']);
    UnitaOrganizzativa::factory()->ente()->perAccount($clienteX)->create(['nome' => 'Sede di Milano']);

    $esito = sedePerAccount($clienteX, 'nuova@rossi.test')->esegui();

    expect($esito->adminNuovo)->toBeTrue()
        ->and($clienteX->membri()->whereKey($esito->admin->id)->exists())->toBeTrue();
});

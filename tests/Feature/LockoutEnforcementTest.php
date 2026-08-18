<?php

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * L'enforcement del lockout (🔗 ADR-013) — gemello di TwoFactorEnforcementTest.
 *
 * Il test chiave è il POST **reale** a /livewire/update: i middleware
 * persistenti saltano `Livewire::test()` (il vendor riconosce le richieste
 * fake), quindi solo una richiesta HTTP vera prova che la registrazione
 * `addPersistentMiddleware` funzioni — cioè che le azioni Livewire, che sono
 * quasi tutte le scritture dell'app, non aggirino il blocco.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = Account::factory()->create();
    $this->ente = UnitaOrganizzativa::factory()->ente()->perAccount($this->account)->create(['nome' => 'Sede Unica']);

    $this->membro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->membro->assignRole('Tenant'); // ruolo senza obbligo 2FA: si testa il solo lockout
    $this->account->aggiungiMembro($this->membro);
});

/** Estrae il primo wire:snapshot dall'HTML, come fa il vendor stesso. */
function snapshotDa(string $html): string
{
    $grezzo = str($html)->betweenFirst('wire:snapshot="', '"')->toString();

    return html_entity_decode($grezzo, ENT_QUOTES);
}

it('redirects every request of the protected group to /bloccato', function () {
    $this->account->blocca('Insoluto');

    $this->actingAs($this->membro)
        ->get('/dashboard')
        ->assertRedirect(route('bloccato'));
});

it('speaks before the per-route can: an Admin with 2FA still bounces', function () {
    // Admin con 2FA confermata: né il 2FA né il `can:strumenti.view` devono
    // parlare prima del lockout.
    $admin = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Admin');
    $this->account->blocca('Insoluto');

    $this->actingAs($admin)
        ->get('/strumenti')
        ->assertRedirect(route('bloccato'));
});

it('blocks a real Livewire update, thanks to the persistent registration', function () {
    // 1. Da sano: si carica la dashboard e si cattura lo snapshot di un
    //    componente del layout (il primo della pagina: qualunque sia, il
    //    metodo $refresh è valido su tutti).
    $html = $this->actingAs($this->membro)->get('/dashboard')->assertOk()->getContent();
    $snapshot = snapshotDa($html);
    expect($snapshot)->not->toBe('');

    // 2. Scatta il lockout. Riautenticazione con istanza fresca: l'istanza di
    //    actingAs sopravvive fra le richieste del test e la GET del passo 1 le
    //    ha già cacheato ente→account (sano). In produzione l'utente si
    //    ricarica a ogni richiesta. Lo snapshot resta valido: è firmato, non
    //    legato all'istanza.
    $this->account->blocca('Insoluto');
    auth()->logout();
    $this->actingAs($this->membro->fresh());

    // 3. L'update Livewire successivo — la forma di ogni azione dell'app —
    //    deve rimbalzare su /bloccato.
    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ])->assertRedirect(route('bloccato'));
});

it('lets the same Livewire update through when the account is healthy', function () {
    // La controprova del test sopra: stessa richiesta, account sano → 200.
    $html = $this->actingAs($this->membro)->get('/dashboard')->assertOk()->getContent();
    $snapshot = snapshotDa($html);

    $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ])->assertOk();
});

it('keeps the logout reachable', function () {
    $this->account->blocca('Insoluto');

    $this->actingAs($this->membro)->post('/logout')->assertRedirect();
    $this->assertGuest();
});

it('restores the access after the sblocco', function () {
    $this->account->blocca('Insoluto');
    $this->actingAs($this->membro)->get('/dashboard')->assertRedirect(route('bloccato'));

    $this->account->sblocca();

    // Riautenticazione con un'istanza fresca: nei test l'istanza di actingAs
    // sopravvive fra le richieste e porta con sé le relazioni ente→account già
    // caricate. In produzione l'utente è ricaricato a ogni richiesta — la
    // cache di relazione vive UNA richiesta, che è esattamente il suo scopo.
    auth()->logout();
    $this->actingAs($this->membro->fresh())->get('/dashboard')->assertOk();
});

it('never touches a user without a tenant', function () {
    $esterno = User::factory()->create(['tenant_id' => null]);
    $esterno->assignRole('Tecnico');

    $this->actingAs($esterno)->get('/campo')->assertOk();
});

it('never touches a user whose ente has no account', function () {
    // Fail-open dichiarato: senza rapporto commerciale non c'è nulla da
    // sospendere.
    $senzaAccount = UnitaOrganizzativa::factory()->ente()->create();
    $utente = User::factory()->create(['tenant_id' => $senzaAccount->id]);
    $utente->assignRole('Tenant');

    $this->actingAs($utente)->get('/dashboard')->assertOk();
});

it('lets the Superadmin in through impersonation — the GDPR safeguard', function () {
    $sedeSuperadmin = UnitaOrganizzativa::factory()->ente()
        ->perAccount(Account::factory()->create())->create();
    $superadmin = User::factory()->create([
        'tenant_id' => $sedeSuperadmin->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $superadmin->assignRole('Superadmin');

    $this->account->blocca('Insoluto');

    // Il membro diretto resta fuori…
    $this->actingAs($this->membro)->get('/dashboard')->assertRedirect(route('bloccato'));
    auth()->logout();

    // …ma il Superadmin che lo impersona entra: assistenza ed export dei dati
    // valgono anche in lockout (ADR-013), e ogni passo è già auditato.
    $this->actingAs($superadmin)->get(route('impersonate', $this->membro));

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Stai impersonando');
});

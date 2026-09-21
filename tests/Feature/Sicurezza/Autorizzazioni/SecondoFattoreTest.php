<?php

use App\Livewire\Settings\TwoFactorAuthentication;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * T1a (S7) — la pagina del secondo fattore (repro dei cacciatori T1aB-1/2).
 *
 * T1aB-1: i flag di visualizzazione erano pubblici e mutabili, e `render()`
 * mostrava segreto TOTP e codici di recupero senza guardare l'impersonazione.
 * T1aB-2: disabilitare, rigenerare e rivedere il fattore confermato non
 * chiedevano la password recente che Fortify (`confirmPassword => true`)
 * pretende sulle proprie rotte.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->segreto = app(Google2FA::class)->generateSecretKey();
    $ente = UnitaOrganizzativa::factory()->ente()->create();

    $this->admin = User::factory()->create(['tenant_id' => $ente->id]);
    $this->admin->assignRole('Admin');
    $this->admin->forceFill([
        'two_factor_secret' => encrypt($this->segreto),
        'two_factor_recovery_codes' => encrypt(json_encode(['codice-riserva-1', 'codice-riserva-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->impersona = function (): void {
        $developer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $developer->assignRole('Developer');
        $this->actingAs($developer)->get(route('impersonate', $this->admin))->assertRedirect();
    };
});

it('does not let the browser flip the display flags', function (string $flag) {
    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->set($flag, true);
})->with(['showingQrCode', 'showingRecoveryCodes'])
    ->throws(CannotUpdateLockedPropertyException::class);

it('never renders the secret or the recovery codes to an impersonator, even with a fresh password', function () {
    ($this->impersona)();
    session()->put('auth.password_confirmed_at', time());

    Livewire::test(TwoFactorAuthentication::class)
        ->assertDontSee($this->segreto)
        ->assertDontSee('codice-riserva-1')
        ->assertViewHas('setupKey', null)
        ->assertViewHas('recoveryCodes', []);
});

it('does not render the secret of an unconfirmed setup to an impersonator', function () {
    $this->admin->forceFill(['two_factor_confirmed_at' => null])->save();
    ($this->impersona)();

    Livewire::test(TwoFactorAuthentication::class)
        ->assertDontSee($this->segreto)
        ->assertViewHas('setupKey', null);
});

it('does not reveal the confirmed secret through enable() without a fresh password', function () {
    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->call('enable')
        ->assertRedirect(route('password.confirm'))
        ->assertDontSee($this->segreto);
});

it('reveals the confirmed secret again once the password is fresh', function () {
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->call('enable')
        ->assertSee($this->segreto);
});

it('does not disable a confirmed factor without a fresh password, and sends to the confirmation', function () {
    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->call('disable')
        ->assertRedirect(route('password.confirm'));

    expect($this->admin->fresh()->two_factor_secret)->not->toBeNull();
    expect(session('url.intended'))->toBe(route('settings.security'));
});

it('does not regenerate the recovery codes without a fresh password', function () {
    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->call('regenerateRecoveryCodes')
        ->assertRedirect(route('password.confirm'));

    expect($this->admin->fresh()->recoveryCodes())->toBe(['codice-riserva-1', 'codice-riserva-2']);
});

it('treats a password confirmed before the timeout as stale', function () {
    session()->put('auth.password_confirmed_at', time() - (int) config('auth.password_timeout') - 1);

    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->call('disable')
        ->assertRedirect(route('password.confirm'));

    expect($this->admin->fresh()->two_factor_secret)->not->toBeNull();
});

it('disables and regenerates with a fresh password', function () {
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->call('regenerateRecoveryCodes')
        ->assertNoRedirect();

    expect($this->admin->fresh()->recoveryCodes())->not->toContain('codice-riserva-1');

    Livewire::actingAs($this->admin)
        ->test(TwoFactorAuthentication::class)
        ->call('disable')
        ->assertNoRedirect();

    expect($this->admin->fresh()->two_factor_secret)->toBeNull();
});

it('lets a user without a factor set it up without a password prompt (forced first setup)', function () {
    $nuovo = User::factory()->create();

    $scheda = Livewire::actingAs($nuovo)
        ->test(TwoFactorAuthentication::class)
        ->call('enable')
        ->assertNoRedirect()
        ->assertSet('showingQrCode', true);

    $segreto = decrypt($nuovo->fresh()->two_factor_secret);
    $scheda->assertSee($segreto)
        ->set('code', app(Google2FA::class)->getCurrentOtp($segreto))
        ->call('confirm')
        ->assertNoRedirect();

    // I codici appena nati si vedono una volta, nella risposta della conferma.
    $codici = $nuovo->fresh()->recoveryCodes();
    expect($codici)->toHaveCount(8);
    $scheda->assertSee($codici[0]);
});

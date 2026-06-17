<?php

use App\Livewire\Settings\TwoFactorAuthentication;
use App\Models\User;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

it('enables and confirms two-factor authentication', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(TwoFactorAuthentication::class)
        ->call('enable')
        ->assertSet('showingQrCode', true);

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();

    $secret = decrypt($user->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $component->set('code', $code)
        ->call('confirm')
        ->assertSet('showingRecoveryCodes', true);

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
    expect($user->recoveryCodes())->toHaveCount(8);
});

it('rejects an invalid confirmation code', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TwoFactorAuthentication::class)
        ->call('enable')
        ->set('code', '000000')
        ->call('confirm')
        ->assertHasErrors('code');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('disables two-factor authentication', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(TwoFactorAuthentication::class)->call('enable');
    $user->refresh();
    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
    $component->set('code', $code)->call('confirm');

    $component->call('disable');

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('redirects to the two-factor challenge at login when 2FA is confirmed', function () {
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->create(['password' => bcrypt('secret-pw-123')]);
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'secret-pw-123',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

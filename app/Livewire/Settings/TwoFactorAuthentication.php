<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Gestione 2FA (TOTP) dell'utente autenticato: abilita → mostra QR + recovery
 * codes → conferma con codice → disabilita. Richiama direttamente le Action di
 * Fortify (l'utente è già autenticato in sessione).
 */
#[Layout('components.layouts.app')]
class TwoFactorAuthentication extends Component
{
    public bool $showingQrCode = false;

    public bool $showingRecoveryCodes = false;

    public string $code = '';

    public function enable(EnableTwoFactorAuthentication $enable): void
    {
        $enable($this->user());
        $this->showingQrCode = true;
        $this->showingRecoveryCodes = false;
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        $confirm($this->user(), $this->code);

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->code = '';
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $generate($this->user());
        $this->showingRecoveryCodes = true;
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        $disable($this->user());

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = false;
        $this->code = '';
    }

    public function getEnabledProperty(): bool
    {
        return ! is_null($this->user()->two_factor_secret);
    }

    public function getConfirmedProperty(): bool
    {
        return ! is_null($this->user()->two_factor_confirmed_at);
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render()
    {
        $user = $this->user();

        $showQr = $this->enabled && (! $this->confirmed || $this->showingQrCode);

        return view('livewire.settings.two-factor-authentication', [
            'qrCode' => $showQr ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $showQr ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => ($this->showingRecoveryCodes && $this->confirmed) ? $user->recoveryCodes() : [],
        ]);
    }
}

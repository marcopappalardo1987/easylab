<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Lab404\Impersonate\Services\ImpersonateManager;
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
 *
 * 🔴 **Nessuna di queste quattro azioni è disponibile durante
 * un'impersonazione** (ADR-018), e non è una comodità tolta: è il confine fra
 * «guardare con gli occhi di un altro» e «prendere le sue credenziali».
 *
 * Il secondo fattore è l'unica cosa che il cliente possiede e noi no. Chi
 * impersona, premendo «Abilita», legherebbe il TOTP del cliente alla **propria**
 * app di autenticazione: da lì in avanti quel cliente non entrerebbe più senza
 * chiedere un codice a noi. Premendo «Disabilita» glielo toglierebbe. E il
 * registro di audit direbbe in entrambi i casi che è stato **lui** a farlo,
 * perché l'utente della sessione è l'impersonato — l'impersonazione ha un
 * banner e una riga di audit propri, ma le azioni che compie restano intestate
 * a chi si impersona.
 *
 * ⛔ Il blocco vive QUI, nelle azioni, e non nella sola vista: le property di
 * un componente Livewire sono pubbliche e i metodi si chiamano da `$wire`. Una
 * schermata che nasconde i bottoni lascia le azioni raggiungibili — è il
 * difetto già trovato quattro volte su questo progetto.
 */
#[Layout('components.layouts.app')]
class TwoFactorAuthentication extends Component
{
    public bool $showingQrCode = false;

    public bool $showingRecoveryCodes = false;

    public string $code = '';

    /**
     * ⛔ Il cancello delle quattro azioni. `abort(403)` e non un messaggio
     * gentile: non è un gesto da riprovare più tardi, è un gesto che chi
     * impersona non deve poter compiere.
     */
    private function vietaSeImpersonando(): void
    {
        abort_if(app(ImpersonateManager::class)->isImpersonating(), 403);
    }

    public function enable(EnableTwoFactorAuthentication $enable): void
    {
        $this->vietaSeImpersonando();

        $enable($this->user());
        $this->showingQrCode = true;
        $this->showingRecoveryCodes = false;
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        $this->vietaSeImpersonando();

        $confirm($this->user(), $this->code);

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->code = '';
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $this->vietaSeImpersonando();

        $generate($this->user());
        $this->showingRecoveryCodes = true;
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        $this->vietaSeImpersonando();

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

<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Lab404\Impersonate\Services\ImpersonateManager;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
 *
 * ## Password recente per il fattore già confermato (S7, T1a)
 *
 * Fortify ha `confirmPassword => true`, ma le sue rotte non passano da qui: il
 * componente chiama le Action direttamente. Disabilitare, rigenerare i codici
 * e rivedere segreto o codici di un fattore **confermato** chiedono quindi una
 * password confermata da meno di `auth.password_timeout`, altrimenti si va su
 * `password.confirm` e si torna qui. La **prima attivazione** (enable →
 * confirm) ne è esente di proposito: è il percorso imposto da
 * `two-factor.enforce` subito dopo il login, e chi non ha ancora un fattore non
 * ha niente da farsi rubare.
 *
 * I due flag di visualizzazione sono `#[Locked]`: li muovono solo le azioni.
 * E `render()` non mostra mai segreto né codici a chi impersona.
 */
#[Layout('components.layouts.app')]
class TwoFactorAuthentication extends Component
{
    #[Locked]
    public bool $showingQrCode = false;

    #[Locked]
    public bool $showingRecoveryCodes = false;

    public string $code = '';

    /**
     * Vale solo per la risposta in corso (le property private non viaggiano
     * nello snapshot): i codici appena prodotti da `confirm()` si mostrano
     * una volta anche senza password recente, perché chi li vede ha appena
     * provato di possedere il TOTP.
     */
    private bool $codiciAppenaGenerati = false;

    /**
     * ⛔ Il cancello delle quattro azioni. `abort(403)` e non un messaggio
     * gentile: non è un gesto da riprovare più tardi, è un gesto che chi
     * impersona non deve poter compiere.
     */
    private function vietaSeImpersonando(): void
    {
        abort_if($this->impersonando(), 403);
    }

    private function impersonando(): bool
    {
        return app(ImpersonateManager::class)->isImpersonating();
    }

    /** Stessa regola di `RequirePassword`, che qui non gira (nessun middleware di rotta). */
    private function passwordRecente(): bool
    {
        $confermata = (int) session('auth.password_confirmed_at', 0);

        return time() - $confermata < (int) config('auth.password_timeout', 10800);
    }

    /** `true` se l'azione può proseguire; altrimenti manda a confermare la password. */
    private function esigiPasswordRecente(): bool
    {
        if ($this->passwordRecente()) {
            return true;
        }

        redirect()->setIntendedUrl(route('settings.security'));
        $this->redirectRoute('password.confirm');

        return false;
    }

    public function enable(EnableTwoFactorAuthentication $enable): void
    {
        $this->vietaSeImpersonando();

        if ($this->confirmed && ! $this->esigiPasswordRecente()) {
            return;
        }

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
        $this->codiciAppenaGenerati = true;
        $this->code = '';
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $this->vietaSeImpersonando();

        if (! $this->esigiPasswordRecente()) {
            return;
        }

        $generate($this->user());
        $this->showingRecoveryCodes = true;
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        $this->vietaSeImpersonando();

        if ($this->confirmed && ! $this->esigiPasswordRecente()) {
            return;
        }

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
        $riservato = ! $this->impersonando();

        // Fattore in configurazione: il QR serve a finire il setup. Fattore
        // confermato: si rivede solo su richiesta e con password recente.
        $showQr = $riservato && $this->enabled && (
            ! $this->confirmed || ($this->showingQrCode && $this->passwordRecente())
        );

        $showCodes = $riservato && $this->showingRecoveryCodes && $this->confirmed
            && ($this->codiciAppenaGenerati || $this->passwordRecente());

        return view('livewire.settings.two-factor-authentication', [
            'qrCode' => $showQr ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $showQr ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $showCodes ? $user->recoveryCodes() : [],
        ]);
    }
}

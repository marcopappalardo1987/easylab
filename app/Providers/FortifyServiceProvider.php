<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Risposta uniforme al «password dimenticata»: il messaggio d'errore di
        // Fortify diceva a chiunque se un indirizzo è cliente (S7, security pass).
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            fn () => new SuccessfulPasswordResetLinkRequestResponse(Password::RESET_LINK_SENT),
        );

        // E il form del token diceva `passwords.user` all'estraneo e
        // `passwords.token` al cliente: sempre la risposta del token non valido.
        $this->app->singleton(
            FailedPasswordResetResponseContract::class,
            fn () => new FailedPasswordResetResponse(Password::INVALID_TOKEN),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Viste stilizzate col design system (registrazione rimandata a S5).
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        RateLimiter::for('login', function (Request $request) {
            // `email[]=…` arrivava qui come array: TypeError (500) prima della validazione.
            $email = $request->input(Fortify::username());
            $throttleKey = Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Link e form del reset NON condividono il secchio per email: chi chiede
        // cinque link per l'indirizzo di un altro gli bloccava il reset vero. Il
        // form si limita solo per IP, il token del broker è già il gate.
        RateLimiter::for('password-reset-link', function (Request $request) {
            $email = $request->input('email');

            return [
                Limit::perMinute(5)->by('password-reset-link-ip|'.$request->ip()),
                Limit::perMinute(5)->by('password-reset-link-email|'.Str::lower(is_string($email) ? $email : '')),
            ];
        });
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by('password-reset-ip|'.$request->ip()));

        // Fortify non offre un limiter per il reset: si aggancia a rotte già
        // registrate. `refreshNameLookups()` serve (senza, getByName non le trova);
        // `in_array` evita il doppio throttle con le rotte in cache.
        $this->app->booted(function () {
            Route::getRoutes()->refreshNameLookups();
            foreach (['password.email' => 'throttle:password-reset-link', 'password.update' => 'throttle:password-reset'] as $nome => $limite) {
                $rotta = Route::getRoutes()->getByName($nome);
                if ($rotta !== null && ! in_array($limite, $rotta->middleware(), true)) {
                    $rotta->middleware($limite);
                }
            }
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}

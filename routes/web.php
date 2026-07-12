<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Settings\TwoFactorAuthentication;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Livewire\Strumenti\ModelliStrumenti;
use App\Livewire\Strumenti\SchedaStrumento;
use Illuminate\Support\Facades\Route;

// Login, logout, reset password, verifica email e 2FA sono registrati da Fortify
// (vedi App\Providers\FortifyServiceProvider). La root rimanda alla login;
// dopo l'accesso Fortify reindirizza a `home` = /dashboard.
Route::redirect('/', '/login');

// Area autenticata. Il middleware two-factor.enforce forza il 2FA sui ruoli
// privilegiati (si auto-esclude da settings.security per consentirne l'attivazione).
Route::middleware(['auth', 'two-factor.enforce'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/anagrafica', Albero::class)
        ->middleware('can:unita_organizzativa.view')
        ->name('anagrafica.index');
    Route::get('/strumenti', ElencoStrumenti::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.index');
    // Prima della rotta {strumento}: altrimenti "modelli" verrebbe risolto come id.
    Route::get('/strumenti/modelli', ModelliStrumenti::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.modelli');
    Route::get('/strumenti/{strumento}', SchedaStrumento::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.show');
    Route::get('/settings/security', TwoFactorAuthentication::class)->name('settings.security');
});

// Impersonation (lab404) — rotte gate-protette da canImpersonate, ancora SENZA UI.
// Fuori dal gruppo two-factor.enforce così la rotta di uscita resta sempre raggiungibile.
Route::middleware('auth')->group(function () {
    Route::impersonate();
});

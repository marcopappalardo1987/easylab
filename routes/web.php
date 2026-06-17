<?php

use App\Livewire\Settings\TwoFactorAuthentication;
use App\Livewire\SystemCheck;
use Illuminate\Support\Facades\Route;

// Login, logout, reset password, verifica email e 2FA sono registrati da Fortify
// (vedi App\Providers\FortifyServiceProvider). La root rimanda alla login;
// dopo l'accesso Fortify reindirizza a `home` = /dashboard.
Route::redirect('/', '/login');

// Area autenticata.
Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/settings/security', TwoFactorAuthentication::class)->name('settings.security');
});

// Verifica TALL stack (Sprint 1 · punto 3) — rotta temporanea, rimovibile dal punto 10.
Route::get('/_tall-check', SystemCheck::class);

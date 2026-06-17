<?php

use Illuminate\Support\Facades\Route;

/*
 | Per ora la root rimanda alla login. L'autenticazione vera (Fortify + 2FA)
 | arriva nello Sprint 1 · punto 4: la vista resources/views/auth/login.blade.php
 | diventerà la login view registrata in Fortify, senza riscriverla.
 */
Route::redirect('/', '/login');

Route::get('/login', fn () => view('auth.login'))->name('login');

// Stub temporaneo: finché Fortify non gestisce l'autenticazione (S4),
// il submit non autentica ma mostra un avviso, evitando un 405.
Route::post('/login', fn () => back()->with(
    'status',
    'Autenticazione non ancora attiva: sarà disponibile dallo Sprint 1 · punto 4 (Fortify + 2FA).'
))->name('login.attempt');

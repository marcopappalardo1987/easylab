<?php

use App\Http\Controllers\AccessoQr;
use App\Livewire\Anagrafica\Albero;
use App\Livewire\Fornitori\ElencoFornitori;
use App\Livewire\Settings\TwoFactorAuthentication;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Livewire\Strumenti\ImportStrumenti;
use App\Livewire\Strumenti\ModelliStrumenti;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Livewire\Strumenti\StampaQr;
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
    // Prima della rotta {strumento}: altrimenti "modelli"/"import" verrebbero
    // risolti come id dello strumento.
    Route::get('/strumenti/modelli', ModelliStrumenti::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.modelli');
    Route::get('/strumenti/import', ImportStrumenti::class)
        ->middleware('can:strumenti.create')
        ->name('strumenti.import');
    Route::get('/strumenti/{strumento}', SchedaStrumento::class)
        ->middleware('can:strumenti.view')
        ->name('strumenti.show');
    // Foglio da stampare e applicare sulla macchina (ADR-003).
    Route::get('/strumenti/{strumento}/qr', StampaQr::class)
        ->middleware('can:strumenti.qr_generate')
        ->name('strumenti.qr');
    Route::get('/fornitori', ElencoFornitori::class)
        ->middleware('can:fornitori.view')
        ->name('fornitori.index');
    Route::get('/settings/security', TwoFactorAuthentication::class)->name('settings.security');
});

/*
 * Accesso da QR (ADR-003). L'ordine dei middleware È la regola:
 *
 *   signed → l'URL non è stato costruito a mano da chi ha letto un token;
 *   auth   → **mai una scheda senza autenticazione**, nemmeno con firma valida.
 *            Chi arriva da sloggato viene mandato al login e poi riportato qui
 *            (`intended`), e funziona proprio perché la firma non scade;
 *   can    → e nemmeno senza il permesso di scansionare.
 *
 * La scheda vera non la serve questa rotta: si limita a tradurre token → id e a
 * rimandare a `strumenti.show`, che è già gatata e passa dai global scope. Così
 * esiste UNA sola pagina scheda con UNA sola catena di autorizzazione: una
 * seconda superficie sarebbe una seconda occasione di sbagliarla.
 *
 * Fuori dal gruppo `two-factor.enforce` per la stessa ragione dell'uscita da
 * impersonazione: chi scansiona in reparto col telefono non deve trovarsi
 * bloccato da un setup che riguarda i ruoli privilegiati.
 */
Route::middleware(['signed', 'auth', 'can:qr.scan'])->group(function () {
    Route::get('/q/{token}', AccessoQr::class)->name('qr.strumento');
});

// Impersonation (lab404) — rotte gate-protette da canImpersonate, ancora SENZA UI.
// Fuori dal gruppo two-factor.enforce così la rotta di uscita resta sempre raggiungibile.
Route::middleware('auth')->group(function () {
    Route::impersonate();
});

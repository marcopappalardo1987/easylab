<?php

use App\Listeners\AuditLogSubscriber;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 Una riga per gesto, non due.
 *
 * Laravel **scopre da sé** i listener in `app/Listeners`, registrando ogni
 * metodo che comincia per `handle` e ha un evento come parametro tipizzato. Con
 * la mappa esplicita di `AuditLogSubscriber::subscribe()` **più** la scoperta,
 * il subscriber risultava iscritto due volte: ogni login, logout, 2FA e
 * impersonazione scrivevano **due righe identiche** — da S1, in silenzio.
 *
 * ⚠️ **Nessun test se ne era accorto**, e la ragione è la forma delle
 * asserzioni: tutte cercavano **una** riga (`first()`, `->exists()`,
 * `latest()`), e una riga c'era. Un registro di sicurezza con ogni evento
 * duplicato non è un fastidio estetico — è un conteggio sbagliato in una
 * tabella che ADR-027 dichiara non riscrivibile.
 *
 * Trovato guardando la vista Audit su **staging**, non in locale: è il tipo di
 * difetto che si vede solo mettendo gli occhi sul dato vero.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('writes one row per login, not two', function () {
    $utente = User::factory()->create();

    $this->post(route('login'), ['email' => $utente->email, 'password' => 'password']);

    expect(Activity::where('log_name', AuditLog::NAME)->where('description', 'Login')->count())->toBe(1);
});

it('writes one row per failed login, not two', function () {
    $this->post(route('login'), ['email' => 'nessuno@esempio.test', 'password' => 'sbagliata']);

    expect(Activity::where('description', 'Login fallito')->count())->toBe(1);
});

it('writes one row per impersonation, not two', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('Superadmin');
    $cliente = User::factory()->create();
    $cliente->assignRole('Tenant');

    $this->actingAs($superadmin)->get(route('impersonate', $cliente));
    $this->get(route('impersonate.leave'));

    expect(Activity::where('description', 'Impersonation avviata')->count())->toBe(1)
        ->and(Activity::where('description', 'Impersonation terminata')->count())->toBe(1);
});

it('never lets a subscriber method be auto-discovered as well', function () {
    // La guardia strutturale: se qualcuno rinominasse un metodo in `handleXxx`,
    // la scoperta automatica lo registrerebbe **in più** alla mappa esplicita e
    // le righe tornerebbero doppie. I tre test qui sopra lo prenderebbero, ma
    // solo per gli eventi che esercitano: questo copre tutti e otto.
    $metodi = collect((new ReflectionClass(AuditLogSubscriber::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $m) => $m->name)
        ->filter(fn (string $n) => str_starts_with($n, 'handle'));

    expect($metodi->all())->toBe([]);
});

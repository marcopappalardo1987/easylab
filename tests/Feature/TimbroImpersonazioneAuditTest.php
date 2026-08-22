<?php

use App\Enums\StatoSemaforo;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * 🔴 Chi ha fatto davvero il gesto, quando qualcuno ne impersona un altro.
 *
 * `CauserResolver` legge `auth()->guard()->user()`, e lab404 **sostituisce**
 * quell'utente: senza il timbro, ogni riga scritta durante un'impersonazione
 * attribuisce **al cliente** un gesto di EasyLab. Non è un difetto di
 * presentazione — è un dato falso in un registro di sicurezza, e in una tabella
 * che questo progetto ha già dichiarato non riscrivibile.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->account = Account::factory()->create();

    $this->superadmin = User::factory()->create(['name' => 'Direzione EasyLab']);
    $this->superadmin->assignRole('Superadmin');

    $this->cliente = User::factory()->create(['name' => 'Anna Bianchi', 'tenant_id' => $this->ente->id]);
    $this->cliente->assignRole('Admin');
});

it('stamps the impersonator on a row written through the audit trait', function () {
    // Le righe del trait sono la **maggioranza del volume**: se il timbro
    // funzionasse solo sulle scritture esplicite coprirebbe la minoranza dei
    // casi, e proprio non quelli in cui si cancella qualcosa.
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente));

    expect(app('impersonate')->isImpersonating())->toBeTrue();

    $this->account->update(['ragione_sociale' => 'Toccata durante impersonazione']);

    $riga = Activity::where('log_name', AuditLog::NAME)
        ->where('subject_type', $this->account->getMorphClass())->latest('id')->first();

    expect($riga)->not->toBeNull()
        // Il causer resta l'impersonato, ed è giusto: è chi la sessione dice
        // che fosse. Il timbro non lo corregge, lo **completa**.
        ->and($riga->causer_id)->toBe($this->cliente->id)
        ->and($riga->properties->get('impersonato_da'))->toBe($this->superadmin->id);
});

it('stamps the impersonator on an explicit row too', function () {
    $strumento = Strumento::factory()->forNode($this->ente)->create();

    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente));

    $strumento->forzaSemaforo(StatoSemaforo::Rosso, 'Verifica durante impersonazione');

    $riga = Activity::where('description', 'Semaforo forzato')->latest('id')->firstOrFail();

    expect($riga->causer_id)->toBe($this->cliente->id)
        ->and($riga->properties->get('impersonato_da'))->toBe($this->superadmin->id)
        // ⚠️ E non ha mangiato le proprietà che la riga aveva già: il timbro si
        // aggiunge, non sostituisce.
        ->and($riga->properties->get('motivo'))->toBe('Verifica durante impersonazione');
});

it('never stamps the row that opens the impersonation, which would name itself', function () {
    // 🔴 `ImpersonateManager::take()` scrive la chiave di sessione **prima** di
    // emettere l'evento, quindi la riga «Impersonation avviata» — che ha già
    // l'impersonatore come causer — si timbrerebbe da sé: `causer_id ===
    // impersonato_da`, un timbro che non aggiunge niente e afferma il falso,
    // perché aprire un'impersonazione non è un gesto compiuto *come*
    // l'impersonato. E finirebbe nel filtro «azioni compiute in impersonazione»
    // insieme a ogni apertura mai avvenuta.
    //
    // `leave()` invece ripulisce la sessione prima dell'evento: senza la
    // guardia le due righe gemelle si comporterebbero in modo diverso.
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente));
    $this->get(route('impersonate.leave'));

    $avviata = Activity::where('description', 'Impersonation avviata')->firstOrFail();
    $terminata = Activity::where('description', 'Impersonation terminata')->firstOrFail();

    expect($avviata->properties->has('impersonato_da'))->toBeFalse()
        ->and($terminata->properties->has('impersonato_da'))->toBeFalse()
        // E non ha mangiato ciò che quelle righe portano già.
        ->and($avviata->properties->get('ip'))->not->toBeNull();
});

it('stamps a delete, which is the case the whole thing exists for', function () {
    // È l'esempio del docblock: «impersona un cliente e ne cancella una
    // garanzia». Gli altri test coprono `updated` e una scrittura esplicita.
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente));

    $this->account->delete();

    $riga = Activity::where('subject_type', $this->account->getMorphClass())
        ->where('event', 'deleted')->latest('id')->firstOrFail();

    expect($riga->properties->get('impersonato_da'))->toBe($this->superadmin->id);
});

it('does not reach a row written outside the request, and that is a declared hole', function () {
    // ⚠️ Il limite dichiarato del timbro: `isImpersonating()` legge la
    // **sessione**, e in un worker, in console o in un webhook la sessione non
    // c'è. Il caso concreto esiste già — `InvitoUtente` è `ShouldQueue` e scrive
    // audit da `failed()` — quindi un invito fallito originato durante
    // un'impersonazione non ha né causer né timbro.
    //
    // Questo test **congela il buco**, non lo ripara: chiuderlo davvero vuol
    // dire catturare l'id al dispatch e portarlo nel payload del job. Se un
    // giorno lo si fa, questo test diventa rosso ed è il segnale giusto.
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente));

    expect(app('impersonate')->isImpersonating())->toBeTrue();

    session()->flush();

    (new InvitoUtente('Ente di prova', 'chi@esempio.test'))
        ->failed(new RuntimeException('SMTP giù'));

    $riga = Activity::where('description', 'Invito NON consegnato')->firstOrFail();

    expect($riga->properties->has('impersonato_da'))->toBeFalse();
});

it('leaves the properties alone when nobody is impersonating', function () {
    // Il caso normale è la stragrande maggioranza delle righe: se il timbro
    // comparisse sempre, «impersonato_da» smetterebbe di voler dire qualcosa.
    $this->actingAs($this->cliente);

    $this->account->update(['ragione_sociale' => 'Toccata normalmente']);

    $riga = Activity::where('subject_type', $this->account->getMorphClass())->latest('id')->firstOrFail();

    expect($riga->properties->has('impersonato_da'))->toBeFalse()
        ->and($riga->causer_id)->toBe($this->cliente->id);
});

it('keeps stamping after the impersonation ends, only until it ends', function () {
    // La finestra si chiude davvero: una riga scritta dopo l'uscita non porta
    // il timbro. Senza questo, «impersonato_da» diventerebbe appiccicoso e il
    // registro racconterebbe un'impersonazione che non è più in corso.
    $this->actingAs($this->superadmin)->get(route('impersonate', $this->cliente));
    $this->account->update(['ragione_sociale' => 'Durante']);

    $this->get(route('impersonate.leave'));
    $this->account->update(['ragione_sociale' => 'Dopo']);

    $righe = Activity::where('subject_type', $this->account->getMorphClass())
        ->orderBy('id')->get();

    expect($righe->last()->properties->has('impersonato_da'))->toBeFalse()
        ->and($righe->first(fn ($r) => $r->properties->has('impersonato_da')))->not->toBeNull();
});

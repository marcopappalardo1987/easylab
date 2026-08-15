<?php

use App\Livewire\Strumenti\StampaQr;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\QrStrumento;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * QR degli strumenti: token, accesso firmato, stampa (🔗 ADR-003 — S4, 15 Ago 2026).
 *
 * L'area rossa qui è **l'accesso**: ADR-003 dice «mai una scheda accessibile
 * senza autenticazione», e un URL firmato è per definizione una porta che
 * qualcuno può trovarsi in mano leggendo un adesivo su una macchina. I casi
 * negativi contano quindi più di quelli positivi, e sono scritti per primi:
 * firma assente, utente sloggato, permesso mancante, macchina di un altro Ente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->utente = function (string $ruolo, ?UnitaOrganizzativa $ente = null): User {
        $u = User::factory()->create([
            'tenant_id' => ($ente ?? $this->ente)->id,
            'two_factor_confirmed_at' => now(),
        ]);
        $u->assignRole($ruolo);

        return $u;
    };

    $this->admin = ($this->utente)('Admin');
});

// --- Il token ---

it('gives every strumento its own token, at birth', function () {
    $altro = Strumento::factory()->forNode($this->dept)->create();

    expect($this->strumento->qr_token)->toHaveLength(32)
        ->and($altro->qr_token)->toHaveLength(32)
        ->and($altro->qr_token)->not->toBe($this->strumento->qr_token);
});

it('refuses to let a payload rewrite the token', function () {
    $originale = $this->strumento->qr_token;

    // Fuori da `$fillable` come le colonne `forced_*`: il form dell'anagrafica
    // scrive per mass-assignment, e un token riscritto da lì invaliderebbe
    // l'adesivo già applicato sulla macchina senza che nessuno lo voglia.
    $this->strumento->update(['qr_token' => 'token-forgiato-da-un-payload']);

    expect($this->strumento->fresh()->qr_token)->toBe($originale);
});

// --- L'accesso: prima i negativi ---

it('refuses an unsigned url, even to an authenticated admin', function () {
    $this->actingAs($this->admin)
        ->get(route('qr.strumento', ['token' => $this->strumento->qr_token]))
        ->assertForbidden();
});

it('sends a logged-out visitor to the login, never to the scheda', function () {
    // È la riga di ADR-003: «mai una scheda accessibile senza autenticazione».
    // La firma valida non basta e non deve bastare.
    $this->get(QrStrumento::url($this->strumento))->assertRedirect(route('login'));
});

it('refuses a user whose role cannot scan', function () {
    $senzaRuolo = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($senzaRuolo)->get(QrStrumento::url($this->strumento))->assertForbidden();
});

it('gives 404 for a machine of another Ente, exactly like the direct url would', function () {
    $altroEnte = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $intruso = ($this->utente)('Admin', $altroEnte);

    // 404 e non 403: per chi guarda, quella macchina non esiste. È il global
    // scope a rispondere, non un controllo scritto a mano — e infatti è la
    // stessa risposta dell'URL diretto.
    $this->actingAs($intruso)->get(QrStrumento::url($this->strumento))->assertNotFound();
});

it('gives 404 for a token that does not exist', function () {
    $urlInventato = URL::signedRoute('qr.strumento', ['token' => str_repeat('x', 32)]);

    $this->actingAs($this->admin)->get($urlInventato)->assertNotFound();
});

// --- L'accesso: il caso felice, e il cliente ---

it('takes an authorised user to the scheda', function () {
    $this->actingAs($this->admin)
        ->get(QrStrumento::url($this->strumento))
        ->assertRedirect(route('strumenti.show', $this->strumento));
});

it('lets the Tenant scan their own machine', function () {
    // Deciso il 15 Ago 2026: l'Elenco Funzionalità §4 dice «il tecnico o il
    // cliente inquadra il QR», la matrice diceva ❌ e il cliente prendeva 403
    // sulla propria macchina. Il QR è una scorciatoia a una pagina che già
    // poteva aprire dal menù.
    $tenant = ($this->utente)('Tenant');

    $this->actingAs($tenant)
        ->get(QrStrumento::url($this->strumento))
        ->assertRedirect(route('strumenti.show', $this->strumento));
});

it('survives the login and lands where the QR pointed', function () {
    // Funziona perché la firma non scade: chi arriva sloggato viene mandato al
    // login e poi riportato all'URL firmato, che nel frattempo è ancora valido.
    $url = QrStrumento::url($this->strumento);
    $this->get($url)->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe($url);
});

// --- La stampa ---

it('renders a printable label with what serves to find the machine again', function () {
    Livewire::actingAs($this->admin)->test(StampaQr::class, ['strumento' => $this->strumento])
        ->assertOk()
        ->assertSee('Autoclave')
        ->assertSee('Ente A › Dip')
        ->assertSee('<svg', false);
});

it('keeps the same token when the label is printed again', function () {
    $originale = $this->strumento->qr_token;

    Livewire::actingAs($this->admin)->test(StampaQr::class, ['strumento' => $this->strumento]);
    Livewire::actingAs($this->admin)->test(StampaQr::class, ['strumento' => $this->strumento]);

    // Un adesivo rovinato si rifà identico: se la ristampa rigenerasse, chi
    // stampa una copia di scorta romperebbe quella già sulla macchina.
    expect($this->strumento->fresh()->qr_token)->toBe($originale);
});

it('invalidates the old label only on an explicit regeneration, and records it', function () {
    $originale = $this->strumento->qr_token;

    Livewire::actingAs($this->admin)->test(StampaQr::class, ['strumento' => $this->strumento])
        ->call('openRigenera')
        ->call('rigenera')
        ->assertSet('showRigeneraForm', false);

    $nuovo = $this->strumento->fresh()->qr_token;
    $riga = Activity::where('log_name', AuditLog::NAME)->latest('id')->first();

    expect($nuovo)->not->toBe($originale)
        ->and($riga->description)->toContain('QR rigenerato')
        ->and($riga->causer_id)->toBe($this->admin->id);

    // E l'etichetta vecchia smette davvero di funzionare: è la conseguenza che
    // la modale annuncia, e senza questo caso resterebbe una promessa.
    $vecchioUrl = URL::signedRoute('qr.strumento', ['token' => $originale]);
    $this->actingAs($this->admin)->get($vecchioUrl)->assertNotFound();
});

it('refuses the print page to whoever cannot generate QR codes', function () {
    $tenant = ($this->utente)('Tenant'); // può scansionare, non stampare

    $this->actingAs($tenant)->get(route('strumenti.qr', $this->strumento))->assertForbidden();
});

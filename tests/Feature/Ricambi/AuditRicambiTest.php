<?php

use App\Actions\Ricambi\RegistraRicambiIntervento;
use App\Enums\StatoSemaforo;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * La traccia delle scritture di dominio (ADR-027), anticipata al blocco 3
 * perché è la seconda metà del patto che ha dato al Tecnico
 * `garanzie.ricambio.manage`: è la fonte del dato **e** ogni sua scrittura è
 * tracciata. Le due metà non possono viaggiare separate.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($dept)->create();

    $this->tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->tecnico->assignRole('Tecnico');

    $this->audit = fn () => Activity::where('log_name', AuditLog::NAME)->get();
});

it('records who registered a part, on the audit channel', function () {
    $this->actingAs($this->tecnico);
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    app(RegistraRicambiIntervento::class)->esegui($intervento, [
        ['nome' => 'Guarnizione O-Ring', 'scadenza_garanzia' => today()->addYears(2)->toDateString()],
    ]);

    $righe = ($this->audit)();

    // Tre scritture, tre righe: catalogo, utilizzo, garanzia.
    expect($righe)->toHaveCount(3)
        ->and($righe->pluck('subject_type')->unique()->sort()->values()->all())
        ->toEqual([Garanzia::class, Ricambio::class, RicambioUtilizzo::class])
        ->and($righe->pluck('causer_id')->unique()->all())->toBe([$this->tecnico->id])
        ->and($righe->pluck('description')->all())
        ->each->toStartWith('Creazione');
});

it('records the derived date that drives the semaforo, not just the input', function () {
    $this->actingAs($this->tecnico);
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    app(RegistraRicambiIntervento::class)->esegui($intervento, [
        ['nome' => 'Filtro HEPA', 'scadenza_garanzia' => '2029-04-05'],
    ]);

    $riga = ($this->audit)()->firstWhere('subject_type', Garanzia::class);

    // ⚠️ In activitylog v5 i valori NON stanno in `properties` — quella resta la
    // borsa delle `withProperties()` esplicite (come l'`ip` di AuditLogSubscriber)
    // — ma in `attribute_changes`. Il primo assert di questo test cercava
    // `properties` e falliva: sbagliato il test, non il trait.
    //
    // Senza `attributiDerivatiTracciati`, l'audit avrebbe registrato la durata
    // (null) e non la data che accende l'arancione.
    expect($riga->attribute_changes['attributes']['data_scadenza_effettiva'])->toContain('2029-04-05')
        ->and($riga->attribute_changes['attributes'])->toHaveKey('data_scadenza_dichiarata');
});

it('writes nothing when a save changes only untracked columns', function () {
    // ⚠️ Il caso deve sporcare una colonna FUORI dal set tracciato, non
    // riassegnare lo stesso valore: un `save()` che non sporca nulla non emette
    // proprio l'evento, quindi non eserciterebbe `dontLogEmptyChanges` — è
    // l'errore della prima stesura di questo test, trovato dalla mutazione che
    // non cadeva. `logEmptyChanges` è `true` di DEFAULT in activitylog v5:
    // senza la chiamata, ogni salvataggio del genere lascerebbe una riga vuota.
    $this->actingAs($this->tecnico);
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Cinghia']);
    Activity::query()->delete();

    $ricambio->reseller_id = 42; // fillable ma escluso dal set tracciato (ADR-002)
    $ricambio->save();

    expect(($this->audit)())->toBeEmpty();
});

it('keeps the forced semaforo on a single explicit row', function () {
    // La regola «o il trait o le activity() esplicite, mai entrambi»: Strumento
    // logga a mano, e non deve guadagnare una seconda riga da questo blocco.
    $admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $admin->assignRole('Admin');
    $this->actingAs($admin);

    $this->strumento->forzaSemaforo(StatoSemaforo::Verde);

    expect(($this->audit)())->toHaveCount(1)
        ->and(($this->audit)()->first()->description)->toBe('Semaforo forzato');
});

it('still records in a console context, without a causer', function () {
    // Seeder e import scrivono senza utente: la traccia resta, il causer è null.
    Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Sensore PT100']);

    expect(($this->audit)())->toHaveCount(1)
        ->and(($this->audit)()->first()->causer_id)->toBeNull();
});

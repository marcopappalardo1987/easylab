<?php

use App\Enums\StatoIntervento;
use App\Enums\TipoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Invariante di dominio e cast del model (ERD §5.2 — ADR-005/009).
 * Fixture in contesto console (nessun actingAs): i global scope non filtrano.
 */
beforeEach(function () {
    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);
});

it('defaults a new intervento to non_fatto with no execution date', function () {
    $intervento = Intervento::create([
        'tenant_id' => $this->ente->id,
        'strumento_id' => $this->strumento->id,
        'descrizione' => 'Manutenzione ordinaria',
        'tipo' => TipoIntervento::ManutenzioneOrdinaria,
        'data_scadenza' => '2026-09-01',
    ]);

    expect($intervento->stato)->toBe(StatoIntervento::NonFatto)
        ->and($intervento->data_esecuzione)->toBeNull();
});

it('fills data_esecuzione with today when saved as fatto without a date', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    $intervento->segnaFatto();

    expect($intervento->stato)->toBe(StatoIntervento::Fatto)
        ->and($intervento->data_esecuzione->toDateString())->toBe(today()->toDateString());
});

it('keeps an explicit past data_esecuzione when marked fatto', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    $intervento->segnaFatto(Carbon\Carbon::parse('2026-01-10'));

    expect($intervento->fresh()->data_esecuzione->toDateString())->toBe('2026-01-10');
});

it('clears data_esecuzione when the intervento is reopened', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-01-10')->create();

    $intervento->riapri();

    expect($intervento->fresh())
        ->stato->toBe(StatoIntervento::NonFatto)
        ->data_esecuzione->toBeNull();
});

it('clears data_esecuzione when stato is set back to non_fatto directly', function () {
    // L'invariante vive nell'hook `saving`, non solo nei metodi di dominio.
    $intervento = Intervento::factory()->forStrumento($this->strumento)->fatto('2026-01-10')->create();

    $intervento->stato = StatoIntervento::NonFatto;
    $intervento->save();

    expect($intervento->fresh()->data_esecuzione)->toBeNull();
});

it('casts tipo, stato and the dates', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create([
        'tipo' => TipoIntervento::TaraturaECertificazione,
        'data_scadenza' => '2026-12-31',
    ])->fresh();

    expect($intervento->tipo)->toBe(TipoIntervento::TaraturaECertificazione)
        ->and($intervento->stato)->toBe(StatoIntervento::NonFatto)
        ->and($intervento->data_scadenza)->toBeInstanceOf(CarbonInterface::class)
        ->and($intervento->data_scadenza->toDateString())->toBe('2026-12-31');
});

it('accepts a data_scadenza both in the past and in the future', function () {
    // Storico (scaduto-non-fatto → arancione, ADR-005) e pianificato convivono.
    $scaduto = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    $pianificato = Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();

    expect($scaduto->data_scadenza->isPast())->toBeTrue()
        ->and($scaduto->stato)->toBe(StatoIntervento::NonFatto)
        ->and($pianificato->data_scadenza->isFuture())->toBeTrue();
});

it('relates an intervento to its strumento and its tecnico', function () {
    $tecnico = User::factory()->create();
    $intervento = Intervento::factory()
        ->forStrumento($this->strumento)
        ->assegnatoA($tecnico)
        ->create();

    expect($intervento->strumento->is($this->strumento))->toBeTrue()
        ->and($intervento->tecnico->is($tecnico))->toBeTrue();
});

it('exposes interventi from a strumento, latest deadline first', function () {
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => '2026-03-01']);
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => '2026-11-01']);
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => '2026-07-01']);

    expect($this->strumento->interventi->pluck('data_scadenza')->map->toDateString()->all())
        ->toBe(['2026-11-01', '2026-07-01', '2026-03-01']);
});

it('leaves the tenant of an intervento aligned with its strumento', function () {
    // Invariante su cui poggia DepartmentThroughStrumentoScope.
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    expect($intervento->tenant_id)->toBe($this->strumento->tenant_id);
});

// --- Scaduto-non-fatto (ADR-005): la definizione che il motore semaforo riuserà ---

it('marks an intervento as scaduto only when non_fatto and past its deadline', function () {
    $scaduto = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    $pianificato = Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();
    // Fatto in ritardo: la scadenza è passata, ma è stato eseguito → non è "in ritardo".
    $fattoTardi = Intervento::factory()->forStrumento($this->strumento)
        ->scaduto()->fatto()->create();

    expect($scaduto->isScaduto())->toBeTrue()
        ->and($pianificato->isScaduto())->toBeFalse()
        ->and($fattoTardi->isScaduto())->toBeFalse();
});

it('does not consider an intervento due today as scaduto', function () {
    // Il confine: oggi si è ancora in tempo. Vale sia in PHP sia in SQL.
    $oggi = Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->toDateString()]);

    expect($oggi->isScaduto())->toBeFalse()
        ->and(Intervento::scadute()->count())->toBe(0);
});

it('filters overdue interventi with the scadute scope', function () {
    $scaduto = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->fatto()->create();

    expect(Intervento::scadute()->pluck('id')->all())->toBe([$scaduto->id]);
});

it('keeps the scadute scope aligned with isScaduto', function () {
    // La garanzia su cui poggia il punto 4: la regola SQL ≡ la regola PHP.
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    Intervento::factory()->forStrumento($this->strumento)->pianificato()->create();
    Intervento::factory()->forStrumento($this->strumento)->fatto()->create();
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->fatto()->create();
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => today()->toDateString()]);

    expect(Intervento::all()->filter->isScaduto()->pluck('id')->all())
        ->toEqualCanonicalizing(Intervento::scadute()->pluck('id')->all());
});

// --- Assegnatario mostrato in UI ---

it('shows the name of a tecnico of the same ente', function () {
    $tecnico = User::factory()->create(['tenant_id' => $this->ente->id, 'name' => 'Luca Bianchi']);
    $intervento = Intervento::factory()->forStrumento($this->strumento)->assegnatoA($tecnico)->create();

    expect($intervento->tecnicoLabel())->toBe('Luca Bianchi');
});

it('shows the name of an external tecnico with no tenant (ADR-007)', function () {
    $tecnico = User::factory()->create(['tenant_id' => null, 'name' => 'Gino Verdi']);
    $intervento = Intervento::factory()->forStrumento($this->strumento)->assegnatoA($tecnico)->create();

    expect($intervento->tecnicoLabel())->toBe('Gino Verdi');
});

it('hides the name of a tecnico belonging to another ente', function () {
    // `tecnico_id` è una FK su users, che non ha TenantScope: il nome di un
    // utente di un altro Ente non deve mai comparire.
    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $estraneo = User::factory()->create(['tenant_id' => $enteB->id, 'name' => 'Mario Rossi']);
    $intervento = Intervento::factory()->forStrumento($this->strumento)->assegnatoA($estraneo)->create();

    expect($intervento->tecnicoLabel())->toBe('—');
});

it('shows an em dash when no tecnico is assigned', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();

    expect($intervento->tecnicoLabel())->toBe('—');
});

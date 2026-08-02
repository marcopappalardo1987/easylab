<?php

use App\Enums\StatoSemaforo;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Semaforo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Semaforo a livello di strumento (ADR-005 — S3 punto 4): metodi del model e
 * allineamento del calcolo bulk dell'elenco con il calcolo per-model.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip']);
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Admin');
});

// --- Calcolo per-model ---

it('is verde for a strumento with no interventi', function () {
    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde)
        ->and($this->strumento->prossimoInterventoAperto())->toBeNull();
});

it('is verde when every intervento is fatto, even if done late', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->fatto()->create();
    Intervento::factory()->forStrumento($this->strumento)->fatto()->create();

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde);
});

it('is arancione with a scaduto-non-fatto', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);
});

it('is arancione with a scadenza within the soglia', function () {
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente())->toDateString()]);

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);
});

it('is verde beyond the soglia but still exposes the prossimo intervento', function () {
    // La colonna "Prossima scadenza" mostra la data anche quando si è verdi.
    $futuro = Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()]);

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde)
        ->and($this->strumento->prossimoInterventoAperto()->id)->toBe($futuro->id);
});

it('picks the nearest scadenza among several aperti', function () {
    // Smaschera un reorder() mancante: la relazione ordina per data DESC.
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => today()->addYear()->toDateString()]);
    $vicino = Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => today()->addDays(5)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => today()->addMonths(6)->toDateString()]);

    expect($this->strumento->prossimoInterventoAperto()->id)->toBe($vicino->id)
        ->and($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);
});

it('ignores soft-deleted interventi', function () {
    $scaduto = Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);

    $scaduto->delete();

    expect($this->strumento->fresh()->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde);
});

it('uses the loaded relation without re-querying when interventi are eager loaded', function () {
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => today()->addYear()->toDateString()]);
    $vicino = Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    $strumento = Strumento::with('interventi')->findOrFail($this->strumento->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $prossimo = $strumento->prossimoInterventoAperto();
    $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "interventi"'))->count();
    DB::disableQueryLog();

    expect($prossimo->id)->toBe($vicino->id)
        ->and($queries)->toBe(0);
});

it('exposes the effective state, which today equals the calculated one', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    // Il ramo "forzato" arriva col punto 5.
    expect($this->strumento->statoSemaforoEffettivo())
        ->toBe($this->strumento->statoSemaforoCalcolato());
});

// --- Allineamento del bulk dell'elenco ---

it('keeps the elenco bulk aligned with the per-model calculation', function () {
    $scaduto = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Con scaduto']);
    Intervento::factory()->forStrumento($scaduto)->scaduto()->create();

    $imminente = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Con imminente']);
    Intervento::factory()->forStrumento($imminente)->create(['data_scadenza' => today()->addDays(3)->toDateString()]);

    $lontano = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Con lontano']);
    Intervento::factory()->forStrumento($lontano)->create(['data_scadenza' => today()->addMonths(6)->toDateString()]);

    $tuttiFatti = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Tutti fatti']);
    Intervento::factory()->forStrumento($tuttiFatti)->scaduto()->fatto()->create();

    $semafori = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->viewData('semafori');

    foreach (Strumento::all() as $s) {
        expect($semafori[$s->id])->toBe($s->statoSemaforoCalcolato(), "Disallineamento su «{$s->nome}»");
    }

    // E il caso arancione-da-scaduto coincide con scopeScadute().
    $idScaduti = Intervento::scadute()->pluck('strumento_id')->unique();
    expect($idScaduti->all())->toBe([$scaduto->id])
        ->and($semafori[$scaduto->id])->toBe(StatoSemaforo::Arancione)
        ->and($semafori[$imminente->id])->toBe(StatoSemaforo::Arancione)
        ->and($semafori[$lontano->id])->toBe(StatoSemaforo::Verde)
        ->and($semafori[$tuttiFatti->id])->toBe(StatoSemaforo::Verde)
        ->and($semafori[$this->strumento->id])->toBe(StatoSemaforo::Verde);
});

it('computes the semaforo in a constant number of queries (no N+1)', function () {
    $queryInterventi = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(ElencoStrumenti::class);
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "interventi"'))->count();
        DB::disableQueryLog();

        return $n;
    };

    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    $conUno = $queryInterventi();

    foreach (range(1, 5) as $i) {
        $s = Strumento::factory()->forNode($this->dept)->create();
        Intervento::factory()->forStrumento($s)->scaduto()->create();
        Intervento::factory()->forStrumento($s)->pianificato()->create();
    }

    expect($queryInterventi())->toBe($conUno);
});

it('keeps the semaforo aggregates scoped for a Responsabile', function () {
    $altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Dip 2']);
    $strumentoFuori = Strumento::factory()->forNode($altroDept)->create(['nome' => 'Fuori sotto-albero']);
    Intervento::factory()->forStrumento($strumentoFuori)->scaduto()->create();
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    $semafori = Livewire::actingAs($resp)->test(ElencoStrumenti::class)->viewData('semafori');

    expect($semafori)->toHaveKey($this->strumento->id)
        ->and($semafori)->not->toHaveKey($strumentoFuori->id)
        ->and($semafori[$this->strumento->id])->toBe(StatoSemaforo::Arancione);
});

// --- Filtro per stato nell'elenco (forma SQL della regola) ---

it('filters the elenco by stato semaforo', function () {
    $scaduto = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Ha uno scaduto']);
    Intervento::factory()->forStrumento($scaduto)->scaduto()->create();

    $imminente = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Ha un imminente']);
    Intervento::factory()->forStrumento($imminente)->create(['data_scadenza' => today()->addDays(3)->toDateString()]);

    $lontano = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Ha solo lontani']);
    Intervento::factory()->forStrumento($lontano)->create(['data_scadenza' => today()->addMonths(6)->toDateString()]);

    // $this->strumento non ha interventi → verde.

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('stato', 'arancione')
        ->assertSee('Ha uno scaduto')
        ->assertSee('Ha un imminente')
        ->assertDontSee('Ha solo lontani')
        ->assertDontSee('Autoclave')
        ->set('stato', 'verde')
        ->assertSee('Ha solo lontani')
        ->assertSee('Autoclave')
        ->assertDontSee('Ha uno scaduto')
        ->assertDontSee('Ha un imminente')
        ->set('stato', null)
        ->assertSee('Ha uno scaduto')
        ->assertSee('Ha solo lontani');
});

it('keeps the stato filter aligned with the per-model calculation', function () {
    // La regola vive in due forme (PHP in Semaforo::calcola, SQL in
    // scopeApertiEntroSoglia): questo test impedisce che divergano.
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    foreach ([-1, 0, 3, 30, 31, 200] as $giorni) {
        $s = Strumento::factory()->forNode($this->dept)->create(['nome' => "Scadenza {$giorni}"]);
        Intervento::factory()->forStrumento($s)->create(['data_scadenza' => today()->addDays($giorni)->toDateString()]);
    }
    Strumento::factory()->forNode($this->dept)->create(['nome' => 'Senza interventi']);

    foreach ([StatoSemaforo::Arancione, StatoSemaforo::Verde] as $stato) {
        $filtrati = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->set('stato', $stato->value)
            ->viewData('strumenti')->pluck('id')->all();

        $attesi = Strumento::all()
            ->filter(fn (Strumento $s) => $s->statoSemaforoCalcolato() === $stato)
            ->pluck('id')->all();

        expect($filtrati)->toEqualCanonicalizing($attesi, "Filtro «{$stato->value}» disallineato dal calcolo per-model");
    }
});

it('resets pagination when the stato filter changes', function () {
    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->call('setPage', 2)
        ->set('stato', 'arancione')
        ->assertSet('paginators.page', 1);
});

// --- Ordinamento delle colonne derivate (SQL, non PHP) ---

it('sorts by stato semaforo in both directions', function () {
    $arancione = Strumento::factory()->forNode($this->dept)->create(['nome' => 'B arancione']);
    Intervento::factory()->forStrumento($arancione)->scaduto()->create();
    $verde = Strumento::factory()->forNode($this->dept)->create(['nome' => 'A verde']);

    $ordine = fn (string $dir) => Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'stato')->set('sortDir', $dir)
        ->viewData('strumenti')->pluck('id')->all();

    // asc = prima i verdi (ordinale 0), desc = prima quelli da sistemare.
    expect(head($ordine('asc')))->not->toBe($arancione->id)
        ->and(head($ordine('desc')))->toBe($arancione->id);
});

it('sorts by prossima scadenza keeping strumenti without one always last', function () {
    $vicino = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Scadenza vicina']);
    Intervento::factory()->forStrumento($vicino)->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    $lontano = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Scadenza lontana']);
    Intervento::factory()->forStrumento($lontano)->create(['data_scadenza' => today()->addYear()->toDateString()]);

    // $this->strumento non ha interventi → "—", deve restare in fondo SEMPRE.
    $asc = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'prossima_scadenza')->set('sortDir', 'asc')->viewData('strumenti')->pluck('id')->all();
    $desc = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'prossima_scadenza')->set('sortDir', 'desc')->viewData('strumenti')->pluck('id')->all();

    expect($asc)->toBe([$vicino->id, $lontano->id, $this->strumento->id])
        ->and($desc)->toBe([$lontano->id, $vicino->id, $this->strumento->id]);
});

it('sorts by ubicazione using the node name', function () {
    $alfa = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Alfa lab']);
    $zeta = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Zeta lab']);
    $inZeta = Strumento::factory()->forNode($zeta)->create(['nome' => 'Sta in Zeta']);
    $inAlfa = Strumento::factory()->forNode($alfa)->create(['nome' => 'Sta in Alfa']);

    $ids = fn (string $dir) => Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'ubicazione')->set('sortDir', $dir)->viewData('strumenti')->pluck('id')->all();

    expect(array_search($inAlfa->id, $ids('asc'), true))->toBeLessThan(array_search($inZeta->id, $ids('asc'), true))
        ->and(array_search($inZeta->id, $ids('desc'), true))->toBeLessThan(array_search($inAlfa->id, $ids('desc'), true));
});

it('sorts across pages, not just within the current one', function () {
    // La prova che l'ordinamento è in SQL: con 25 strumenti la pagina 1 deve
    // contenere i 20 con scadenza più vicina, non i primi 20 riordinati.
    foreach (range(1, 25) as $g) {
        $s = Strumento::factory()->forNode($this->dept)->create(['nome' => "Strumento {$g}"]);
        Intervento::factory()->forStrumento($s)->create(['data_scadenza' => today()->addDays($g)->toDateString()]);
    }

    $pagina1 = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'prossima_scadenza')->set('sortDir', 'asc')
        ->viewData('strumenti');

    expect($pagina1->count())->toBe(20)
        ->and($pagina1->first()->nome)->toBe('Strumento 1')
        ->and($pagina1->last()->nome)->toBe('Strumento 20');
});

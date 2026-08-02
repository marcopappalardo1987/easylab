<?php

use App\Enums\StatoSemaforo;
use App\Enums\TipoIntervento;
use App\Livewire\Strumenti\ElencoStrumenti;
use App\Models\Garanzia;
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

it('exposes the effective state, which equals the calculated one when not forced', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    expect($this->strumento->statoSemaforoEffettivo())
        ->toBe($this->strumento->statoSemaforoCalcolato());

    // Con la forzatura vince quella (il caso completo è in ForzaturaSemaforoTest).
    $this->actingAs($this->admin);
    $this->strumento->forzaSemaforo(StatoSemaforo::Verde);

    expect($this->strumento->fresh()->statoSemaforoEffettivo())->toBe(StatoSemaforo::Verde);
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

    // Forzati: il bulk deve seguire l'effettivo, non il calcolato.
    $this->actingAs($this->admin);
    $forzatoRosso = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Forzato rosso']);
    $forzatoRosso->forzaSemaforo(StatoSemaforo::Rosso, 'Non idoneo');
    $forzatoVerde = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Forzato verde con scaduto']);
    Intervento::factory()->forStrumento($forzatoVerde)->scaduto()->create();
    $forzatoVerde->forzaSemaforo(StatoSemaforo::Verde);

    $semafori = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->viewData('semafori');

    foreach (Strumento::all() as $s) {
        expect($semafori[$s->id])->toBe($s->statoSemaforoEffettivo(), "Disallineamento su «{$s->nome}»");
    }

    expect($semafori[$forzatoRosso->id])->toBe(StatoSemaforo::Rosso)
        ->and($semafori[$forzatoVerde->id])->toBe(StatoSemaforo::Verde);

    // Lo scaduto resta scaduto anche sotto una forzatura verde: il semaforo
    // mostra il forzato, ma il problema non sparisce dai dati (ADR-005).
    $idScaduti = Intervento::scadute()->pluck('strumento_id')->unique();
    expect($idScaduti->all())->toEqualCanonicalizing([$scaduto->id, $forzatoVerde->id])
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

it('filters by rosso, which only forced strumenti can be', function () {
    $this->actingAs($this->admin);

    $rosso = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Dichiarato non idoneo']);
    $rosso->forzaSemaforo(StatoSemaforo::Rosso, 'Guarnizione rotta');
    $conScaduto = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Solo scaduto']);
    Intervento::factory()->forStrumento($conScaduto)->scaduto()->create();

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('stato', 'rosso')
        ->assertSee('Dichiarato non idoneo')
        ->assertDontSee('Solo scaduto')
        ->assertDontSee('Autoclave')
        ->set('stato', 'arancione')
        ->assertSee('Solo scaduto')
        ->assertDontSee('Dichiarato non idoneo');   // forzato rosso, non arancione
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

    // Forzature: il filtro SQL deve seguirle come fa statoSemaforoEffettivo().
    $this->actingAs($this->admin);
    $rosso = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Forzato rosso']);
    $rosso->forzaSemaforo(StatoSemaforo::Rosso, 'Non idoneo');
    $verdeForzato = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Forzato verde con scaduto']);
    Intervento::factory()->forStrumento($verdeForzato)->scaduto()->create();
    $verdeForzato->forzaSemaforo(StatoSemaforo::Verde);
    $arancioneForzato = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Forzato arancione senza interventi']);
    $arancioneForzato->forzaSemaforo(StatoSemaforo::Arancione);

    foreach (StatoSemaforo::cases() as $stato) {
        $filtrati = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->set('stato', $stato->value)
            ->viewData('strumenti')->pluck('id')->all();

        $attesi = Strumento::all()
            ->filter(fn (Strumento $s) => $s->statoSemaforoEffettivo() === $stato)
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

it('sorts by stato semaforo across all three values', function () {
    $this->actingAs($this->admin);

    $arancione = Strumento::factory()->forNode($this->dept)->create(['nome' => 'B arancione']);
    Intervento::factory()->forStrumento($arancione)->scaduto()->create();

    $rosso = Strumento::factory()->forNode($this->dept)->create(['nome' => 'C rosso']);
    $rosso->forzaSemaforo(StatoSemaforo::Rosso, 'Non idoneo');

    // Ha uno scaduto (calcolato arancione) ma è forzato verde: deve ordinare
    // come un verde (ordinale 0), non come un arancione.
    $verdeForzato = Strumento::factory()->forNode($this->dept)->create(['nome' => 'D verde forzato']);
    Intervento::factory()->forStrumento($verdeForzato)->scaduto()->create();
    $verdeForzato->forzaSemaforo(StatoSemaforo::Verde);

    $ordine = fn (string $dir) => Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'stato')->set('sortDir', $dir)
        ->viewData('strumenti')->pluck('id')->all();

    $asc = $ordine('asc');
    $desc = $ordine('desc');

    // asc: verdi (incluso il forzato) → arancioni → rosso in fondo.
    expect(last($asc))->toBe($rosso->id)
        ->and(head($desc))->toBe($rosso->id)
        ->and(array_search($verdeForzato->id, $asc, true))
        ->toBeLessThan(array_search($arancione->id, $asc, true));
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

// --- Garanzie come secondo ingresso del semaforo (ADR-004, punto 7) ---

it('is arancione when interventi are fine but a garanzia is scaduta', function () {
    // Nessun intervento in scadenza: senza le garanzie sarebbe verde.
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addMonths(6)->toDateString()]);
    Garanzia::factory()->forStrumento($this->strumento)->scaduta()->create();

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);
});

it('is arancione when a garanzia falls within the soglia', function () {
    Garanzia::factory()->forStrumento($this->strumento)
        ->create(['data_inizio' => today()->subMonths(12)->addDays(5)->toDateString(), 'durata_mesi' => 12]);

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Arancione);
});

it('stays verde when the garanzia is beyond the soglia', function () {
    Garanzia::factory()->forStrumento($this->strumento)->attiva()->create();

    expect($this->strumento->statoSemaforoCalcolato())->toBe(StatoSemaforo::Verde);
});

it('picks the nearest garanzia among several', function () {
    Garanzia::factory()->forStrumento($this->strumento)->attiva()->create();
    $vicina = Garanzia::factory()->forStrumento($this->strumento)->scaduta()->create();
    Garanzia::factory()->forStrumento($this->strumento)
        ->aOre(today()->addYears(2)->toDateString())->create();

    expect($this->strumento->prossimaGaranzia()->id)->toBe($vicina->id);
});

it('keeps the elenco aligned with the per-model calculation when garanzie are involved', function () {
    $this->actingAs($this->admin);

    $soloGaranziaScaduta = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Solo garanzia scaduta']);
    Garanzia::factory()->forStrumento($soloGaranziaScaduta)->scaduta()->create();

    $garanziaImminente = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Garanzia imminente']);
    Garanzia::factory()->forStrumento($garanziaImminente)
        ->create(['data_inizio' => today()->subMonths(12)->addDays(12)->toDateString(), 'durata_mesi' => 12]);

    $garanziaLontana = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Garanzia lontana']);
    Garanzia::factory()->forStrumento($garanziaLontana)->attiva()->create();

    $entrambi = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Intervento e garanzia']);
    Intervento::factory()->forStrumento($entrambi)->scaduto()->create();
    Garanzia::factory()->forStrumento($entrambi)->scaduta()->create();

    $forzatoVerde = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Forzato verde con garanzia scaduta']);
    Garanzia::factory()->forStrumento($forzatoVerde)->scaduta()->create();
    $forzatoVerde->forzaSemaforo(StatoSemaforo::Verde);

    // Il bulk dell'elenco deve coincidere col calcolo per-model, riga per riga.
    $semafori = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)->viewData('semafori');
    foreach (Strumento::all() as $s) {
        expect($semafori[$s->id])->toBe($s->statoSemaforoEffettivo(), "Disallineamento su «{$s->nome}»");
    }

    // E anche il filtro SQL, sui tre stati.
    foreach (StatoSemaforo::cases() as $stato) {
        $filtrati = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
            ->set('stato', $stato->value)->viewData('strumenti')->pluck('id')->all();
        $attesi = Strumento::all()
            ->filter(fn (Strumento $s) => $s->statoSemaforoEffettivo() === $stato)
            ->pluck('id')->all();

        expect($filtrati)->toEqualCanonicalizing($attesi, "Filtro «{$stato->value}» disallineato");
    }

    expect($semafori[$soloGaranziaScaduta->id])->toBe(StatoSemaforo::Arancione)
        ->and($semafori[$garanziaImminente->id])->toBe(StatoSemaforo::Arancione)
        ->and($semafori[$garanziaLontana->id])->toBe(StatoSemaforo::Verde)
        ->and($semafori[$forzatoVerde->id])->toBe(StatoSemaforo::Verde);
});

it('shows the garanzia in the prossima scadenza column when it is the nearest', function () {
    $conGaranzia = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Garanzia vicina']);
    Garanzia::factory()->forStrumento($conGaranzia)
        ->create(['data_inizio' => today()->subMonths(12)->addDays(12)->toDateString(), 'durata_mesi' => 12]);
    Intervento::factory()->forStrumento($conGaranzia)
        ->create(['data_scadenza' => today()->addMonths(6)->toDateString()]);

    $conIntervento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Intervento vicino']);
    Intervento::factory()->forStrumento($conIntervento)
        ->create(['tipo' => TipoIntervento::Taratura, 'data_scadenza' => today()->addDays(3)->toDateString()]);
    Garanzia::factory()->forStrumento($conIntervento)->attiva()->create();

    Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->assertSee('Garanzia tra 12 gg')   // la garanzia batte l'intervento a 6 mesi
        ->assertSee('Taratura tra 3 gg');   // qui vince l'intervento
});

it('sorts prossima scadenza across interventi and garanzie', function () {
    $garanziaVicina = Strumento::factory()->forNode($this->dept)->create(['nome' => 'A']);
    Garanzia::factory()->forStrumento($garanziaVicina)
        ->create(['data_inizio' => today()->subMonths(12)->addDays(2)->toDateString(), 'durata_mesi' => 12]);

    $interventoMedio = Strumento::factory()->forNode($this->dept)->create(['nome' => 'B']);
    Intervento::factory()->forStrumento($interventoMedio)
        ->create(['data_scadenza' => today()->addDays(20)->toDateString()]);

    $lontano = Strumento::factory()->forNode($this->dept)->create(['nome' => 'C']);
    Garanzia::factory()->forStrumento($lontano)
        ->aOre(today()->addYears(3)->toDateString())->create();

    $ordine = Livewire::actingAs($this->admin)->test(ElencoStrumenti::class)
        ->set('sortBy', 'prossima_scadenza')->set('sortDir', 'asc')
        ->viewData('strumenti')->pluck('id')->all();

    // Ordinati sul minimo fra le due fonti; senza scadenze in fondo.
    expect(array_slice($ordine, 0, 3))->toBe([$garanziaVicina->id, $interventoMedio->id, $lontano->id])
        ->and(last($ordine))->toBe($this->strumento->id);   // Autoclave: nessuna scadenza
});

<?php

use App\Enums\StatoSemaforo;
use App\Enums\VisibilitaGaranzieRicambio;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Support\Semaforo;
use App\Support\Strumenti\MotiviDellaScheda;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Blade;
use Spatie\Permission\Models\Role;

/**
 * I numerini sui tab della scheda di una macchina (🔗 ADR-055): accanto a
 * «Interventi», «Ricambi» e «Garanzie», quante scadenze da gestire stanno lì.
 *
 * Contano gli stessi motivi che la Panoramica elenca (ADR-024), e per questo
 * ne ereditano la regola di privacy: la garanzia di un pezzo montato non
 * accende nessun numero per chi non ha titolo a saperla (ADR-029).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->laboratorio = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create(['nome' => 'Chimica']);
    $this->strumento = Strumento::factory()->forNode($this->laboratorio)->create(['nome' => 'Autoclave']);

    $this->persona = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->ente->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = ($this->persona)('Admin');

    // La garanzia di un pezzo montato su questa macchina, in scadenza.
    $this->pezzoInScadenza = function (): Garanzia {
        // Un nome per pezzo: il catalogo non ammette due voci uguali nello stesso Ente.
        static $quanti = 0;
        $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Lampada UV '.(++$quanti)]);
        $utilizzo = RicambioUtilizzo::factory()->forStrumento($this->strumento)->forRicambio($ricambio)->create();

        return Garanzia::factory()->forRicambio($utilizzo)->imminente()->create();
    };

    /** Il numero scritto accanto al nome di un tab, o `null` se non ce n'è. */
    $this->numeroSul = function (string $html, string $tab): ?string {
        expect($html)->toContain('data-tab="'.$tab.'"');

        // Dal `data-tab` alla chiusura del bottone: il numerino, se c'è, sta lì.
        preg_match('/data-tab="'.$tab.'">(.*?)<\/button>/s', $html, $bottone);

        return preg_match('/data-da-gestire="(\d+)"/', $bottone[1] ?? '', $numero) === 1 ? $numero[1] : null;
    };
});

it('puts next to each tab the number of scadenze to deal with that live there', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create(['data_scadenza' => today()->subDays(10)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)->create(['data_scadenza' => today()->addDays(5)->toDateString()]);
    Garanzia::factory()->forStrumento($this->strumento)->imminente()->create();
    // Tre numeri diversi: con due uguali, un numero finito sul tab sbagliato
    // non si distinguerebbe da quello giusto.
    ($this->pezzoInScadenza)();
    ($this->pezzoInScadenza)();
    ($this->pezzoInScadenza)();

    $html = scheda($this->admin, $this->strumento)->assertSee('Motivi (6)')->html();

    expect(($this->numeroSul)($html, 'interventi'))->toBe('2')
        ->and(($this->numeroSul)($html, 'garanzie'))->toBe('1')
        ->and(($this->numeroSul)($html, 'ricambi'))->toBe('3')
        // Nessuna scadenza abita in questi tre.
        ->and(($this->numeroSul)($html, 'panoramica'))->toBeNull()
        ->and(($this->numeroSul)($html, 'anagrafica'))->toBeNull()
        ->and(($this->numeroSul)($html, 'documenti'))->toBeNull();
});

it('shows no number at all on a machine with nothing to deal with', function () {
    // Uno «0» su ogni tab sarebbe rumore: il numero pesa perché di solito non c'è.
    Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()]);
    Intervento::factory()->forStrumento($this->strumento)->fatto()->create();
    Garanzia::factory()->forStrumento($this->strumento)->attiva()->create();

    $html = scheda($this->admin, $this->strumento)->assertSee('Nessuna scadenza aperta o imminente.')->html();

    expect($html)->not->toContain('data-da-gestire');
});

it('counts a scadenza from the day it enters the soglia, and not the day before', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumento)
        ->create(['data_scadenza' => today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()]);

    expect(($this->numeroSul)(scheda($this->admin, $this->strumento)->html(), 'interventi'))->toBeNull();

    $intervento->update(['data_scadenza' => today()->addDays(Semaforo::giorniImminente())->toDateString()]);

    expect(($this->numeroSul)(scheda($this->admin, $this->strumento)->html(), 'interventi'))->toBe('1');
});

it('keeps the numbers when the semaforo is forced: a forzatura does not hide what is still to do', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();

    // Chi forza è l'utente autenticato: `forzaSemaforo()` non lo riceve.
    $this->actingAs($this->admin);
    $this->strumento->forzaSemaforo(StatoSemaforo::Verde, 'Verificata sul campo');

    expect($this->strumento->fresh()->forced_reason)->toBe('Verificata sul campo')
        ->and(($this->numeroSul)(scheda($this->admin, $this->strumento->fresh())->html(), 'interventi'))->toBe('1');
});

it('never lights a number on Ricambi for a tenant whose ente hides the garanzie of the parts', function () {
    // 🔴 ADR-029: un «1» su Ricambi direbbe che su quella macchina un pezzo è
    // stato sostituito — cioè ciò che il testo neutro della Panoramica tace.
    ($this->pezzoInScadenza)();
    $tenant = ($this->persona)('Tenant');

    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);

    $html = scheda($tenant->fresh(), $this->strumento)
        // Il motivo c'è, in forma neutra: il pallino è dovuto a tutti.
        ->assertSee('Motivi (1)')
        ->assertSee('Garanzia in scadenza il')
        ->html();

    expect(($this->numeroSul)($html, 'ricambi'))->toBeNull()
        ->and($html)->not->toContain('data-da-gestire');

    // Il complementare, senza cui il test qui sopra sarebbe verde anche con i
    // contatori rotti per tutti.
    $this->ente->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Lettura);

    expect(($this->numeroSul)(scheda($tenant->fresh(), $this->strumento)->html(), 'ricambi'))->toBe('1');
});

it('asks the ability and not the bare permission, tab by tab', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    Garanzia::factory()->forStrumento($this->strumento)->imminente()->create();
    ($this->pezzoInScadenza)();

    $diagnosi = fn () => $this->strumento->fresh()->diagnosiSemaforo();

    $this->actingAs($this->admin);
    expect(MotiviDellaScheda::contaPerTab($diagnosi()))->toBe(['interventi' => 1, 'garanzie' => 1, 'ricambi' => 1]);

    // Chi vede gli interventi e nient'altro.
    $soloInterventi = Role::create(['name' => 'Solo interventi', 'guard_name' => 'web']);
    $soloInterventi->givePermissionTo(['strumenti.view', 'interventi.view']);
    $this->actingAs(($this->persona)('Solo interventi'));
    expect(MotiviDellaScheda::contaPerTab($diagnosi()))->toBe(['interventi' => 1]);

    // Chi ha titolo alla garanzia del pezzo ma non al tab in cui sta: il motivo
    // lo legge per esteso in Panoramica, ma non c'è un tab su cui contarlo.
    $senzaTabRicambi = Role::create(['name' => 'Garanzie senza ricambi', 'guard_name' => 'web']);
    $senzaTabRicambi->givePermissionTo(['strumenti.view', 'garanzie.macchina.view', 'garanzie.ricambio.view']);
    $this->actingAs(($this->persona)('Garanzie senza ricambi'));
    expect(MotiviDellaScheda::contaPerTab($diagnosi()))->toBe(['garanzie' => 1]);

    // Chi ha il tab Ricambi ma non il titolo alla garanzia del pezzo.
    $senzaGaranziePezzi = Role::create(['name' => 'Ricambi senza garanzie', 'guard_name' => 'web']);
    $senzaGaranziePezzi->givePermissionTo(['strumenti.view', 'ricambio_utilizzo.view']);
    $this->actingAs(($this->persona)('Ricambi senza garanzie'));
    expect(MotiviDellaScheda::contaPerTab($diagnosi()))->toBe([]);
});

it('never counts more than the Panoramica lists', function () {
    Intervento::factory()->forStrumento($this->strumento)->scaduto()->create();
    Garanzia::factory()->forStrumento($this->strumento)->imminente()->create();
    ($this->pezzoInScadenza)();

    foreach (['Admin', 'Tenant', 'Responsabile Reparto', 'Tecnico'] as $ruolo) {
        $chi = ($this->persona)($ruolo);
        $chi->unitaResponsabili()->attach($this->laboratorio);
        $this->actingAs($chi->fresh());

        $diagnosi = $this->strumento->fresh()->diagnosiSemaforo();

        expect(array_sum(MotiviDellaScheda::contaPerTab($diagnosi)))->toBeLessThanOrEqual(count($diagnosi->motivi));
    }
});

it('says the number in words too, for who cannot see it', function (int $quante, string $visibile, string $detto) {
    $html = Blade::render('<x-ui.da-gestire :quante="$quante" />', ['quante' => $quante]);

    expect($html)
        ->toContain('<span aria-hidden="true">'.$visibile.'</span>')
        ->toContain('<span class="sr-only">('.$detto.')</span>')
        ->toContain('data-da-gestire="'.$quante.'"');
})->with([
    'una' => [1, '1', 'una scadenza da gestire'],
    'due' => [2, '2', '2 scadenze da gestire'],
    'novantanove' => [99, '99', '99 scadenze da gestire'],
    // Oltre le due cifre il numerino non si allarga: il conto vero resta nel testo.
    'cento' => [100, '99+', '100 scadenze da gestire'],
]);

it('renders nothing for zero, and nothing for a count that makes no sense', function (int $quante) {
    expect(trim(Blade::render('<x-ui.da-gestire :quante="$quante" />', ['quante' => $quante])))->toBe('');
})->with([0, -1]);

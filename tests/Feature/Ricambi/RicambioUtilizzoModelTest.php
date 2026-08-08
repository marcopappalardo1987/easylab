<?php

use App\Enums\TipoIntervento;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Guardie di coerenza di `ricambio_utilizzo` (ERD §7.2). Area rossa: reggono il
 * livello 2 del global scope e il doppio salto del semaforo (ADR-020), quindi
 * ogni guardia ha qui il suo test NEGATIVO.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $deptA = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $this->strumentoA = Strumento::factory()->forNode($deptA)->create();
    $this->ricambioA = Ricambio::factory()->forTenant($this->enteA)->create();

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    $this->strumentoB = Strumento::factory()->forNode($deptB)->create();
    $this->ricambioB = Ricambio::factory()->forTenant($this->enteB)->create();

    $this->adminA = User::factory()->create(['tenant_id' => $this->enteA->id]);
    $this->adminA->assignRole('Admin');
});

it('rejects a strumento belonging to another tenant', function () {
    $this->actingAs($this->adminA);

    expect(fn () => RicambioUtilizzo::create([
        'strumento_id' => $this->strumentoB->id,
        'ricambio_id' => $this->ricambioA->id,
        'data' => today()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class, 'strumento');
});

it('rejects a payload forged coherently around another tenant', function () {
    // ⚠️ È il test che distingue `creating` da `saving`, e il caso precedente
    // NON lo faceva: lì `tenant_id` resta null e la guardia scatta comunque,
    // qualunque sia l'evento. Qui il payload è coerente CON L'ENTE B — tenant,
    // strumento e ricambio tutti di B — quindi:
    //  - su `saving` la guardia girerebbe prima che BelongsToTenant riscriva
    //    `tenant_id`, troverebbe tutto coerente e lascerebbe passare; subito
    //    dopo `creating` ritimbrerebbe il tenant ad A, e la riga finirebbe
    //    salvata con tenant A che punta a strumento e catalogo di B;
    //  - su `creating` (dove la guardia sta davvero) il trait ha già rimesso A,
    //    e il confronto con lo strumento di B fallisce.
    // È la rottura esatta dell'invariante su cui poggia
    // DepartmentThroughStrumentoScope, che filtra per `strumento_id` fidandosi
    // che il tenant coincida.
    $this->actingAs($this->adminA);

    expect(fn () => RicambioUtilizzo::create([
        'tenant_id' => $this->enteB->id,
        'strumento_id' => $this->strumentoB->id,
        'ricambio_id' => $this->ricambioB->id,
        'data' => today()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class, 'strumento');

    expect(RicambioUtilizzo::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects a ricambio belonging to another tenant', function () {
    // Non è ordine ma una fuga: la ricerca incrociata parte da `ricambio_id` e
    // mostrerebbe all'Ente A il nome di un pezzo dell'Ente B.
    $this->actingAs($this->adminA);

    expect(fn () => RicambioUtilizzo::create([
        'strumento_id' => $this->strumentoA->id,
        'ricambio_id' => $this->ricambioB->id,
        'data' => today()->toDateString(),
    ]))->toThrow(InvalidArgumentException::class, 'ricambio');
});

it('rejects an intervento belonging to a different strumento', function () {
    $altroStrumento = Strumento::factory()->forNode($this->strumentoA->unita)->create();
    $intervento = Intervento::factory()->forStrumento($altroStrumento)->create();

    expect(fn () => RicambioUtilizzo::factory()
        ->forStrumento($this->strumentoA)
        ->forRicambio($this->ricambioA)
        ->create(['intervento_id' => $intervento->id]))
        ->toThrow(InvalidArgumentException::class, 'intervento');
});

it('accepts a null intervento_id, for rows entered from the tab', function () {
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumentoA)
        ->forRicambio($this->ricambioA)
        ->create();

    expect($utilizzo->intervento_id)->toBeNull()
        ->and($utilizzo->quantita)->toBe(1);
});

it('links the intervento when it belongs to the same strumento', function () {
    $intervento = Intervento::factory()->forStrumento($this->strumentoA)->create([
        'tipo' => TipoIntervento::ManutenzioneStraordinaria,
    ]);

    $utilizzo = RicambioUtilizzo::factory()
        ->forIntervento($intervento)
        ->forRicambio($this->ricambioA)
        ->create();

    expect($utilizzo->intervento_id)->toBe($intervento->id)
        ->and($utilizzo->strumento_id)->toBe($this->strumentoA->id);
});

it('rejects a quantita below one', function () {
    // Nel model e non come `unsignedInteger`: SQLite ignora l'unsigned, quindi
    // il vincolo esisterebbe solo su Postgres. È la lezione di `durata_mesi >= 1`.
    expect(fn () => RicambioUtilizzo::factory()
        ->forStrumento($this->strumentoA)
        ->forRicambio($this->ricambioA)
        ->create(['quantita' => 0]))
        ->toThrow(InvalidArgumentException::class, 'quantita');
});

it('skips the coherence checks when only the data changes', function () {
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumentoA)
        ->forRicambio($this->ricambioA)
        ->create();

    // Congela l'ottimizzazione: tre SELECT non si ripagano per un cambio di data.
    $utilizzo->data = today()->subDay();

    expect(fn () => $utilizzo->save())->not->toThrow(InvalidArgumentException::class);
});

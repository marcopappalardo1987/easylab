<?php

use App\Models\Ricambio;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Collega-o-crea del catalogo (ADR-008, chiave passata al nome da ADR-022).
 * È il mattone del form intervento (blocco 3): le sue regole si congelano qui,
 * dove non serve montare una UI per esercitarle.
 */
beforeEach(function () {
    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
});

it('links the existing entry when the normalised name matches', function () {
    $esistente = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Guarnizione O-Ring']);

    $collegato = Ricambio::collegaOCrea('  guarnizione   O-RING ', $this->enteA->id);

    expect($collegato->id)->toBe($esistente->id)
        ->and(Ricambio::where('tenant_id', $this->enteA->id)->count())->toBe(1);
});

it('creates a new entry when the name is unknown', function () {
    $creato = Ricambio::collegaOCrea('Filtro HEPA', $this->enteA->id);

    expect($creato->exists)->toBeTrue()
        ->and($creato->tenant_id)->toBe($this->enteA->id)
        ->and($creato->nome_normalizzato)->toBe('filtro hepa');
});

it('does not rewrite the existing nome when linking', function () {
    // La grafia del primo che ha inserito vince: riscriverla cambierebbe
    // l'etichetta sotto le righe storiche di altri.
    $esistente = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Guarnizione O-Ring']);

    Ricambio::collegaOCrea('GUARNIZIONE O-RING', $this->enteA->id);

    expect($esistente->fresh()->nome)->toBe('Guarnizione O-Ring');
});

it('keeps two tenants catalogues separate even in console context', function () {
    // In console il TenantScope NON filtra: senza il `where('tenant_id')`
    // esplicito nel collega-o-crea, l'Ente B aggancerebbe la voce dell'Ente A e
    // da lì ne mostrerebbe il nome del pezzo. È il test che dimostra che quel
    // filtro non è ridondante rispetto al global scope.
    $diA = Ricambio::collegaOCrea('Filtro HEPA', $this->enteA->id);
    $diB = Ricambio::collegaOCrea('Filtro HEPA', $this->enteB->id);

    expect($diB->id)->not->toBe($diA->id)
        ->and($diB->tenant_id)->toBe($this->enteB->id)
        ->and(Ricambio::whereIn('tenant_id', [$this->enteA->id, $this->enteB->id])->count())->toBe(2);
});

it('recreates an entry whose twin is soft-deleted', function () {
    // È il caso che giustifica il `where deleted_at is null` dell'indice: il
    // merge doppioni cestina per mestiere, e una voce cestinata non deve
    // impedire di registrare di nuovo quel pezzo.
    $cestinato = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Cinghia']);
    $cestinato->delete();

    $nuovo = Ricambio::collegaOCrea('Cinghia', $this->enteA->id);

    expect($nuovo->id)->not->toBe($cestinato->id)
        ->and($nuovo->trashed())->toBeFalse();
});

it('rejects a duplicate normalised name at database level', function () {
    // Insert diretto: salta il model, quindi prova che il vincolo vive nel DB e
    // non solo nell'hook. Senza, un bug futuro di normalizzazione produrrebbe
    // doppioni in silenzio.
    Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Sensore PT100']);

    expect(fn () => DB::table('ricambi')->insert([
        'tenant_id' => $this->enteA->id,
        'nome' => 'Sensore PT100',
        'nome_normalizzato' => 'sensore pt100',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

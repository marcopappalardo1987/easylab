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

it('does not abort the surrounding transaction when the insert fails', function () {
    // ⚠️ Il test che discrimina la correzione del savepoint, e vale SOLO su
    // Postgres: lì una violazione di vincolo aborta l'intera transazione
    // (25P02) e ogni query successiva fallisce, ri-SELECT di recupero comprese.
    // Su SQLite il rollback è per statement, quindi senza savepoint il codice
    // passerebbe comunque — è la ragione per cui questo test esiste e per cui
    // la relativa mutazione cade su un driver solo.
    //
    // Si usa una violazione di FK sul tenant e non una corsa sull'unique:
    // è deterministica e non richiede due connessioni.
    $errore = null;

    DB::transaction(function () use (&$errore) {
        try {
            Ricambio::collegaOCrea('Filtro HEPA', tenantId: 999_999);
        } catch (QueryException $e) {
            $errore = $e;
        }

        // Senza il savepoint questa query fallirebbe con 25P02, portandosi via
        // anche il rollback di RefreshDatabase: il bug si presenterebbe come
        // "suite impazzita", non come un test rosso.
        expect(DB::table('ricambi')->count())->toBe(0);
    });

    // 23503 = foreign_key_violation. Senza la correzione affiorerebbe invece
    // l'eccezione della ri-SELECT dentro il catch, cioè 25P02.
    expect($errore?->getCode())->toBe('23503');
})->skip(
    fn () => DB::connection()->getDriverName() !== 'pgsql',
    'Vale solo su Postgres: SQLite fa rollback per statement e non aborta la transazione.'
);

it('recovers the entry created by a concurrent writer', function () {
    // La corsa si simula con `beforeStartingTransaction`, che gira PRIMA che il
    // savepoint venga creato: così la riga del concorrente sopravvive al
    // ROLLBACK TO SAVEPOINT. Un listener su `Ricambio::creating` finirebbe
    // invece DENTRO il savepoint e verrebbe annullato con esso — è la trappola
    // in cui si cade scrivendo questo test.
    $gia = false;
    $enteId = $this->enteA->id;

    DB::connection()->beforeStartingTransaction(function ($connection) use (&$gia, $enteId) {
        if ($gia || $connection->transactionLevel() < 1) {
            return; // solo la transazione annidata di collegaOCrea
        }
        $gia = true;

        DB::table('ricambi')->insert([
            'tenant_id' => $enteId,
            'nome' => 'Filtro HEPA',
            'nome_normalizzato' => 'filtro hepa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $ricambio = DB::transaction(fn () => Ricambio::collegaOCrea('Filtro HEPA', $enteId));

    expect($ricambio->nome_normalizzato)->toBe('filtro hepa')
        ->and(Ricambio::where('tenant_id', $enteId)->count())->toBe(1);
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

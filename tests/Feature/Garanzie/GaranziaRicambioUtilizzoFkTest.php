<?php

use App\Enums\SoggettoGaranzia;
use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\QueryException;

/**
 * Debito S3 saldato (S4 blocco 1): `garanzie.ricambio_utilizzo_id` ha finalmente
 * una FK, perché la tabella referenziata ora esiste.
 *
 * Il vincolo vive nel DATABASE e non solo nel docblock: `foreign_key_constraints`
 * è `true` di default (config/database.php) e `phpunit.xml` non lo sovrascrive,
 * quindi SQLite lo applica come Postgres.
 */
beforeEach(function () {
    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($dept)->create(['nome' => 'Autoclave']);
    $this->ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Guarnizione O-Ring']);
});

it('rejects a garanzia pointing at a non-existent ricambio_utilizzo', function () {
    expect(fn () => Garanzia::factory()->create([
        'tenant_id' => $this->ente->id,
        'soggetto' => SoggettoGaranzia::Ricambio,
        'strumento_id' => null,
        'ricambio_utilizzo_id' => 42, // l'intero libero che la S3 ammetteva
    ]))->toThrow(QueryException::class);
});

it('walks from the garanzia up to the strumento through the mounted part', function () {
    // È il doppio salto che ADR-020 userà per il semaforo: qui se ne verifica
    // solo il cablaggio, il calcolo arriva nel blocco 4.
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio($this->ricambio)
        ->create();

    $garanzia = Garanzia::factory()->forRicambio($utilizzo)->create();

    expect($garanzia->ricambioUtilizzo->strumento->id)->toBe($this->strumento->id)
        ->and($utilizzo->garanzia->id)->toBe($garanzia->id);
});

it('keeps the existing garanzie indexes alive after the SQLite table rebuild', function () {
    // Su SQLite l'ALTER che aggiunge la FK ricostruisce la tabella via __temp__:
    // se `BlueprintState` non ri-emettesse gli indici, il progetto perderebbe in
    // silenzio quelli su cui poggiano semaforo e scadenzario (ERD §11).
    $indici = collect(Schema::getIndexes('garanzie'))->pluck('columns')->map(fn ($c) => implode(',', $c));

    expect($indici)->toContain('strumento_id,data_scadenza_effettiva')
        ->and($indici)->toContain('data_scadenza_effettiva')
        ->and($indici)->toContain('ricambio_utilizzo_id');
});

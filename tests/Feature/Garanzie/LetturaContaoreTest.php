<?php

use App\Models\LetturaContaore;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;

/**
 * Letture contaore (ERD §5.3 — ADR-004): append-only come gli spostamenti.
 */
beforeEach(function () {
    $this->ente = UnitaOrganizzativa::factory()->ente()->create();
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create();
});

it('is append-only: update and delete throw', function () {
    $lettura = LetturaContaore::factory()->forStrumento($this->strumento)->create(['ore' => 1200]);

    expect(fn () => $lettura->update(['ore' => 9999]))->toThrow(RuntimeException::class);
    expect(fn () => $lettura->delete())->toThrow(RuntimeException::class);

    expect($lettura->fresh()->ore)->toBe(1200);
});

it('lists the storico with the most recent reading first', function () {
    LetturaContaore::factory()->forStrumento($this->strumento)->create(['data' => today()->subMonths(6)->toDateString(), 'ore' => 800]);
    LetturaContaore::factory()->forStrumento($this->strumento)->create(['data' => today()->toDateString(), 'ore' => 2400]);
    LetturaContaore::factory()->forStrumento($this->strumento)->create(['data' => today()->subMonths(3)->toDateString(), 'ore' => 1600]);

    expect($this->strumento->lettureContaore->pluck('ore')->all())->toBe([2400, 1600, 800]);
});

it('keeps the tenant aligned with the strumento', function () {
    $lettura = LetturaContaore::factory()->forStrumento($this->strumento)->create();

    expect($lettura->tenant_id)->toBe($this->strumento->tenant_id);
});

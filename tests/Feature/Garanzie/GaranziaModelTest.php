<?php

use App\Enums\SoggettoGaranzia;
use App\Models\Garanzia;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Semaforo;

/**
 * Normalizzazione e invarianti della garanzia (ERD §6.1 — ADR-004/019).
 * Fixture in contesto console (nessun actingAs): i global scope non filtrano.
 */
beforeEach(function () {
    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->strumento = Strumento::factory()->forNode($this->dept)->create(['nome' => 'Autoclave']);
});

// --- Normalizzazione: tutto converge in data_scadenza_effettiva ---

it('computes the effective date from inizio plus durata', function () {
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create([
        'data_inizio' => '2026-01-15',
        'durata_mesi' => 24,
    ]);

    expect($garanzia->data_scadenza_effettiva->toDateString())->toBe('2028-01-15');
});

it('recomputes the effective date on every save', function () {
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create([
        'data_inizio' => '2026-01-01',
        'durata_mesi' => 12,
    ]);
    expect($garanzia->data_scadenza_effettiva->toDateString())->toBe('2027-01-01');

    $garanzia->update(['durata_mesi' => 36]);

    expect($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2029-01-01');
});

it('ignores a forged data_scadenza_effettiva in the payload', function () {
    // Il campo è fuori da $fillable E ricalcolato: nessun payload lo falsifica.
    $garanzia = Garanzia::factory()->forStrumento($this->strumento)->create([
        'data_inizio' => '2026-01-01',
        'durata_mesi' => 12,
        'data_scadenza_effettiva' => '2099-12-31',
    ]);

    expect($garanzia->data_scadenza_effettiva->toDateString())->toBe('2027-01-01');

    $garanzia->update(['data_scadenza_effettiva' => '2099-12-31']);

    expect($garanzia->fresh()->data_scadenza_effettiva->toDateString())->toBe('2027-01-01');
});

it('rejects a garanzia without durata_mesi', function () {
    // Unica forma rimasta dopo ADR-019: senza durata non c'è scadenza da
    // normalizzare, e una garanzia senza scadenza non pilota nulla.
    expect(fn () => Garanzia::factory()->forStrumento($this->strumento)->create([
        'durata_mesi' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a durata_mesi below one, whoever the caller is', function () {
    // Il `min:1` del form copre l'utente; questa guardia copre seeder, import e
    // migration di backfill — che è come sono nate 90 righe con durata 0.
    foreach ([0, -3] as $durata) {
        expect(fn () => Garanzia::factory()->forStrumento($this->strumento)->create([
            'durata_mesi' => $durata,
        ]))->toThrow(InvalidArgumentException::class);
    }

    expect(Garanzia::withoutGlobalScopes()->count())->toBe(0);
});

// --- Invariante soggetto/FK (ERD §6.1) ---

it('rejects a garanzia macchina without a strumento', function () {
    expect(fn () => Garanzia::factory()->create([
        'tenant_id' => $this->ente->id,
        'soggetto' => SoggettoGaranzia::Macchina,
        'strumento_id' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a garanzia with both foreign keys', function () {
    expect(fn () => Garanzia::factory()->forStrumento($this->strumento)->create([
        'ricambio_utilizzo_id' => 1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a garanzia ricambio pointing at a strumento', function () {
    expect(fn () => Garanzia::factory()->forStrumento($this->strumento)->create([
        'soggetto' => SoggettoGaranzia::Ricambio,
    ]))->toThrow(InvalidArgumentException::class);
});

it('accepts a garanzia ricambio with only the ricambio_utilizzo_id', function () {
    // La FK vera arriva in S4: oggi la colonna è libera, di proposito.
    $garanzia = Garanzia::factory()->create([
        'tenant_id' => $this->ente->id,
        'soggetto' => SoggettoGaranzia::Ricambio,
        'strumento_id' => null,
        'ricambio_utilizzo_id' => 42,
    ]);

    expect($garanzia->soggetto)->toBe(SoggettoGaranzia::Ricambio)
        ->and($garanzia->strumento_id)->toBeNull();
});

// --- Confine con il motore semaforo ---

it('matches the semaforo boundary in the entroSoglia scope', function () {
    $soglia = Semaforo::giorniImminente();

    // Una garanzia per ciascun confine, tutte a tipo data.
    $casi = [
        'scaduta' => today()->subDay(),
        'oggi' => today(),
        'soglia' => today()->addDays($soglia),
        'oltre' => today()->addDays($soglia + 1),
    ];

    $ids = [];
    foreach ($casi as $etichetta => $scadenza) {
        // data_inizio + 12 mesi = la scadenza voluta.
        $ids[$etichetta] = Garanzia::factory()->forStrumento($this->strumento)->create([
            'data_inizio' => $scadenza->copy()->subMonths(12)->toDateString(),
            'durata_mesi' => 12,
        ])->id;
    }

    $rilevanti = Garanzia::entroSoglia()->pluck('id')->all();

    // Scaduta e imminente pesano; oltre soglia no.
    expect($rilevanti)->toContain($ids['scaduta'], $ids['oggi'], $ids['soglia'])
        ->and($rilevanti)->not->toContain($ids['oltre']);
});

it('knows whether it is already expired', function () {
    $scaduta = Garanzia::factory()->forStrumento($this->strumento)->scaduta()->create();
    $attiva = Garanzia::factory()->forStrumento($this->strumento)->attiva()->create();

    expect($scaduta->isScaduta())->toBeTrue()
        ->and($attiva->isScaduta())->toBeFalse();
});

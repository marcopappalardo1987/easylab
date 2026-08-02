<?php

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Models\Intervento;
use App\Support\Semaforo;

/**
 * Motore semaforo (ADR-005 — S3 punto 4): funzione pura, nessun DB.
 * Confini: scaduto < oggi · imminente [oggi, oggi+soglia] · verde oltre.
 */
it('is verde with no scadenze at all', function () {
    expect(Semaforo::calcola(null))->toBe(StatoSemaforo::Verde);
});

it('is arancione when the prossima scadenza is in the past (scaduto-non-fatto)', function () {
    expect(Semaforo::calcola(today()->subDay()))->toBe(StatoSemaforo::Arancione)
        ->and(Semaforo::calcola(today()->subMonths(6)))->toBe(StatoSemaforo::Arancione);
});

it('is arancione for a scadenza today, which is imminente and not yet scaduto', function () {
    expect(Semaforo::calcola(today()))->toBe(StatoSemaforo::Arancione);

    // Coerenza col confine canonico del model: oggi NON è scaduto.
    $oggi = new Intervento(['stato' => StatoIntervento::NonFatto, 'data_scadenza' => today()]);
    expect($oggi->isScaduto())->toBeFalse();
});

it('is arancione at exactly the soglia (inclusive boundary)', function () {
    expect(Semaforo::calcola(today()->addDays(Semaforo::giorniImminente())))
        ->toBe(StatoSemaforo::Arancione);
});

it('is verde beyond the soglia', function () {
    expect(Semaforo::calcola(today()->addDays(Semaforo::giorniImminente() + 1)))
        ->toBe(StatoSemaforo::Verde);
});

it('never returns rosso, whatever the date', function () {
    // Il rosso è solo forzatura manuale (punto 5).
    foreach ([today()->subYears(3), today()->subDay(), today(), today()->addDays(30), today()->addYears(2), null] as $data) {
        expect(Semaforo::calcola($data))->not->toBe(StatoSemaforo::Rosso);
    }
});

it('reads the soglia from config/easylab.php', function () {
    config(['easylab.semaforo.giorni_imminente' => 0]);

    expect(Semaforo::giorniImminente())->toBe(0)
        ->and(Semaforo::calcola(today()))->toBe(StatoSemaforo::Arancione)   // scadenza oggi: sempre imminente
        ->and(Semaforo::calcola(today()->addDay()))->toBe(StatoSemaforo::Verde); // domani: fuori soglia

    config(['easylab.semaforo.giorni_imminente' => 60]);
    expect(Semaforo::calcola(today()->addDays(45)))->toBe(StatoSemaforo::Arancione);
});

it('accepts a garanzia scadenza as second input (punto 7 ready)', function () {
    expect(Semaforo::calcola(null, today()->addDays(10)))->toBe(StatoSemaforo::Arancione)
        ->and(Semaforo::calcola(null, today()->subDay()))->toBe(StatoSemaforo::Arancione)
        ->and(Semaforo::calcola(null, today()->addDays(60)))->toBe(StatoSemaforo::Verde);

    // Interventi verdi + garanzia imminente → arancione comunque.
    expect(Semaforo::calcola(today()->addYear(), today()->addDays(5)))->toBe(StatoSemaforo::Arancione);
});

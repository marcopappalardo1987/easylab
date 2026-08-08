<?php

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Enums\TipoMotivoSemaforo;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Support\DiagnosiSemaforo;
use App\Support\MotivoSemaforo;
use App\Support\Semaforo;

/**
 * Diagnosi del semaforo (ADR-024): il motore non dice più solo *se* accendere
 * l'arancione, ma *perché*. Funzione pura, nessun DB — i model qui sono
 * istanziati non salvati, solo per interrogarne le regole di dominio.
 */
function motivoIntervento(string $scadenza, StatoIntervento $stato = StatoIntervento::NonFatto): MotivoSemaforo
{
    return MotivoSemaforo::daIntervento(new Intervento([
        'stato' => $stato,
        'data_scadenza' => $scadenza,
        'descrizione' => 'Taratura annuale',
    ]));
}

function motivoGaranzia(string $scadenza): MotivoSemaforo
{
    $garanzia = new Garanzia;
    $garanzia->data_scadenza_effettiva = $scadenza;

    return MotivoSemaforo::daGaranziaMacchina($garanzia);
}

// --- Stato derivato dai motivi ---

it('is verde with no motivi at all', function () {
    $diagnosi = Semaforo::diagnostica();

    expect($diagnosi->stato)->toBe(StatoSemaforo::Verde)
        ->and($diagnosi->motivi)->toBe([]);
});

it('keeps only the candidati within the soglia', function () {
    $diagnosi = Semaforo::diagnostica(
        motivoIntervento(today()->addYear()->toDateString()),          // fuori soglia: scartato
        motivoIntervento(today()->subDay()->toDateString()),           // scaduto: tenuto
        motivoGaranzia(today()->addDays(5)->toDateString()),           // imminente: tenuta
    );

    expect($diagnosi->stato)->toBe(StatoSemaforo::Arancione)
        ->and($diagnosi->motivi)->toHaveCount(2);
});

it('orders motivi by scadenza, most urgent first', function () {
    $diagnosi = Semaforo::diagnostica(
        motivoGaranzia(today()->addDays(20)->toDateString()),
        motivoIntervento(today()->subMonths(2)->toDateString()),
        motivoIntervento(today()->addDays(3)->toDateString()),
    );

    $date = array_map(fn (MotivoSemaforo $m) => $m->scadenza->toDateString(), $diagnosi->motivi);

    expect($date)->toBe([
        today()->subMonths(2)->toDateString(),
        today()->addDays(3)->toDateString(),
        today()->addDays(20)->toDateString(),
    ]);
});

it('honours the inclusive soglia boundary', function () {
    expect(Semaforo::diagnostica(motivoIntervento(today()->addDays(Semaforo::giorniImminente())->toDateString()))->motivi)
        ->toHaveCount(1)
        ->and(Semaforo::diagnostica(motivoIntervento(today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()))->motivi)
        ->toBe([]);
});

it('reads the soglia from config like calcola does', function () {
    config(['easylab.semaforo.giorni_imminente' => 0]);
    expect(Semaforo::diagnostica(motivoIntervento(today()->addDay()->toDateString()))->motivi)->toBe([]);

    config(['easylab.semaforo.giorni_imminente' => 60]);
    expect(Semaforo::diagnostica(motivoIntervento(today()->addDays(45)->toDateString()))->motivi)->toHaveCount(1);
});

// --- Invarianti strutturali (ADR-024) ---

it('ties the stato to the motivi structurally: arancione if and only if there is a motivo', function () {
    $date = [
        today()->subYears(3), today()->subDay(), today(),
        today()->addDays(Semaforo::giorniImminente()), today()->addDays(Semaforo::giorniImminente() + 1),
        today()->addYears(2),
    ];

    foreach ($date as $data) {
        $diagnosi = Semaforo::diagnostica(motivoIntervento($data->toDateString()));

        expect($diagnosi->stato === StatoSemaforo::Arancione)->toBe($diagnosi->motivi !== [],
            "Stato e motivi disallineati per {$data->toDateString()}");
    }
});

it('never produces rosso: that stays a manual forzatura', function () {
    // Anche con dieci motivi scadutissimi il motore non dichiara "non idoneo".
    $motivi = array_map(fn (int $i) => motivoIntervento(today()->subYears($i)->toDateString()), range(1, 10));

    expect(Semaforo::diagnostica(...$motivi)->stato)->toBe(StatoSemaforo::Arancione)
        ->and((new DiagnosiSemaforo([]))->stato)->not->toBe(StatoSemaforo::Rosso);
});

// --- calcola() resta un involucro sopra la diagnosi ---

it('keeps calcola coherent with diagnostica over a grid of dates', function () {
    $date = [
        null, today()->subYear(), today()->subDay(), today(),
        today()->addDays(Semaforo::giorniImminente()), today()->addDays(Semaforo::giorniImminente() + 1),
    ];

    foreach ($date as $intervento) {
        foreach ($date as $garanzia) {
            $candidati = [];
            if ($intervento !== null) {
                $candidati[] = MotivoSemaforo::anonimo(TipoMotivoSemaforo::Intervento, $intervento);
            }
            if ($garanzia !== null) {
                $candidati[] = MotivoSemaforo::anonimo(TipoMotivoSemaforo::GaranziaMacchina, $garanzia);
            }

            expect(Semaforo::calcola($intervento, $garanzia))
                ->toBe(Semaforo::diagnostica(...$candidati)->stato);
        }
    }
});

// --- `scaduto` viene dalle regole uniche del model, non ricalcolato ---

it('takes scaduto from Intervento::isScaduto, today included as not overdue', function () {
    expect(motivoIntervento(today()->subDay()->toDateString())->scaduto)->toBeTrue()
        ->and(motivoIntervento(today()->toDateString())->scaduto)->toBeFalse()
        ->and(motivoIntervento(today()->addDay()->toDateString())->scaduto)->toBeFalse()
        // Un intervento già fatto non è scaduto, qualunque sia la data.
        ->and(motivoIntervento(today()->subYear()->toDateString(), StatoIntervento::Fatto)->scaduto)->toBeFalse();
});

it('takes scaduto from Garanzia::isScaduta', function () {
    expect(motivoGaranzia(today()->subDay()->toDateString())->scaduto)->toBeTrue()
        ->and(motivoGaranzia(today()->toDateString())->scaduto)->toBeFalse();
});

it('carries the origin row so the UI can link back to it', function () {
    $intervento = new Intervento(['stato' => StatoIntervento::NonFatto, 'data_scadenza' => today(), 'descrizione' => 'Taratura annuale']);
    $intervento->id = 42;

    $motivo = MotivoSemaforo::daIntervento($intervento);

    expect($motivo->tipo)->toBe(TipoMotivoSemaforo::Intervento)
        ->and($motivo->riferimentoId)->toBe(42)
        ->and($motivo->dettaglio)->toBe('Taratura annuale');
});

it('leaves an anonymous motivo without scaduto or riferimento', function () {
    // È il motivo che costruisce calcola() dalle date nude dell'elenco: senza il
    // model non si può sapere se è scaduto, e inventarlo sarebbe una copia della
    // regola. Non arriva mai alla UI.
    $motivo = MotivoSemaforo::anonimo(TipoMotivoSemaforo::Intervento, today());

    expect($motivo->scaduto)->toBeNull()
        ->and($motivo->riferimentoId)->toBeNull()
        ->and($motivo->dettaglio)->toBeNull();
});

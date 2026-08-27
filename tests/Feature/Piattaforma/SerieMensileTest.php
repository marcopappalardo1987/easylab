<?php

use App\Support\Piattaforma\SerieMensile;

/**
 * Il DTO delle serie mensili della cabina di regia (S6 — 🔗 ADR-034).
 *
 * Due domande sole, e sono le due che una vista non può porsi da sola.
 *
 * 🔴 **Quanto è cresciuta la serie nella finestra.** Su una serie cumulata la
 * risposta *non* è `ultimo() - primo()`: il primo punto è il totale alla FINE
 * del primo bucket mostrato, quindi quella differenza copre undici intervalli su
 * dodici e perde tutto ciò che è entrato nel mese di apertura. Il sintomo non è
 * un errore, è la cifra **plausibile e diversa** — «+0 in 12 mesi» sotto una
 * tile che dice «Clienti 3» — che questo progetto ha già imparato a temere.
 *
 * 🔴 **Se c'è qualcosa da raccontare.** `GeometriaGrafico` fa cadere la serie
 * piatta a metà altezza per non dividere per zero: dodici mesi a zero
 * disegnerebbero quindi la stessa sparkline di 5.000 strumenti fermi da un anno.
 * `eVuota()` è l'unica cosa che separa un'assenza da un dato, e vale solo se
 * qualcuno la chiama — `GraficiCabinaTest` verifica che la cabina lo faccia.
 *
 * ⚠️ Sta sotto `tests/Feature/` e non sotto `tests/Unit/` per il perimetro di
 * file di questa lavorazione, come `GeometriaGraficoTest`: non tocca il
 * database, e `RefreshDatabase` qui è solo un costo.
 */
function serieDa(array $valori, ?int $base = null): SerieMensile
{
    $mesi = [];
    $etichette = [];

    foreach (array_keys($valori) as $i) {
        $mesi[] = sprintf('2026-%02d', $i + 1);
        $etichette[] = 'M'.($i + 1);
    }

    return new SerieMensile($mesi, $etichette, array_values($valori), $base);
}

// ─── La variazione, che è il numero scritto a parole accanto alla sparkline ──

it('measures a cumulative series from its base and not from its first point', function () {
    // Tre clienti entrati nel PRIMO bucket della finestra e nessuno dopo: la
    // curva è piatta a 3, ma la crescita nella finestra è 3 e non 0.
    $serie = serieDa(array_fill(0, 12, 3), base: 0);

    expect($serie->variazione())->toBe(3)
        ->and($serie->variazioneConSegno())->toBe('+3');
});

it('does not count towards the change what was already there before the window', function () {
    // Chi c'era già non è cresciuto adesso: la correzione non è «`ultimo()` e
    // basta», che direbbe +12 su una piattaforma che ne ha presi due.
    $serie = serieDa([10, 10, 11, 12], base: 10);

    expect($serie->variazione())->toBe(2);
});

it('falls back to the visible span for a series that is not cumulative', function () {
    // I nuovi clienti per mese ripartono da zero a ogni bucket: una «base» lì
    // non vuol dire niente, e la lettura giusta resta la distanza fra il primo
    // e l'ultimo punto disegnato.
    expect(serieDa([2, 5, 9])->variazione())->toBe(7);
});

it('reports a negative change with a real minus sign and not a hyphen', function () {
    // `−` (U+2212) e non `-`: è il segno che il resto della pagina usa nei
    // numeri tabulari.
    expect(serieDa([9, 4], base: 9)->variazioneConSegno())->toBe('−5');
});

it('measures no change at all on a series with no points, base or not', function () {
    // Il caso che nessuno raggiunge dall'interfaccia, e che senza una guardia
    // esplicita darebbe `0 - base`, cioè un calo inventato.
    expect(serieDa([], base: 7)->variazione())->toBe(0)
        ->and(serieDa([])->variazione())->toBe(0);
});

// ─── L'assenza di dato, che non è un dato ────────────────────────────────────

it('calls a series of twelve zeros empty, because a flat line reads as a figure', function () {
    expect(serieDa(array_fill(0, 12, 0))->eVuota())->toBeTrue()
        ->and(serieDa([])->eVuota())->toBeTrue();
});

it('does not call empty a series that is flat but not at zero', function () {
    // 5.000 strumenti fermi da un anno sono un dato, e vanno disegnati: è
    // esattamente la serie che, senza questa distinzione, avrebbe la stessa
    // identica sparkline di una piattaforma appena installata.
    expect(serieDa(array_fill(0, 12, 5000))->eVuota())->toBeFalse()
        ->and(serieDa([0, 0, 1])->eVuota())->toBeFalse();
});

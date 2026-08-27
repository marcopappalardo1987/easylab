<?php

use App\Support\Piattaforma\GeometriaGrafico;

/**
 * La geometria dei grafici, che è dove stanno i **500 silenziosi**.
 *
 * In PHP 8 una divisione per zero non produce un `d="M NaN"`: lancia
 * `DivisionByZeroError`, cioè manda la cabina di regia in errore. E i casi che
 * ci arrivano non sono di laboratorio — sono **tutti** quelli del primo giorno:
 * nessun cliente, un cliente solo, dodici mesi tutti a zero, dodici mesi tutti
 * uguali. Il giorno in cui la pagina esplode così è il giorno in cui nessuno
 * sta guardando i log.
 *
 * L'altra metà del file guarda una cosa che non esplode affatto: **l'asse
 * invertito**. Un grafico col massimo in basso resta bello da guardare e
 * racconta il contrario di ciò che è successo — nessuno lo scopre leggendo il
 * codice, perché la formula sembra giusta in entrambi i versi.
 *
 * ⚠️ Vive sotto `tests/Feature/` e non sotto `tests/Unit/` per una ragione
 * amministrativa e non tecnica (il perimetro di file di questa lavorazione):
 * non tocca il database, e `RefreshDatabase` qui è solo un costo.
 */

// ─── Le divisioni per zero, una per una ──────────────────────────────────────

it('returns null for an empty series instead of an empty path', function () {
    // Il componente deve poter **non disegnare nulla**. Una linea piatta a zero
    // si leggerebbe come un dato; l'assenza di dato è un'altra cosa.
    expect(GeometriaGrafico::percorsoSparkline([]))->toBeNull();
});

it('does not divide by zero when every value in the series is identical', function () {
    // `max === min` è il caso reale di una piattaforma appena avviata: dodici
    // mesi a zero clienti. `($v - $min) / ($max - $min)` sarebbe `0/0`.
    $piatta = GeometriaGrafico::percorsoSparkline(array_fill(0, 12, 0));

    expect($piatta)->not->toBeNull()
        // Tutti i punti a metà altezza: 32 / 2.
        ->and($piatta['linea'])->toContain('16.0')
        ->and($piatta['ultimoY'])->toBe(16.0);

    // E lo stesso con dodici mesi tutti uguali ma diversi da zero.
    $costante = GeometriaGrafico::percorsoSparkline(array_fill(0, 12, 7));

    expect($costante['ultimoY'])->toBe(16.0);
});

it('draws a single point as a flat line, not as a broken path', function () {
    // Un `d` con un solo `M` e nessun `L` non disegna niente e non dà errore.
    $uno = GeometriaGrafico::percorsoSparkline([5]);

    expect($uno['linea'])->toContain(' L ')
        // Da bordo a bordo: 0 → 120.
        ->and($uno['linea'])->toStartWith('M 0.0 ')
        ->and($uno['ultimoX'])->toBe(120.0);
});

it('does not divide by zero when the bar chart has nothing but zeros', function () {
    $barre = GeometriaGrafico::barre(array_fill(0, 12, 0));

    expect($barre)->toHaveCount(12)
        // Altezza zero: la vista non disegna il rettangolo, perché una barra di
        // un pixel si legge come un dato minuscolo invece che come un'assenza.
        ->and(collect($barre)->pluck('altezza')->unique()->all())->toBe([0.0]);
});

it('gives back no bars at all for an empty series', function () {
    expect(GeometriaGrafico::barre([]))->toBe([]);
});

it('does not divide by zero when the composition bar has nothing in it', function () {
    expect(GeometriaGrafico::segmenti([0, 0, 0]))->toBe([0.0, 0.0, 0.0]);
});

// ─── L'asse, che è l'errore che resta bello ──────────────────────────────────

it('puts the maximum at the top and the minimum at the bottom', function () {
    // ⚠️ L'asse Y dell'SVG cresce verso il BASSO: il massimo sta in alto solo
    // perché la normalizzazione è **sottratta**. Invertire quel segno dà un
    // grafico specchiato che racconta il contrario, e che nessuno vede.
    $p = GeometriaGrafico::percorsoSparkline([0, 10]);

    // Il primo punto è il minimo, l'ultimo è il massimo: quindi la y dell'ultimo
    // dev'essere PIÙ PICCOLA di quella del primo.
    preg_match('/^M ([\d.]+) ([\d.]+) L ([\d.]+) ([\d.]+)$/', $p['linea'], $m);

    expect($m)->not->toBeEmpty()
        ->and((float) $m[4])->toBeLessThan((float) $m[2])
        ->and($p['ultimoY'])->toBe(3.0);   // il margine alto
});

it('puts the tallest bar highest, which is the same mistake on the other chart', function () {
    $barre = GeometriaGrafico::barre([1, 10]);

    // Più alto = `y` più piccola, perché `y` è il bordo SUPERIORE del rettangolo.
    expect($barre[1]['y'])->toBeLessThan($barre[0]['y'])
        ->and($barre[1]['altezza'])->toBeGreaterThan($barre[0]['altezza']);
});

// ─── La barra di composizione, che deve chiudere ─────────────────────────────

it('keeps the stacked segments at exactly 100% when the percentages do not divide evenly', function () {
    // Tre clienti su tre piani danno 33,33% tre volte: 99,99, e la barra resta
    // aperta in coda di un capello — che si legge come una quarta categoria
    // senza nome. L'ultima voce non nulla assorbe il resto.
    $s = GeometriaGrafico::segmenti([1, 1, 1]);

    // ⚠️ **Con una tolleranza, non con `toBe(100.0)`.** L'identità stretta fra
    // float passa su tre voci per fortuna aritmetica e diventerebbe rossa su
    // codice **corretto** appena il numero di voci cambia: sette voci da 1 danno
    // 99.99999999999999 (test qui sotto), mentre le percentuali stampate in
    // decimale sommano a 100,00 esatti e la barra chiude davvero. La tolleranza
    // è mille volte più stretta della fessura da 0,01 che sorveglia.
    expect(array_sum($s))->toEqualWithDelta(100.0, 0.000001)
        ->and($s[0])->toBe(33.33)
        ->and($s[2])->toBe(33.34);
});

it('keeps the stacked segments at 100% for a number of slices that no float sums cleanly', function () {
    // Sette voci da 1: 14,29 sei volte più 14,26. È il caso che smaschera
    // l'identità stretta fra float, ed è la prova che l'invariante non dipende
    // dal numero di piani a catalogo — che è destinato a cambiare.
    $s = GeometriaGrafico::segmenti(array_fill(0, 7, 1));

    expect($s)->toHaveCount(7)
        ->and(array_sum($s))->toEqualWithDelta(100.0, 0.000001)
        // E in decimale la barra chiude **esatta**: è il numero che il browser
        // usa per le larghezze, non la somma binaria.
        ->and(number_format(array_sum($s), 2, '.', ''))->toBe('100.00');
});

it('gives the leftover to the last non-empty slice, not to an empty one', function () {
    // Darlo a una voce a zero la farebbe comparire nella barra senza comparire
    // nella legenda: un colore senza nome, che è il difetto che ADR-034 vieta.
    $s = GeometriaGrafico::segmenti([1, 1, 1, 0]);

    expect(array_sum($s))->toEqualWithDelta(100.0, 0.000001)
        ->and($s[3])->toBe(0.0)
        ->and($s[2])->toBe(33.34);
});

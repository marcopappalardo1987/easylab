<?php

use App\Support\Guide\Manuale as Libreria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ⛔ Il ramo con la CACHE, che in locale e nei test non viene mai eseguito.
 *
 * `Manuale::tutte()` salta la cache in `local` e in `testing` — apposta, perché
 * un test che sposta `config('guide.guide')` leggerebbe altrimenti l'elenco
 * messo in cache dal test precedente. Il prezzo è che l'unico ramo che gira in
 * produzione non era coperto da niente, e infatti:
 *
 * 🔴 **Il 6 Set 2026 staging è andato in 500 appena deployato**, con 2470 test
 * verdi in locale. `config/cache.php` porta `'serializable_classes' => false`
 * — il framework si rifiuta di deserializzare qualunque classe letta dalla
 * cache, difesa dai gadget chain se `APP_KEY` trapela — e la prima versione ci
 * metteva dentro una `Collection`: tornava `__PHP_Incomplete_Class`, e il tipo
 * di ritorno esplodeva in TypeError.
 *
 * Questo file finge l'ambiente e usa uno store che serializza davvero, così il
 * ramo viene percorso per intero invece che descritto.
 */
beforeEach(function () {
    Libreria::dimentica();
    Cache::store('file')->clear();
});

afterEach(function () {
    Cache::store('file')->clear();
});

it('survives a real cache round trip outside local and testing', function () {
    // `file` invece di `array`: lo store in memoria non serializza nulla, quindi
    // resterebbe verde qualunque cosa ci si mettesse dentro.
    config()->set('cache.default', 'file');
    app()->detectEnvironment(fn () => 'staging');

    $primo = Libreria::tutte();   // scrive in cache
    $secondo = Libreria::tutte(); // rilegge dalla cache

    expect($secondo)->toBeInstanceOf(Collection::class);
    expect($secondo->all())->toEqual($primo->all());
    expect($secondo->pluck('slug')->all())->toEqual($primo->pluck('slug')->all());
});

it('puts nothing but arrays and scalars into the cache', function () {
    // La stessa regola detta sul dato invece che sul comportamento: qualunque
    // oggetto qui dentro tornerebbe incompleto, a qualunque profondità.
    $verifica = function (mixed $valore, string $dove) use (&$verifica) {
        expect(is_object($valore))->toBeFalse("Oggetto in cache in «{$dove}»: tornerà __PHP_Incomplete_Class.");

        if (is_array($valore)) {
            foreach ($valore as $chiave => $figlio) {
                $verifica($figlio, "{$dove}.{$chiave}");
            }
        }
    };

    $verifica(Libreria::tutte()->all(), 'guide');
});

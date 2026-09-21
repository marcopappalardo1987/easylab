<?php

use Illuminate\Support\Facades\Route;

/**
 * 🔴 Le pagine d'errore non raccontano l'interno (S7, T1c · OWASP A05).
 *
 * Con `APP_DEBUG=false` un'eccezione deve dare una pagina muta: niente
 * messaggio, niente stack, niente percorsi, niente valori di configurazione.
 * Il messaggio dell'eccezione qui porta apposta un "segreto": è ciò che una
 * query fallita metterebbe davvero in pagina (SQL, nomi di colonna, valori).
 */
beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/_t1c/esplode', function () {
        throw new RuntimeException('SQLSTATE[42P01] segreto-t1c password=hunter2');
    });
});

/*
 * I tre test sotto forzano `app.debug = false` e provano che cosa fa Laravel
 * in quel caso. Non dicono se l'app GIRA con debug spento: lo dicono i due
 * guardiani qui, che leggono la config vera e i file d'ambiente di deploy.
 */
it('turns debug off when APP_DEBUG is missing from the environment', function () {
    $salvati = [$_ENV['APP_DEBUG'] ?? null, $_SERVER['APP_DEBUG'] ?? null, getenv('APP_DEBUG')];
    unset($_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG']);
    putenv('APP_DEBUG');

    try {
        $config = require config_path('app.php');
    } finally {
        [$env, $server, $put] = $salvati;
        if ($env !== null) {
            $_ENV['APP_DEBUG'] = $env;
        }
        if ($server !== null) {
            $_SERVER['APP_DEBUG'] = $server;
        }
        if ($put !== false) {
            putenv("APP_DEBUG={$put}");
        }
    }

    expect($config['debug'])->toBeFalse();
});

it('shows a mute 500 page with debug off', function () {
    $pagina = $this->get('/_t1c/esplode')->assertStatus(500)->getContent();

    expect($pagina)->not->toContain('segreto-t1c')
        ->and($pagina)->not->toContain('hunter2')
        ->and($pagina)->not->toContain('SQLSTATE')
        ->and($pagina)->not->toContain(base_path())
        ->and($pagina)->not->toContain('RuntimeException')
        ->and($pagina)->not->toContain((string) config('app.key'))
        // Positivo: senza, le negazioni sarebbero vere anche su una risposta vuota.
        ->and($pagina)->toContain('500');
});

it('gives a JSON client the same mute page, not a trace', function () {
    // Fuori da `api/*` (bootstrap/app.php) si rende l'HTML anche a chi chiede
    // JSON: è il ramo in cui Laravel, con debug acceso, metterebbe `trace`.
    $corpo = $this->getJson('/_t1c/esplode')->assertStatus(500)->getContent();

    expect($corpo)->not->toContain('segreto-t1c')
        ->and($corpo)->not->toContain('"trace"')
        ->and($corpo)->not->toContain(base_path())
        ->and($corpo)->toContain('Server Error');
});

it('does not expose the stack of a 404 either', function () {
    $pagina = $this->get('/_t1c/non-esiste')->assertNotFound()->getContent();

    expect($pagina)->not->toContain(base_path())
        ->and($pagina)->not->toContain('NotFoundHttpException')
        ->and($pagina)->toContain('404');
});

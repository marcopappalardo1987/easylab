<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Isolamento\Support\Matrice;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * Inventario GENERATO dell'isolamento multi-tenant (ADR-001): modelli con
 * BelongsToTenant, rotte con parametro, componenti Livewire e classi in coda
 * si ricavano dal codice, e ciascuno deve avere la sua riga in Matrice. Un
 * modello, una rotta, un componente o un job nuovi rendono rosso questo file
 * finché qualcuno non scrive il test negativo (o il motivo per cui non serve).
 */

/** Ogni cella `t:` punta a un test che esiste con quella descrizione esatta. */
function cellaValida(string $cella): ?string
{
    if (str_starts_with($cella, 'na:')) {
        return strlen(trim(substr($cella, 3))) >= 15 ? null : "motivo troppo corto: {$cella}";
    }
    if (! str_starts_with($cella, 't:') || ! str_contains($cella, '::')) {
        return "cella malformata: {$cella}";
    }

    [$file, $descrizione] = explode('::', substr($cella, 2), 2);
    $percorso = base_path('tests/Feature/'.$file);
    if (! is_file($percorso)) {
        return "file di test inesistente: {$file}";
    }

    return str_contains((string) file_get_contents($percorso), "it('".str_replace("'", "\\'", $descrizione)."'")
        ? null
        : "test inesistente in {$file}: {$descrizione}";
}

it('covers every model with BelongsToTenant, and only those', function () {
    expect(Matrice::modelliTenant())->not->toBeEmpty()
        ->and(array_keys(Matrice::celle()))->toEqualCanonicalizing(Matrice::modelliTenant());
});

it('fills every surface of every model with a test or a reason', function () {
    $difetti = [];
    foreach (Matrice::celle() as $modello => $celle) {
        $mancanti = array_diff(Matrice::SUPERFICI, array_keys($celle));
        $extra = array_diff(array_keys($celle), Matrice::SUPERFICI);
        foreach ([...$mancanti, ...$extra] as $superficie) {
            $difetti[] = "{$modello}: superficie {$superficie} mancante o sconosciuta";
        }
        foreach ($celle as $superficie => $cella) {
            if (($errore = cellaValida($cella)) !== null) {
                $difetti[] = "{$modello}.{$superficie}: {$errore}";
            }
        }
    }

    expect($difetti)->toBe([]);
});

it('has a row in the fixture world for every model with BelongsToTenant', function () {
    // Il mondo di prova crea una riga per Ente di ogni modello: senza, la
    // batteria di VettoriIsolationTest non avrebbe niente da attaccare.
    $creati = collect((new ReflectionMethod(MondoDueEnti::class, 'ente'))->getFileName())
        ->map(fn ($f) => (string) file_get_contents($f))->first();

    foreach (Matrice::modelliTenant() as $modello) {
        expect($creati)->toContain(class_basename($modello).'::');
    }
});

it('covers every app route with a path parameter', function () {
    $rotte = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with(ltrim((string) ($r->getAction('uses') instanceof Closure ? '' : $r->getActionName()), '\\'), 'App\\'))
        ->filter(fn ($r) => $r->parameterNames() !== [])
        ->map(fn ($r) => $r->uri())
        ->unique()->sort()->values()->all();

    expect($rotte)->not->toBeEmpty()
        ->and($rotte)->toEqualCanonicalizing(array_keys(Matrice::rotteConParametro()));

    $difetti = collect(Matrice::rotteConParametro())->map(fn ($c, $uri) => cellaValida($c) === null ? null : "{$uri}: ".cellaValida($c))->filter()->values()->all();
    expect($difetti)->toBe([]);
});

it('covers every Livewire component', function () {
    $componenti = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Livewire'))))
        ->filter(fn ($f) => $f->isFile() && str_ends_with($f->getFilename(), '.php'))
        ->map(fn ($f) => substr(str_replace(app_path('Livewire').'/', '', $f->getPathname()), 0, -4))
        ->reject(fn (string $c) => str_contains($c, 'Concerns/'))
        ->sort()->values()->all();

    expect($componenti)->not->toBeEmpty()
        ->and($componenti)->toEqualCanonicalizing(array_keys(Matrice::componenti()));

    $difetti = collect(Matrice::componenti())->map(fn ($c, $k) => cellaValida($c) === null ? null : "{$k}: ".cellaValida($c))->filter()->values()->all();
    expect($difetti)->toBe([]);
});

it('covers every queued class of the app', function () {
    $inCoda = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
        ->filter(fn ($f) => $f->isFile() && str_ends_with($f->getFilename(), '.php'))
        ->map(fn ($f) => 'App\\'.str_replace(['/', '.php'], ['\\', ''], str_replace(app_path().'/', '', $f->getPathname())))
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, ShouldQueue::class))
        ->sort()->values()->all();

    expect($inCoda)->not->toBeEmpty()
        ->and($inCoda)->toEqualCanonicalizing(array_keys(Matrice::code()));

    $difetti = collect(Matrice::code())->map(fn ($c, $k) => cellaValida($c) === null ? null : "{$k}: ".cellaValida($c))->filter()->values()->all();
    expect($difetti)->toBe([]);
});

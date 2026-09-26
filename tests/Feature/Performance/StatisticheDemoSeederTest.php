<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Il seeder dimostrativo non può diventare quadratico su Postgres.
 *
 * T7 di S7: un worker della suite su Postgres è rimasto fermo 15 ore su una
 * count di `verificaInvarianti()`. Causa: dopo il rollback di un test
 * l'autovacuum lascia le tabelle a `reltuples = 0` con `relpages > 0`, il
 * planner stima una riga per le decine di migliaia inserite a blocchi e sceglie
 * nested loop su scansioni complete. Rimedio: `DemoSeeder::aggiornaStatistiche()`
 * lancia `ANALYZE` dopo i blocchi.
 *
 * ⚠️ **La prima versione di questo test riproduceva quello stato scrivendo in
 * `pg_class`, e in CI è andata in DEADLOCK con l'autovacuum** (run del 26 Set,
 * 3053 verdi e questo rosso). Scrivere nei cataloghi di sistema per provare una
 * cosa nostra è un prezzo troppo alto: qui si guarda invece ciò che il seeder
 * fa davvero, cioè le `ANALYZE` che emette, catturate dal log delle query. È
 * deterministico, non tocca il catalogo e non dipende dall'autovacuum.
 *
 * Su SQLite non c'è nessun planner da rinfrescare, quindi il caso si salta.
 */
it('runs ANALYZE on the bulk-loaded tables, so the planner never plans them as empty', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $analyze = [];
    DB::listen(function ($query) use (&$analyze) {
        if (str_starts_with(mb_strtolower(ltrim($query->sql)), 'analyze')) {
            $analyze[] = mb_strtolower($query->sql);
        }
    });

    $this->seed(DemoSeeder::class);

    $tutte = implode(' ', $analyze);

    // Le tabelle che il seme riempie a blocchi, cioè quelle che senza ANALYZE
    // il planner crede vuote. `ricambio_utilizzo` è elencata a parte perché si
    // popola dopo la prima ANALYZE: se sparisse quella seconda chiamata, il
    // join dei ricambi tornerebbe quadratico da solo.
    foreach (['strumenti', 'interventi', 'garanzie', 'ricambio_utilizzo'] as $tabella) {
        expect($tutte)->toContain($tabella);
    }

    // Almeno due chiamate: quella per sede e quella dei ricambi. Il conteggio
    // lato server (`pg_stat_user_tables.last_analyze`) qui NON si usa: lo
    // aggiorna un raccoglitore asincrono, cioè la stessa incostanza che questo
    // test esiste per togliere.
    expect(count($analyze))->toBeGreaterThanOrEqual(2);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'Le statistiche del planner esistono solo su Postgres.');

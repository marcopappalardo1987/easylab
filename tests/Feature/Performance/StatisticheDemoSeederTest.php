<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Il seeder dimostrativo non può diventare quadratico su Postgres.
 *
 * T7 di S7: un worker della suite su Postgres fermo 15 ore su una count di
 * `verificaInvarianti()`. Causa: dopo il rollback di un test l'autovacuum lascia
 * le tabelle a `reltuples = 0` con `relpages > 0`, il planner stima 1 riga per
 * le decine di migliaia inserite a blocchi e sceglie nested loop su scansioni
 * complete. Qui quello stato si riproduce a mano (l'autovacuum non è
 * deterministico) e si controlla che `DemoSeeder::aggiornaStatistiche()` lo
 * rimetta in ordine. Su SQLite non c'è planner da ingannare.
 */
it('refreshes the planner statistics after its bulk inserts, so the invariant joins stay hash joins', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Lo stato che l'autovacuum lascia dopo un rollback: tabella "vuota" per il
    // planner, ma con pagine. Transazionale: il rollback del test lo annulla.
    DB::statement("update pg_class set reltuples = 0, relpages = 500 where relkind = 'r' and relname in ('strumenti', 'interventi', 'spostamenti_strumento', 'garanzie', 'ricambio_utilizzo')");
    DB::statement("set local statement_timeout = '60s'");

    $this->seed(DemoSeeder::class);

    $piano = fn (string $sql): string => collect(DB::select('explain '.$sql))->pluck('QUERY PLAN')->implode("\n");
    $stima = fn (string $tabella): int => (int) preg_replace('/.*rows=(\d+).*/s', '$1', $piano("select * from {$tabella}"));

    foreach (['strumenti', 'interventi', 'ricambio_utilizzo'] as $tabella) {
        $vere = DB::table($tabella)->count();
        expect($vere)->toBeGreaterThan(100);
        expect($stima($tabella))->toBeGreaterThan(intdiv($vere, 2));
    }

    expect($piano('select count(*) from interventi inner join strumenti on strumenti.id = interventi.strumento_id where interventi.tenant_id != strumenti.tenant_id'))
        ->not->toContain('Nested Loop');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'Il planner da ingannare esiste solo su Postgres.');

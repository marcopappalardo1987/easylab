<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indice PARZIALE su `strumenti.forced_state` (`where forced_state is not null`),
 * la mossa che la migration del 2 Ago 2026 lasciava indicata (ADR-005; ERD
 * §5.1). ⚠️ Decisione aperta: la migration è pronta, se tenerla lo decide Marco
 * — e va decisa PRIMA del push: la migration è già in `database/migrations`,
 * quindi il primo deploy la applica (T4B-3).
 *
 * La colonna è quasi sempre NULL (16 forzati su 5.105 sul DB di sviluppo),
 * quindi un indice intero sarebbe fatto di NULL; quello parziale contiene le
 * sole righe forzate ed è minuscolo.
 *
 * Misura (EXPLAIN ANALYZE su `easylab_test`, 20.000 strumenti di cui 387
 * forzati, dentro una transazione annullata):
 *
 * - conteggio dei rossi della dashboard (`conStato(Rosso)`, Ente da 8.000):
 *   0,65 ms → 0,10 ms, BitmapOr fra questo indice e le sottoquery;
 * - conteggio dei rossi del Parco (tutti gli Enti): 1,62 ms → 0,04 ms.
 *
 * Non cambia verde/arancione: il loro ramo è `forced_state is null`, cioè la
 * quasi totalità delle righe. Il guadagno è reale ma sotto il millisecondo a
 * questo volume.
 *
 * ⚠️ `create index` SENZA `concurrently`: lock SHARE su `strumenti` per la
 * durata della costruzione, millisecondi a questo volume (e l'indice legge le
 * sole righe forzate). `concurrently` non gira nella transazione in cui Laravel
 * avvolge le migration su Postgres.
 *
 * ## Su SQLite si salta, e lo si dichiara
 *
 * SQLite saprebbe creare un indice parziale, ma la suite locale non misura
 * piani d'esecuzione e lo schema di riferimento per il planner è Postgres (CI e
 * produzione): tenerlo solo lì evita un DDL diverso fra i due driver senza
 * niente da guadagnare in locale.
 */
return new class extends Migration
{
    private const NOME = 'strumenti_forced_state_parziale_index';

    public function up(): void
    {
        if ($this->nonSupportato()) {
            return;
        }

        DB::statement('create index '.self::NOME.' on strumenti (forced_state) where forced_state is not null');
    }

    public function down(): void
    {
        if ($this->nonSupportato()) {
            return;
        }

        DB::statement('drop index if exists '.self::NOME);
    }

    private function nonSupportato(): bool
    {
        return Schema::getConnection()->getDriverName() !== 'pgsql';
    }
};

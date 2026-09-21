<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indici per l'elenco strumenti (S7, passo performance; ERD §5.1 `strumenti`,
 * confine Ente di ADR-006).
 *
 * Ricavati dal codice, non a memoria: `ElencoStrumenti` ordina per una colonna
 * di `strumenti` più il tie-break `strumenti.id`, dentro il `tenant_id` che il
 * TenantScope mette su ogni query. Il default è `nome`.
 *
 * Misura (EXPLAIN ANALYZE su `easylab_test`, 20.000 strumenti in 25 Enti, il
 * più grande con 8.000; prima pagina da 25, dentro una transazione annullata):
 *
 * - ordinamento per `nome` (default): 5,9 ms con top-N heapsort su tutto
 *   l'Ente → 0,01 ms, Index Scan su `(tenant_id, nome, id)` che si ferma a 25;
 * - ordinamento per `data_installazione` desc: 1,1 ms → 0,01 ms su
 *   `(tenant_id, data_installazione)`, con Incremental Sort per l'id.
 *
 * L'`id` in coda al primo indice è il tie-break dell'ORDER BY: senza, Postgres
 * dovrebbe riordinare i pari dopo la scansione. Il secondo non lo porta perché
 * lì i pari sono pochi e l'Incremental Sort li chiude in memoria.
 *
 * Non aiuta, misurato, il conteggio degli obsoleti della dashboard: la soglia
 * prende la maggioranza dell'Ente e il planner resta sull'indice `tenant_id`.
 *
 * In discesa (`nome desc`) il tie-break resta `id asc`: Postgres scorre
 * l'indice all'indietro e chiude i pari con un Incremental Sort sull'id (0,03 ms,
 * misurato). Non serve un indice apposta.
 *
 * ⚠️ `create index` SENZA `concurrently`: prende un lock SHARE su `strumenti`
 * (letture libere, scritture in attesa) per la durata della costruzione —
 * millisecondi ai volumi di oggi (~5.000 righe sul DB di sviluppo). Con
 * `concurrently` non potrebbe girare nella transazione in cui Laravel avvolge
 * le migration su Postgres. Da rivedere solo con un parco di ordini di
 * grandezza più grande.
 *
 * Additiva: solo `create index`, `down()` li toglie e basta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->index(['tenant_id', 'nome', 'id'], 'strumenti_tenant_nome_id_index');
            $table->index(['tenant_id', 'data_installazione'], 'strumenti_tenant_data_installazione_index');
        });
    }

    public function down(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->dropIndex('strumenti_tenant_nome_id_index');
            $table->dropIndex('strumenti_tenant_data_installazione_index');
        });
    }
};

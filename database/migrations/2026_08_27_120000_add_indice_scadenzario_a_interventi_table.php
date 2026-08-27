<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indice per lo Scadenzario aggregato (ERD §5.2 — ADR-005).
 *
 * La tabella ha già `(strumento_id, stato, data_scadenza)`, che serve alle
 * query della **scheda** e del semaforo: quelle nominano sempre lo strumento,
 * quindi il prefisso sinistro è utile. Lo Scadenzario invece NON nomina lo
 * strumento — è una vista cross-macchina: filtra `tenant_id` + `stato` e ordina
 * per `data_scadenza`. Su quell'indice il prefisso sinistro non è selettivo, e
 * il piano ricade su una scansione con sort.
 *
 * ⛔ **L'indice singolo su `tenant_id` NON viene rimosso**, benché sia il
 * prefisso sinistro di questo e quindi in parte ridondante: toglierlo sarebbe
 * una migration distruttiva (area rossa della Policy di Code Review) su una
 * colonna con altri consumatori — ogni query scopata da `TenantScope` che non
 * nomini né `stato` né `data_scadenza`. La ridondanza parziale è dichiarata qui
 * e si ferma qui.
 *
 * Additiva e reversibile: `down()` toglie solo l'indice aggiunto da `up()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventi', function (Blueprint $table) {
            $table->index(['tenant_id', 'stato', 'data_scadenza']);
        });
    }

    public function down(): void
    {
        Schema::table('interventi', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'stato', 'data_scadenza']);
        });
    }
};

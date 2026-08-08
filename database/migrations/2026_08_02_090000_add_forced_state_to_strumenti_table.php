<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Override manuale del semaforo (ERD §5.1 — ADR-005, S3 punto 5), già
     * annunciato dal commento di `create_strumenti_table`. Nomi in inglese:
     * eccezione consapevole rispetto alle altre colonne, ratificata dall'ERD.
     *
     * Tutte nullable: NULL = nessuna forzatura, lo stato mostrato torna al
     * calcolato. Se valorizzato, `forced_state` VINCE sul calcolato.
     *
     * Nessun indice su `forced_state`: l'ERD non lo dichiara, la colonna è
     * quasi sempre NULL (cardinalità inutile) e le query dell'elenco sono già
     * ristrette da `tenant_id` e paginate. Se servirà con la dashboard S6
     * (aggregati cross-tenant), la mossa giusta è un indice PARZIALE
     * (`where forced_state is not null`) in una migration dedicata.
     */
    public function up(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->string('forced_state')->nullable();             // verde|arancione|rosso
            $table->unsignedBigInteger('forced_by')->nullable();
            $table->dateTime('forced_at')->nullable();
            $table->string('forced_reason')->nullable();            // obbligatorio solo per il rosso (S3)

            $table->foreign('forced_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->dropForeign(['forced_by']);
            $table->dropColumn(['forced_state', 'forced_by', 'forced_at', 'forced_reason']);
        });
    }
};

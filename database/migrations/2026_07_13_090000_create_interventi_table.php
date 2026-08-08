<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attività/interventi (ERD §5.2 — ADR-005/007/009): la fonte di verità del
     * semaforo. `data_scadenza` può essere passata (storico) o futura
     * (pianificata); `tecnico_id` è l'assegnatario, da cui S4 deriverà il grant
     * puntuale del Tecnico sullo strumento (ADR-007). Le tarature sono
     * interventi `tipo = taratura` (ADR-009): nessun motore di scadenze a parte.
     *
     * Le colonne del semaforo forzato (forced_state/by/at/reason) vivono su
     * `strumenti` e arrivano al punto 5, non qui.
     */
    public function up(): void
    {
        Schema::create('interventi', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();               // confine Ente
            $table->unsignedBigInteger('reseller_id')->nullable();          // NULL in V1 (ADR-002)
            $table->unsignedBigInteger('strumento_id');
            $table->unsignedBigInteger('tecnico_id')->nullable()->index();  // assegnatario (ADR-007)
            $table->text('descrizione');
            $table->string('tipo');                                         // manutenzione|taratura|ispezione|riparazione|altro
            $table->date('data_scadenza')->index();                         // passata (storico) o futura (pianificata)
            $table->string('stato')->default('non_fatto');                  // non_fatto|fatto
            $table->date('data_esecuzione')->nullable();                    // valorizzata sse stato = fatto
            $table->timestamps();
            $table->softDeletes();

            // Query del semaforo (ADR-005): "interventi scaduti-non-fatti di questo
            // strumento". Copre anche il solo `strumento_id` (prefisso sinistro),
            // per cui l'indice singolo dell'ERD sarebbe ridondante; quello su
            // `stato` (cardinalità 2) non sarebbe selettivo.
            $table->index(['strumento_id', 'stato', 'data_scadenza']);

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('strumento_id')->references('id')->on('strumenti');
            $table->foreign('tecnico_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventi');
    }
};

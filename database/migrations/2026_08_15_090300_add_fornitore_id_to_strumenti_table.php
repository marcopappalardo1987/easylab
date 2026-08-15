<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il fornitore da cui la macchina è stata acquistata (ERD §5.1 — ADR-023).
     *
     * **Obbligatorio nel form, NULLABLE in schema**, ed è la divergenza che il
     * prossimo lettore scambierebbe per una dimenticanza — per questo sta
     * scritta qui e non solo nell'ADR: gli strumenti già a sistema non hanno un
     * fornitore, e gli import CSV in onboarding nemmeno. Una FK NOT NULL li
     * renderebbe non salvabili, cioè romperebbe proprio il caso d'uso per cui
     * l'import esiste. L'obbligo vive nella validazione del form, dove riguarda
     * chi inserisce a mano.
     *
     * Nessun `onDelete`, come tutte le FK di dominio del progetto: a rendere
     * praticabile la cancellazione è il soft delete di `fornitori`, non una
     * cascata che porterebbe via le macchine insieme al fornitore.
     */
    public function up(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->unsignedBigInteger('fornitore_id')->nullable()->index();
            $table->foreign('fornitore_id')->references('id')->on('fornitori');
        });
    }

    public function down(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->dropForeign(['fornitore_id']);
            $table->dropColumn('fornitore_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anagrafica fornitori per Ente (ERD §7.3 — ADR-023).
     *
     * ⚠️ **Il pivot `fornitore_strumento` della prima stesura dell'ERD NON si
     * crea**: ADR-023 ha corretto la relazione in 1-N («ogni macchinario è
     * associato al fornitore da cui è stato acquistato»), e un pivot N-N
     * permetterebbe di rappresentare una realtà che il dominio non ha.
     *
     * Contatti come colonne separate e non `json`: sono validabili (`email`,
     * `max`), ricercabili e leggibili in una query di supporto. Il `json` di
     * `parametri_tecnici` esiste perché quella è una scheda tecnica APERTA, con
     * chiavi ignote a priori; qui le chiavi sono tre e si sanno.
     */
    public function up(): void
    {
        Schema::create('fornitori', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();      // confine Ente
            $table->unsignedBigInteger('reseller_id')->nullable(); // NULL in V1 (ADR-002)

            $table->string('ragione_sociale');
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Ricerca e select del form partono sempre dal nome dentro un Ente
            // (ERD §7.3). Nessun unique: due sedi con la stessa ragione sociale
            // esistono, e un vincolo le renderebbe non inseribili — i doppioni
            // si segnalano nel form, non si vietano in schema.
            $table->index(['tenant_id', 'ragione_sociale']);

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornitori');
    }
};

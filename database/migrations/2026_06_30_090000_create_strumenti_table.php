<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asset/strumento (ERD §5.1). Qui solo le colonne anagrafica; il semaforo
     * (forced_state/by/at/reason — S3, ADR-005), l'obsolescenza (S3, ADR-014)
     * e il qr_token (S4, ADR-003) si aggiungono nei rispettivi sprint con
     * migration non distruttive.
     */
    public function up(): void
    {
        Schema::create('strumenti', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();           // confine Ente
            $table->unsignedBigInteger('reseller_id')->nullable();      // NULL in V1 (ADR-002)
            $table->unsignedBigInteger('unita_organizzativa_id');       // ubicazione corrente
            $table->string('nome');
            $table->string('modello')->nullable();
            $table->string('matricola')->nullable();                    // seriale costruttore
            $table->json('parametri_tecnici')->nullable();              // scheda tecnica flessibile
            $table->date('data_installazione')->nullable();             // base obsolescenza (S3)
            $table->timestamps();
            $table->softDeletes();

            $table->index('unita_organizzativa_id');

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('unita_organizzativa_id')->references('id')->on('unita_organizzativa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strumenti');
    }
};

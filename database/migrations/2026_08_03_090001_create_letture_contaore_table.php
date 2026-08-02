<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Letture contaore (ERD §5.3 — ADR-004): storico manuale delle ore, input
     * alternativo per le garanzie "a ore". Append-only come i movimenti
     * (`spostamenti_strumento`): niente soft delete, niente update — una
     * lettura sbagliata si corregge registrandone un'altra.
     *
     * V1: lo storico è solo registrato. L'estrapolazione automatica del ritmo
     * (≥2 letture → ore/giorno → data prevista) è V1.1.
     */
    public function up(): void
    {
        Schema::create('letture_contaore', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();       // confine Ente
            $table->unsignedBigInteger('reseller_id')->nullable();  // NULL in V1 (ADR-002)
            $table->unsignedBigInteger('strumento_id');
            $table->date('data');
            $table->integer('ore');                                 // lettura registrata
            $table->unsignedBigInteger('registrata_da')->nullable();
            $table->timestamps();                                   // append-only: NO softDeletes

            $table->index(['strumento_id', 'data']);                // storico per strumento

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('strumento_id')->references('id')->on('strumenti');
            $table->foreign('registrata_da')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letture_contaore');
    }
};

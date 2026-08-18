<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allegati a Strumento o Intervento (ERD §8.1 — ADR-009/025/026).
     *
     * ⚠️ **`strumento_id` denormalizzato, e non è ridondanza**: l'ERD §10 impone
     * al Responsabile Reparto la restrizione al sotto-albero anche sui
     * documenti, e sul solo morph quella restrizione **non è esprimibile** —
     * `DepartmentThroughStrumentoScope` filtra per una colonna, e qui la strada
     * verso lo strumento sarebbe due (diretta per i documenti della macchina,
     * via `interventi.strumento_id` per quelli dell'intervento). L'alternativa
     * era un secondo scope a due rami, cioè una seconda copia della subquery di
     * sicurezza che `AccessibleStrumenti` è nato per evitare.
     *
     * Va deciso ORA e non dopo: aggiungere una FK in ALTER ricostruisce la
     * tabella su SQLite, e una colonna NOT NULL non si aggiunge a una tabella
     * già popolata senza backfill.
     *
     * `path` porta `tenant_id` nel prefisso (ERD §8.1): non è sicurezza — quella
     * è la Policy — ma rende leggibile il bucket e circoscrivibile un ripristino.
     */
    public function up(): void
    {
        Schema::create('documenti', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('reseller_id')->nullable();

            $table->string('documentabile_type');
            $table->unsignedBigInteger('documentabile_id');

            // Sempre valorizzata: per un documento di strumento è lo strumento
            // stesso, per uno di intervento è lo strumento dell'intervento.
            $table->unsignedBigInteger('strumento_id')->index();

            $table->string('tipo');
            $table->string('nome');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('caricato_da')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['documentabile_type', 'documentabile_id']);

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('strumento_id')->references('id')->on('strumenti');
            $table->foreign('caricato_da')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documenti');
    }
};

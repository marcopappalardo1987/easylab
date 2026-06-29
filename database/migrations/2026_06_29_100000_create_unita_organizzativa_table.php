<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Albero organizzativo dinamico (ERD §4.1 — ADR-006/015).
     * Nodo radice `tipo = ente` = il tenant. Qui solo le colonne strutturali
     * necessarie a tenancy + sotto-albero; i campi fiscali/lockout/obsolescenza
     * del nodo ente arrivano nei rispettivi sprint (S3/S5) come migration
     * non distruttive.
     */
    public function up(): void
    {
        Schema::create('unita_organizzativa', function (Blueprint $table) {
            $table->id();
            // = id del nodo ente radice. Sul nodo ente coincide col proprio id,
            // popolato DOPO l'insert (FK self-reference): nullable solo per
            // questa finestra transitoria, valorizzato subito dopo.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('reseller_id')->nullable(); // NULL in V1 (ADR-002), no FK
            $table->unsignedBigInteger('parent_id')->nullable();   // NULL sul nodo ente
            $table->string('tipo'); // ente | dipartimento | sottolaboratorio
            $table->string('nome');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('tipo');

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('parent_id')->references('id')->on('unita_organizzativa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unita_organizzativa');
    }
};

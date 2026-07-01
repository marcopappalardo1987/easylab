<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log spostamenti strumento (ERD §5.4 — ADR-015). Append-only: niente soft
     * delete, niente update/delete. Origine/destinazione possono essere un nodo
     * interno OPPURE un'entità esterna alla piattaforma (testo libero). In V1
     * si usano `interno` e `ingresso`; `uscita`/`cross_tenant` sono predisposti.
     */
    public function up(): void
    {
        Schema::create('spostamenti_strumento', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();       // confine Ente
            $table->unsignedBigInteger('strumento_id')->index();

            // Origine: nodo interno | ente esterno | sconosciuta (tutto null)
            $table->unsignedBigInteger('da_nodo_id')->nullable();
            $table->string('da_esterno')->nullable();

            // Destinazione: nodo interno (V1) | ente esterno (predisposto)
            $table->unsignedBigInteger('a_nodo_id')->nullable();
            $table->string('a_esterno')->nullable();

            $table->string('tipo_spostamento'); // interno | ingresso | uscita | cross_tenant
            $table->date('data');
            $table->unsignedBigInteger('eseguito_da')->nullable();
            $table->text('nota')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('strumento_id')->references('id')->on('strumenti');
            $table->foreign('da_nodo_id')->references('id')->on('unita_organizzativa');
            $table->foreign('a_nodo_id')->references('id')->on('unita_organizzativa');
            $table->foreign('eseguito_da')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spostamenti_strumento');
    }
};

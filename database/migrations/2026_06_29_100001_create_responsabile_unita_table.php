<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot Responsabile Reparto ↔ nodo (ERD §3.2 — ADR-006).
     * L'utente è assegnato a uno o più nodi e vede solo il loro sotto-albero
     * (livello 2 del Global Scope, vedi DepartmentScope).
     */
    public function up(): void
    {
        Schema::create('responsabile_unita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unita_organizzativa_id')->constrained('unita_organizzativa')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'unita_organizzativa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsabile_unita');
    }
};

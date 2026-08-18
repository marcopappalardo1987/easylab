<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot "membri dell'account" (ERD §4.3 — ADR-032).
     *
     * Gemello di `tecnico_cliente` (ADR-007/030) per forma e per ruolo, e la
     * parentela è dichiarata da ADR-032 stesso: come quello elenca gli Enti che
     * un tecnico attraversa senza toccare `tenant_id`, questo elenca gli utenti
     * che amministrano un rapporto commerciale. Un pivot e non una FK
     * `owner_user_id` sull'account: gli utenti hanno soft delete, e un account
     * non deve morire perché il suo unico proprietario è stato disattivato —
     * la co-titolarità futura è una riga in più, non una migrazione.
     *
     * UNIQUE (user_id, account_id) fa due lavori, come nel gemello:
     *  - impedisce il doppione, che renderebbe ambigua la revoca;
     *  - con `user_id` in TESTA serve da indice per la lettura che sta sul
     *    percorso caldo — «gli account dell'utente X» — fatta dallo switcher
     *    in top bar a ogni pagina. (ERD §4.3 nasceva con l'ordine opposto:
     *    corretto qui e nella doc, il percorso caldo comanda.)
     *
     * L'indice su `account_id` serve alla direzione opposta — «chi amministra
     * questo account» (Policy billing, dashboard S6) — e al vincolo FK: su
     * Postgres un DELETE su `accounts` scandirebbe per intero la tabella
     * figlia senza un indice sulla colonna referenziante.
     */
    public function up(): void
    {
        Schema::create('account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'account_id']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_user');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * I **clienti preferiti**, per persona (🔗 ADR-037).
     *
     * Terzo pivot della stessa famiglia di `account_user` e `tecnico_cliente`,
     * e per la stessa ragione: è una preferenza **di chi guarda**, non un dato
     * del cliente. Due Superadmin seguono clienti diversi, e nessuna colonna su
     * `accounts` potrebbe dirlo — un flag lì renderebbe «preferito» una
     * proprietà del rapporto commerciale invece che della persona.
     *
     * ⚠️ **Nessun `tenant_id`, e non è una dimenticanza.** La riga lega un utente
     * di piattaforma a un Account, cioè attraversa i tenant per definizione,
     * come ogni riga della cabina di regia (🔗 ADR-018). Il confine non sta qui:
     * il perimetro che ne nasce passa comunque da `ParcoClienti::clienti()`, che
     * **interseca** con l'insieme legittimo — un preferito nel frattempo
     * cestinato, o l'account di piattaforma, non produce righe.
     *
     * UNIQUE (user_id, account_id) fa i due lavori del gemello: impedisce il
     * doppione — che duplicherebbe le righe di ogni `whereIn` costruito qui
     * sopra — e con `user_id` in testa serve da indice per la lettura calda,
     * «i preferiti di X», fatta a ogni render delle tre schede del Parco.
     *
     * L'indice su `account_id` serve al vincolo FK: su Postgres un DELETE su
     * `accounts` scandirebbe per intero la tabella figlia senza.
     */
    public function up(): void
    {
        Schema::create('clienti_preferiti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'account_id']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clienti_preferiti');
    }
};

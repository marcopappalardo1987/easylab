<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-out dal digest email delle scadenze (ERD §3.1 — ADR-011).
     *
     * È il diritto di opposizione del registro dei trattamenti (T4) reso una
     * colonna: finora era un impegno scritto e nient'altro. Riguarda **solo il
     * canale email**; le notifiche in-app restano sempre attive, perché sono la
     * copia di ciò che l'utente vede già entrando in app e non un invio verso
     * l'esterno.
     *
     * Default `true` di proposito: il promemoria di una manutenzione scaduta è
     * il servizio, non marketing — chi non lo vuole lo spegne, ma nessuno resta
     * senza avvisi per una casella mai spuntata. Il default copre anche gli
     * utenti esistenti senza backfill.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('riceve_email_scadenze')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('riceve_email_scadenze');
        });
    }
};

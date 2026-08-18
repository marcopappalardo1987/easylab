<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifiche in-app (ERD §9 — ADR-011). Tabella standard di Laravel, creata
     * dallo stub del framework e lasciata tale.
     *
     * **Perché NON porta `tenant_id`**, unica tabella nuova del progetto a non
     * averlo: una riga qui appartiene a una *persona* (`notifiable`), non a un
     * Ente — è la copia della notifica ricevuta, non un dato di business
     * dell'organizzazione. Il contesto Ente c'è comunque, dentro il payload
     * (`data.ente_id`), che è ciò che serve alla campanella per dire «di quale
     * Ente parla questa riga».
     *
     * La distinzione diventerà visibile con 🔗 ADR-032: lo switcher fra i propri
     * Enti riscrive `users.tenant_id`, e una notifica timbrata col tenant
     * sparirebbe dalla campanella appena l'utente cambia sede — pur essendo la
     * stessa persona con la stessa posta. Per lo stesso motivo non esiste un
     * model custom: le righe si leggono solo via `$user->notifications`, quindi
     * il meta-test dei modelli tenant non ha nulla da chiedere.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

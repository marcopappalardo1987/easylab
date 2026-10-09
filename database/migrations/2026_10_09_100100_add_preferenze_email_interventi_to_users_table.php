<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le tre preferenze email sugli interventi, per persona (🔗 ADR-047; stessa
 * forma di `riceve_email_scadenze`, ADR-011 e registro trattamenti T4).
 *
 * `true` per tutti: chi non ha mai scelto riceve. È il default già in uso per
 * il riepilogo, e l'opposizione si esercita dalla stessa pagina
 * (`/settings/notifiche`).
 *
 * Tre colonne e non una: «programmato», «eseguito» e «assegnato a me» arrivano
 * a persone diverse per ragioni diverse, e chi vuole sapere quando un lavoro è
 * finito non per questo vuole un'email a ogni pianificazione.
 */
return new class extends Migration
{
    private const COLONNE = [
        'riceve_email_interventi_programmati',
        'riceve_email_interventi_eseguiti',
        'riceve_email_interventi_assegnati',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (self::COLONNE as $colonna) {
                $table->boolean($colonna)->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(self::COLONNE);
        });
    }
};

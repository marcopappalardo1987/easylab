<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La preferenza sull'email «macchina segnalata», per persona (🔗 ADR-047,
 * aggiunta del 9 Ott 2026; stessa forma delle altre `riceve_email_*`).
 *
 * `true` per tutti: chi non ha mai scelto riceve, e vi rinuncia dalla pagina
 * delle preferenze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('riceve_email_macchine_segnalate')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('riceve_email_macchine_segnalate');
        });
    }
};

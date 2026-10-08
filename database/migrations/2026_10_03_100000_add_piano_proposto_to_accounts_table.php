<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il piano a pagamento che la cabina ha **proposto** a un account e che il
 * cliente non ha ancora pagato (🔗 ADR-045, ADR-032 l'Account intestatario,
 * ADR-002 il rapporto commerciale).
 *
 * 🔴 **Non è `accounts.piano`, ed è la ragione per cui esiste.** Un account
 * marcato su un piano a pagamento senza subscription è un cliente che risulta
 * pagante e non paga: è il difetto che la modale di provisioning ha sempre
 * rifiutato di produrre. Il cliente nasce quindi sul piano predefinito, e qui
 * resta scritto **cosa gli è stato chiesto di attivare** — finché non lo paga,
 * e a quel punto `Account::cambiaPiano()` lo azzera.
 *
 * ⚠️ **Stringa senza FK né CHECK, come `piano`** (ERD §4.3): il listino può
 * archiviare un piano, e una proposta rimasta su un piano ritirato non deve
 * far esplodere né una migration né un webhook. Chi la legge chiede prima a
 * `Piani::esiste()`.
 *
 * Additiva e nullable: le righe esistenti non hanno nessuna proposta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('piano_proposto')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('piano_proposto');
        });
    }
};

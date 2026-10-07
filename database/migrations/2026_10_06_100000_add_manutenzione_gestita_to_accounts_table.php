<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il cliente la cui manutenzione è **gestita da EasyLab** (🔗 ADR-046, ADR-032
 * l'Account, ADR-018 il confine fra clienti).
 *
 * 🔴 **Non è un'etichetta: apre i dati.** Su un account con questo segno il
 * Superadmin lavora come sul proprio Ente — vede e scrive macchine, interventi,
 * ricambi e documenti di tutte le sue sedi, senza impersonare nessuno
 * (`ClientiGestiti`). Per questo sta sull'Account e non sul piano: è una
 * clausola del rapporto, decisa da una persona, e un cliente Free può averla.
 *
 * Additiva, `false` per tutti: nessun cliente esistente cambia perimetro con
 * questa migration. A scriverla è solo `Account::affidaManutenzione()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('manutenzione_gestita')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('manutenzione_gestita');
        });
    }
};

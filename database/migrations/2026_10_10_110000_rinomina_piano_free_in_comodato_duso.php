<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il piano senza canone si chiama «Comodato d'uso» (🔗 ADR-050; ADR-035 il
 * listino a database).
 *
 * Il listino vive nel database e la config è solo il bootstrap: cambiare
 * l'etichetta in `config/easylab.php` basta a un database nuovo, non a quelli
 * che esistono già. Questa migration porta la riga dove la config porta le
 * nuove.
 *
 * ⚠️ **Solo se l'etichetta è ancora quella di partenza.** Se qualcuno l'ha già
 * cambiata dal listino, quella è una decisione presa da una persona, e una
 * migration non la sovrascrive. Il codice `free` non si tocca: è ciò che
 * `accounts.piano` conserva, e il cliente non lo vede.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('piani')
            ->where('codice', 'free')
            ->where('etichetta', 'Free')
            ->update(['etichetta' => "Comodato d'uso"]);
    }

    public function down(): void
    {
        DB::table('piani')
            ->where('codice', 'free')
            ->where('etichetta', "Comodato d'uso")
            ->update(['etichetta' => 'Free']);
    }
};

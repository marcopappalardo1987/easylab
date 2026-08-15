<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Token del QR di ogni strumento (ERD §5.1 — ADR-003), annunciato dal
     * docblock di `create_strumenti_table`: «il qr_token (S4, ADR-003) si
     * aggiunge nel rispettivo sprint».
     *
     * **Tre passi in una migration sola, e l'ordine è obbligato**: colonna
     * nullable → backfill delle righe esistenti → indice unique. Creare la
     * colonna già NOT NULL non è possibile su una tabella popolata, e mettere
     * l'unique prima del backfill fallirebbe al secondo NULL su Postgres... no:
     * su Postgres i NULL non collidono fra loro in un indice unique, ed è
     * proprio questo il punto — l'indice passerebbe e resterebbe una tabella
     * mezza vuota che *sembra* a posto. L'ordine qui sotto rende impossibile
     * quello stato.
     *
     * ⚠️ **Il backfill è la ragione per cui questa migration va guardata sul DB
     * di sviluppo, non solo in test**: la suite ricrea SQLite da zero, dove di
     * righe preesistenti non ce n'è nessuna, quindi un backfill sbagliato
     * passerebbe verde. Verifica: `select count(*) from strumenti where
     * qr_token is null` deve dare 0.
     *
     * Token di 32 caratteri da `Str::random()`: non è un id offuscato ma un
     * segreto: chi legge l'adesivo non deve poter indovinare quello della
     * macchina accanto. La colonna resta nullable in schema perché l'unico
     * modo di garantirla piena è l'hook `creating` del model — e una colonna
     * NOT NULL renderebbe non salvabile qualunque riga creata da un `insert()`
     * in blocco, che gli eventi non li fa scattare (DemoSeeder, import CSV).
     */
    public function up(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->string('qr_token', 32)->nullable();
        });

        // Riga per riga e non con un UPDATE unico: ogni strumento vuole il
        // PROPRIO token, e un `update` di massa scriverebbe lo stesso valore
        // ovunque. `chunkById` perché il parco è di migliaia di righe.
        DB::table('strumenti')->select('id')->whereNull('qr_token')->orderBy('id')
            ->chunkById(500, function ($righe) {
                foreach ($righe as $riga) {
                    DB::table('strumenti')->where('id', $riga->id)
                        ->update(['qr_token' => Str::random(32)]);
                }
            });

        Schema::table('strumenti', function (Blueprint $table) {
            $table->unique('qr_token');
        });
    }

    public function down(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il tetto di strumenti di un piano (🔗 ADR-049; ADR-035 il listino, ADR-032 il
 * limite di Enti, di cui questo è il gemello).
 *
 * Due colonne, perché sono due decisioni: **quanti** (`max_strumenti`) e **a
 * che cosa si applica** il numero (`conteggio_strumenti`: ogni sede per conto
 * suo, o il cliente in tutto). Stanno entrambe sul piano e non sull'Account: si
 * decidono nel listino, e chi attiva il piano le trova già decise.
 *
 * `max_strumenti` a `null` significa **illimitato**, come `max_enti`. È il
 * valore di ogni piano esistente: questa migration non mette un tetto a nessuno.
 *
 * ⚠️ `enum()` e non `string()`: Laravel lo rende `varchar` più un CHECK in
 * colonna, su Postgres come su SQLite, quindi il vincolo vale anche per le
 * scritture che non passano dal cast del model. I valori sono scritti per
 * esteso e non derivati da `App\Enums\ConteggioStrumenti`: una migration è la
 * fotografia dello schema al giorno in cui è stata scritta.
 *
 * Additiva e reversibile. Va applicata anche al DB di sviluppo
 * (`php artisan migrate`): la suite gira su un database ricreato da zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('piani', function (Blueprint $table) {
            $table->unsignedInteger('max_strumenti')->nullable();
            $table->enum('conteggio_strumenti', ['per_sede', 'per_cliente'])->default('per_sede');
        });
    }

    public function down(): void
    {
        Schema::table('piani', function (Blueprint $table) {
            $table->dropColumn(['max_strumenti', 'conteggio_strumenti']);
        });
    }
};

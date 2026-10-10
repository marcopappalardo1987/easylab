<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il referente di uno strumento, e le sue note (🔗 ADR-054; ERD §4.2).
 *
 * Il referente è la persona del laboratorio a cui la macchina fa capo: nome,
 * cognome, email e cellulare. Sta sulla riga dello strumento e non in una
 * tabella sua perché non è una persona di Easy Lab — non ha un account, non ha
 * un ruolo — ed è un dato **della macchina**: due strumenti con lo stesso
 * referente restano due righe, e cambiarlo su uno non lo cambia sull'altro.
 *
 * `referente_email` è ciò che decide se le email dello strumento arrivano anche
 * a lui (`App\Support\Notifiche\Referente`).
 *
 * Tutte nullable e senza backfill: gli strumenti già registrati non hanno un
 * referente, e i campi sono facoltativi anche per quelli nuovi.
 *
 * Additiva e reversibile. Va applicata anche al DB di sviluppo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->string('referente_nome')->nullable();
            $table->string('referente_cognome')->nullable();
            $table->string('referente_email')->nullable();
            $table->string('referente_cellulare', 50)->nullable();
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('strumenti', function (Blueprint $table) {
            $table->dropColumn(['referente_nome', 'referente_cognome', 'referente_email', 'referente_cellulare', 'note']);
        });
    }
};

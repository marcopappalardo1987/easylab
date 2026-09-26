<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'indice che serve alla vista Audit (S6), e che la tabella non ha mai avuto.
 *
 * `activity_log` nasce con gli indici di spatie — `log_name`, e i due morph su
 * `subject` e `causer` — ma **niente su `created_at`**, che è l'unico predicato
 * presente in *ogni* query della vista: è l'ordinamento di default e il campo
 * del filtro per periodo.
 *
 * ⚠️ **`(created_at, id)` e non la sola `created_at`.** L'ordinamento
 * obbligatorio della vista è `created_at DESC, id DESC`: il tie-break non è
 * prudenza, è necessario perché i timestamp si serializzano **al secondo** e
 * login e impersonazione vengono scritti nella stessa richiesta — i pari sono
 * la norma, non un caso limite, e senza tie-break la paginazione perde e
 * ripete righe. Con la sola `created_at` Postgres farebbe index scan
 * all'indietro più un ordinamento incrementale sui pari; con la coppia è una
 * scansione pura, e la colonna in più costa quanto niente.
 *
 * ⚠️ **Uno solo, e per una ragione.** `activity_log` è **append-only e
 * write-hot**: ogni scrittura di dominio del prodotto ci passa, quindi ogni
 * indice è un costo su ogni `create`/`update`/`delete` dell'intera
 * applicazione, non su questa pagina. Scartati: `event` (cinque valori
 * distinti, nessun planner lo sceglierebbe), `subject_type` (è già la colonna
 * di testa dell'indice morph esistente), `log_name` in testa a un composito (è
 * di fatto costante, quindi renderebbe l'indice equivalente al singolo ma più
 * grosso). Su `causer_id` **non** è vero che sia già coperto — è la seconda
 * colonna del morph, non un prefisso — ma resta fuori perché è un filtro raro,
 * e la ragione va detta com'è invece che a memoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['created_at', 'id'], 'activity_log_created_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_created_at_id_index');
        });
    }
};

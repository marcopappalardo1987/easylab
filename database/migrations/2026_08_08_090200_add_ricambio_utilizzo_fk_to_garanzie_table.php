<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salda il primo dei debiti dichiarati da `create_garanzie_table` (ERD §6.1
     * — ADR-004/008): `ricambio_utilizzo_id` nasceva in S3 senza vincolo perché
     * la tabella referenziata non esisteva. Ora esiste (migration 090100).
     *
     * **Solo il vincolo, nessun indice**: `->index()` era già sulla colonna
     * dalla S3, ed è quello che ERD §11 dichiara CALDO per il doppio salto di
     * ADR-020.
     *
     * **Nessun `onDelete`**, come su tutte le FK di dominio del progetto: a
     * rendere la cancellazione praticabile senza cascata è il `deleted_at` di
     * `ricambio_utilizzo` (vedi il docblock di quella migration).
     *
     * Sui due driver: su SQLite l'ALTER passa da `compileAlter()`, che ricostruisce
     * la tabella via `__temp__` — gli indici, compositi inclusi, sopravvivono
     * perché `BlueprintState` li rilegge dallo schema vivo. Su Postgres è un
     * `ADD CONSTRAINT`: sulle ~5100 righe del DB di sviluppo valida in
     * millisecondi, perché con la semantica `MATCH SIMPLE` una colonna NULL
     * soddisfa il vincolo senza alcuna lookup — e lì sono tutte NULL.
     */
    public function up(): void
    {
        Schema::table('garanzie', function (Blueprint $table) {
            $table->foreign('ricambio_utilizzo_id')->references('id')->on('ricambio_utilizzo');
        });
    }

    /** Non è a perdita: toglie il vincolo, la colonna e i dati restano. */
    public function down(): void
    {
        Schema::table('garanzie', function (Blueprint $table) {
            $table->dropForeign(['ricambio_utilizzo_id']);
        });
    }
};

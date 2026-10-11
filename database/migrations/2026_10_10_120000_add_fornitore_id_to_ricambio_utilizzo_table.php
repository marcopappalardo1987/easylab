<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il fornitore da cui è stato comprato un pezzo montato (🔗 ADR-051; ADR-023 il
 * fornitore della macchina, di cui è il gemello; ADR-008/022 i ricambi).
 *
 * Sta sulla riga del **montaggio** e non sulla voce di catalogo: la voce è un
 * nome («Guarnizione portello»), e lo stesso pezzo si compra da fornitori
 * diversi in anni diversi. A sapere da chi è arrivato è il pezzo montato quel
 * giorno, che è anche quello che porta la propria garanzia.
 *
 * Nullable e senza backfill, come `strumenti.fornitore_id`: i pezzi già
 * registrati non hanno un fornitore, e il campo è facoltativo anche per quelli
 * nuovi. Nessun `cascade`: un fornitore non si porta via i pezzi che ha
 * venduto, e `Fornitore` rifiuta di cancellarsi finché ne ha.
 *
 * Additiva e reversibile. Va applicata anche al DB di sviluppo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ricambio_utilizzo', function (Blueprint $table) {
            $table->unsignedBigInteger('fornitore_id')->nullable()->index();
            $table->foreign('fornitore_id')->references('id')->on('fornitori');
        });
    }

    public function down(): void
    {
        Schema::table('ricambio_utilizzo', function (Blueprint $table) {
            $table->dropForeign(['fornitore_id']);
            $table->dropColumn('fornitore_id');
        });
    }
};

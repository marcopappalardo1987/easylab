<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `ricambio_utilizzo.data` diventa nullable (ERD §7.2 — ADR-022).
     *
     * La colonna registra **quando il pezzo è stato montato**. Su un intervento
     * pianificato quel momento non è ancora arrivato, e finora ci si scriveva
     * *oggi* come segnaposto: la scheda dichiarava «montato il \<oggi\>» per un
     * pezzo che nessuno aveva toccato, e la riga sarebbe comparsa in qualunque
     * ricerca per periodo (§7.2 è la base della ricerca incrociata).
     *
     * Un primo tentativo aveva corretto solo la **chiusura** — `segnaFatto()`
     * riallinea le righe alla data di esecuzione — lasciando però il
     * segnaposto in piedi, e con esso il dato falso, fino a quel momento.
     * Correggere a valle un valore inventato a monte non lo rende vero: la
     * forma giusta è non inventarlo. NULL significa «non ancora montato», e
     * `segnaFatto()` continua a riempirlo con la data vera.
     *
     * Nessun backfill: le 4 righe esistenti sul DB di sviluppo restano con la
     * loro data, e una data c'è comunque per tutte quelle nate da un intervento
     * già eseguito.
     *
     * ⚠️ Gli ordinamenti su questa colonna devono ora essere espliciti sui
     * NULL: SQLite li mette per primi e Postgres per ultimi (trappola nota del
     * progetto). Le relazioni che ordinano per `data` portano un CASE che
     * mette in cima le righe **da montare** — sono quelle che aspettano
     * qualcosa, non le più vecchie.
     */
    public function up(): void
    {
        Schema::table('ricambio_utilizzo', function (Blueprint $table) {
            $table->date('data')->nullable()->change();
        });
    }

    /**
     * Rollback a perdita dichiarata: le righe non ancora montate non hanno una
     * data da ripristinare, e il NOT NULL le rifiuterebbe. Si scrive la data di
     * creazione, che è il momento in cui il pezzo è stato *annotato* — non
     * quello in cui è stato montato, ma è l'unica approssimazione disponibile.
     */
    public function down(): void
    {
        DB::table('ricambio_utilizzo')->whereNull('data')->update(['data' => DB::raw('date(created_at)')]);

        Schema::table('ricambio_utilizzo', function (Blueprint $table) {
            $table->date('data')->nullable(false)->change();
        });
    }
};

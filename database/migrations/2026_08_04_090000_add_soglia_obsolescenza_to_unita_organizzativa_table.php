<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soglia di obsolescenza per Ente (ERD §4.1 — ADR-014), già annunciata dal
     * docblock di `create_unita_organizzativa_table`: «i campi
     * fiscali/lockout/obsolescenza del nodo ente arrivano nei rispettivi
     * sprint come migration non distruttive».
     *
     * È un campo del solo nodo `ente`: sugli altri nodi resta al default e non
     * viene mai letto. NOT NULL con default 10 e non nullable, di proposito —
     * con una colonna nullable il "10 di default" vivrebbe in due posti (un
     * COALESCE in ogni forma SQL e un `?? 10` in PHP) e le due copie potrebbero
     * divergere; qui il valore è sempre sulla riga.
     */
    public function up(): void
    {
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->unsignedInteger('soglia_obsolescenza_anni')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->dropColumn('soglia_obsolescenza_anni');
        });
    }
};

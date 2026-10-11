<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Da quale fornitore del catalogo di EasyLab è stata copiata questa riga
     * (ERD §7.3 — 🔗 ADR-062). `null` per i fornitori che un cliente ha
     * inserito da sé, e per quelli di EasyLab stessa.
     *
     * Additiva: una colonna nullable. L'unico su (tenant_id, globale_id) tiene
     * una sola copia per catalogo; i `null` restano distinti su entrambi i
     * motori, quindi non tocca i fornitori che ci sono già.
     */
    public function up(): void
    {
        Schema::table('fornitori', function (Blueprint $table) {
            $table->unsignedBigInteger('globale_id')->nullable()->after('reseller_id');
            $table->foreign('globale_id')->references('id')->on('fornitori')->nullOnDelete();
            $table->unique(['tenant_id', 'globale_id']);
        });
    }

    public function down(): void
    {
        Schema::table('fornitori', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'globale_id']);
            $table->dropForeign(['globale_id']);
            $table->dropColumn('globale_id');
        });
    }
};

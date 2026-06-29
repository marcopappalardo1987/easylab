<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Confine di isolamento multi-tenant (ADR-001/006): l'Ente proprietario.
     * NULL = utente di piattaforma (Developer/Superadmin/Tecnico);
     * valorizzato per Admin/Responsabile Reparto/Tenant (ERD §3.1).
     * La FK verso `unita_organizzativa.id` si aggiunge quando quella tabella
     * esisterà (S2 punto 4); per ora solo colonna indicizzata.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')
                ->after('email')
                ->nullable()
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};

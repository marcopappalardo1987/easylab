<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soppressione delle letture contaore (ADR-019, ERD §5.3).
     *
     * La tabella esisteva per un motivo solo: fornire l'input alternativo con
     * cui stimare la scadenza delle garanzie "a ore" (ADR-004, metodo 2).
     * Eliminata quella forma di garanzia, nessun motore legge più queste righe:
     * resterebbe una funzione che chiede all'utente di inserire un dato che
     * nessuno guarda — peggio di una funzione mancante, perché sembra servire.
     *
     * ⚠️ MIGRATION DISTRUTTIVA: le letture registrate finora vanno perse. Sono
     * dato osservativo, non contabile, e il dump preso prima della migration
     * resta l'unica via di recupero.
     *
     * Se un giorno il contaore tornasse, tornerebbe come **dato operativo
     * autonomo** (usura, pianificazione) e non come input di garanzia: forma e
     * consumatori sarebbero altri, quindi ricrearla da zero è la strada giusta
     * — non conservare una tabella morta nell'attesa.
     */
    public function up(): void
    {
        Schema::dropIfExists('letture_contaore');
    }

    /**
     * Ricrea la sola forma della tabella, identica a
     * `create_letture_contaore_table`. I dati non tornano.
     */
    public function down(): void
    {
        Schema::create('letture_contaore', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->unsignedBigInteger('strumento_id');
            $table->date('data');
            $table->integer('ore');
            $table->unsignedBigInteger('registrata_da')->nullable();
            $table->timestamps();

            $table->index(['strumento_id', 'data']);

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('strumento_id')->references('id')->on('strumenti');
            $table->foreign('registrata_da')->references('id')->on('users')->nullOnDelete();
        });
    }
};

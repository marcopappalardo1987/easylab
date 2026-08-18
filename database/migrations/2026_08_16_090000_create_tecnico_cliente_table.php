<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot "portafoglio clienti" del Tecnico (ERD §3.3 — ADR-007, ADR-030).
     *
     * È il primo dei due canali di accesso del Tecnico: vede tutte le macchine
     * degli Enti elencati qui. Il secondo canale (assegnazione di intervento)
     * non ha tabella propria — vive già su `interventi.tecnico_id`.
     *
     * Gemello di `responsabile_unita` (ADR-006) per forma e per ruolo: un pivot
     * che concede *righe*, non permessi. Ne condivide la struttura di proposito,
     * così chi legge uno capisce l'altro.
     *
     * Migration **additiva**: non tocca dati esistenti. Finché il pivot è vuoto
     * il comportamento dei Tecnici resta quello di prima (fail-closed), quindi
     * applicarla non cambia nulla da sola.
     */
    public function up(): void
    {
        Schema::create('tecnico_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tecnico_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ente_id')->constrained('unita_organizzativa')->cascadeOnDelete();
            $table->timestamps();

            // UNIQUE (tecnico_id, ente_id) fa due lavori:
            //  - impedisce il doppione, che raddoppierebbe le righe della
            //    subquery `tenant_id IN (...)` senza cambiarne il risultato ma
            //    rendendo ambigua la revoca ("tolto uno, ne resta un altro");
            //  - con `tecnico_id` in TESTA serve da indice per l'unica lettura
            //    che sta sul percorso caldo — «il portafoglio del tecnico X»,
            //    eseguita a ogni query di ogni modello scopato. Per questo NON
            //    si aggiunge un secondo indice sul solo `tecnico_id`: sarebbe
            //    un prefisso di questo, quindi peso in scrittura e nulla in
            //    lettura.
            $table->unique(['tecnico_id', 'ente_id']);

            // Direzione opposta — «chi segue questo Ente?» — usata dalla pagina
            // permessi di S6 e, soprattutto, dal vincolo FK: su Postgres un
            // DELETE su `unita_organizzativa` scandisce per intero la tabella
            // figlia se la colonna referenziante non è indicizzata, e il primo
            // indice non copre `ente_id` da solo (non ne è il prefisso).
            $table->index('ente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tecnico_cliente');
    }
};

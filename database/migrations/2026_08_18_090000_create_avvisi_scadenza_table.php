<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Memoria degli avvisi già inviati (ADR-011, scheduler S5).
     *
     * Serve a una sola domanda, che lo scheduler pone a ogni giro: «di questa
     * scadenza ho già avvisato?». Senza, il comando giornaliero rimanderebbe la
     * stessa email finché la scadenza resta aperta — ed è il «niente duplicati»
     * che la Policy di Code Review dichiara area rossa.
     *
     * **`data_scadenza` fa parte della chiave, e non è ridondanza.** È ciò che
     * fa funzionare le proroghe: un intervento rinviato o una garanzia rinnovata
     * hanno una data nuova, quindi quando quella rientra in soglia l'avviso
     * riparte — legittimamente, perché è un'altra scadenza. Senza la data nella
     * unique servirebbe una logica di invalidazione a parte, cioè un secondo
     * posto in cui la regola può divergere.
     *
     * **Perché una tabella e non colonne sui modelli** (`avvisato_imminente_at`
     * su `interventi` e `garanzie`): quelle sarebbero stato eterno appiccicato a
     * due tabelle calde, incapaci di raccontare il secondo avviso dopo una
     * proroga senza colonne di reset. E soprattutto non sarebbero un *log*: il
     * registro dei trattamenti (T4) dichiara «log invii a rotazione», e una
     * colonna non ruota. La cache è stata scartata perché non è durabile — un
     * flush produrrebbe una tempesta di duplicati il giorno dopo, cioè il
     * fallimento esattamente nel modo che questa tabella deve impedire.
     *
     * Migration **additiva**: nessun dato esistente toccato. Finché il comando
     * non gira la tabella resta vuota e nulla cambia.
     */
    public function up(): void
    {
        Schema::create('avvisi_scadenza', function (Blueprint $table) {
            $table->id();

            // Denormalizzato di proposito, benché ricavabile dal riferimento: lo
            // scheduler gira in console, dove i global scope NON filtrano
            // (CurrentTenant::shouldScope() = false). Avere il tenant sulla riga
            // rende l'isolamento un'affermazione verificabile sul log stesso,
            // invece che una proprietà da dedurre seguendo il morph.
            $table->unsignedBigInteger('tenant_id')->index();

            // Intervento | Garanzia: le due sole fonti di scadenza del dominio
            // (le tarature sono interventi, ADR-009; l'obsolescenza non è un
            // motivo, ADR-024).
            $table->morphs('riferimento');

            $table->string('transizione');
            $table->date('data_scadenza');

            // Solo created_at: una riga di log non si aggiorna mai.
            $table->timestamp('created_at')->nullable();

            // Il cuore dell'idempotenza. Nome esplicito e corto: quello generato
            // da Laravel per quattro colonne supera i limiti di lunghezza degli
            // identificatori.
            $table->unique(
                ['riferimento_type', 'riferimento_id', 'transizione', 'data_scadenza'],
                'avvisi_scadenza_unico',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avvisi_scadenza');
    }
};

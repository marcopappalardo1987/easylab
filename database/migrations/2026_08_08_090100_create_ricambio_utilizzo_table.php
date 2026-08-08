<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Associazione macchina↔ricambio↔intervento (ERD §7.2 — ADR-008/022):
     * "questo pezzo è montato su questa macchina". È la base della ricerca
     * incrociata (dato un `ricambio_id` → tutti gli strumenti dove è usato) e
     * il ponte del doppio salto `garanzie → ricambio_utilizzo → strumenti` che
     * ADR-020 usa per far pesare la garanzia del pezzo sul semaforo.
     *
     * `intervento_id` è nullable: valorizzato per le righe create dal form
     * intervento (ADR-022, il punto d'ingresso abituale), NULL per gli
     * inserimenti diretti dal tab Ricambi.
     *
     * **Nessun vincolo "deve avere una garanzia"**: ADR-022 la rende
     * obbligatoria di FLUSSO, non di schema, perché la riga nasce prima della
     * sua garanzia dentro la stessa transazione (ERD §7.2).
     *
     * ⚠️ **`softDeletes()` è una deviazione dall'ERD §7.2**, che dà solo
     * `timestamps`. Il motivo è meccanico e non estetico: `garanzie` ha soft
     * delete e la FK `garanzie.ricambio_utilizzo_id` non ha `onDelete`
     * (convenzione del progetto sulle FK di dominio), quindi una garanzia
     * cestinata resta FISICAMENTE in tabella con la sua FK valorizzata e un
     * DELETE fisico dell'utilizzo violerebbe il vincolo. Senza `deleted_at`, la
     * correzione dal tab Ricambi dovrebbe fare `forceDelete()` della garanzia —
     * cioè buttare via la rete di sicurezza che `garanzie` ha di proposito — e
     * la terza via è chiusa da `Garanzia::verificaSoggetto()`, che vieta di
     * azzerare la FK. Con il soft delete, cancellare è cancellare, ed è anche
     * il comportamento di tutte le altre tabelle di business del progetto.
     * Conseguenza per chi legge queste righe altrove: una riga cestinata non
     * esiste per nessuna lettura di dominio, semaforo compreso — un pezzo
     * smontato per errore non deve accendere l'arancione.
     */
    public function up(): void
    {
        Schema::create('ricambio_utilizzo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();               // confine Ente
            $table->unsignedBigInteger('reseller_id')->nullable();          // NULL in V1 (ADR-002)

            $table->unsignedBigInteger('strumento_id')->index();
            $table->unsignedBigInteger('ricambio_id')->index();
            $table->unsignedBigInteger('intervento_id')->nullable()->index(); // NULL se inserito dal tab
            $table->integer('quantita')->default(1);
            $table->date('data');

            $table->timestamps();
            $table->softDeletes();                                          // vedi docblock

            // Quattro indici SINGOLI e nessun composito, di proposito. Le tre
            // query previste li usano uno alla volta: il doppio salto di ADR-020
            // è un `strumento_id IN (...)` (ERD §11 lo dichiara CALDO — lo
            // attraversa una query per pagina dell'elenco strumenti), la ricerca
            // incrociata parte da `ricambio_id`, il tab Ricambi di un intervento
            // da `intervento_id`. Un composito servirebbe una sola di queste e
            // pagherebbe sulle altre.

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('strumento_id')->references('id')->on('strumenti');
            $table->foreign('ricambio_id')->references('id')->on('ricambi');
            $table->foreign('intervento_id')->references('id')->on('interventi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ricambio_utilizzo');
    }
};

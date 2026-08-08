<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Garanzie (ERD §6.1 — ADR-004): motore sdoppiato, garanzia del macchinario
     * E del singolo ricambio montato, entrambe normalizzate in
     * `data_scadenza_effettiva`, l'unico campo che pilota semaforo e notifiche.
     * Le ore non arrivano mai al motore: servono solo a stimare quella data.
     *
     * `ricambio_utilizzo_id` nasce QUI senza vincolo di FK, perché la tabella
     * `ricambio_utilizzo` non esiste ancora (nasce in S4 — ADR-008). Debito
     * saldato l'8 Ago 2026 dalla migration additiva
     * `2026_08_08_090200_add_ricambio_utilizzo_fk_to_garanzie_table`.
     *
     * Il vincolo "esattamente uno fra strumento_id e ricambio_utilizzo_id,
     * coerente col soggetto" vive nel model (hook `saving`) e non come CHECK:
     * è lo stile del progetto per gli invarianti, e su SQLite un CHECK non è
     * alterabile con ALTER TABLE — bloccherebbe l'aggiunta della FK in S4.
     */
    public function up(): void
    {
        Schema::create('garanzie', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();               // confine Ente
            $table->unsignedBigInteger('reseller_id')->nullable();          // NULL in V1 (ADR-002)

            $table->string('soggetto');                                     // macchina|ricambio
            $table->unsignedBigInteger('strumento_id')->nullable();         // sse soggetto = macchina
            $table->unsignedBigInteger('ricambio_utilizzo_id')->nullable()->index(); // sse ricambio (FK in S4)

            $table->string('tipo_scadenza');                                // data|ore
            $table->date('data_inizio');
            $table->integer('durata_mesi')->nullable();                     // sse tipo_scadenza = data
            $table->integer('soglia_ore')->nullable();                      // sse tipo_scadenza = ore
            $table->date('data_scadenza_prevista')->nullable();             // stima, manuale in V1
            $table->date('data_scadenza_effettiva');                        // CAMPO GUIDA (ADR-004)

            $table->timestamps();
            $table->softDeletes();

            // "Garanzie di questo strumento, la più vicina prima": serve alla
            // scheda e al semaforo. Copre anche il solo `strumento_id`
            // (prefisso sinistro), quindi l'indice singolo dell'ERD sarebbe
            // ridondante — stesso ragionamento della migration interventi.
            $table->index(['strumento_id', 'data_scadenza_effettiva']);
            // Scadenzario e notifiche cross-strumento (ERD §11, scheduler S5).
            $table->index('data_scadenza_effettiva');

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
            $table->foreign('strumento_id')->references('id')->on('strumenti');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garanzie');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tenant_id` entra nella unique dell'idempotenza (ADR-011 × ADR-014/015).
     *
     * ## Il difetto che questa migration chiude
     *
     * Dal 27 Ago 2026 `avvisi_scadenza` ospita anche la transizione `obsoleta`,
     * la cui riga punta allo **Strumento** e porta come `data_scadenza` la
     * `data_installazione` **nuda** — vedi il docblock di
     * `App\Support\Notifiche\AvvisiObsolescenza` per il perché non sia
     * «installazione + soglia».
     *
     * Quando una macchina cambia Ente (ADR-015, `TipoSpostamento::CrossTenant`)
     * il nuovo proprietario ha diritto al proprio avviso, mentre la riga del
     * vecchio **resta**: il reset di `AvvisiObsolescenza` è conservativo e tocca
     * solo le macchine positivamente lette, e dopo il trasferimento quella
     * macchina non è più fra le sue. Le due righe condividono allora tutte e
     * quattro le colonne della vecchia unique — morph, riferimento, transizione
     * e data — e la seconda `create()` violava il vincolo: non un avviso in meno,
     * ma il comando notturno **abortito**, quindi nessun avviso per tutti gli
     * Enti successivi, ogni giorno, finché la macchina restava dov'era.
     *
     * ## Perché allargare la chiave e non stringere il codice
     *
     * L'unicità che il progetto vuole davvero è «di questa cosa, per questo
     * Ente, in questa transizione, a questa data, ho già avvisato»: il tenant è
     * sempre stato parte del significato — la colonna esiste sulla tabella dal
     * primo giorno, denormalizzata apposta perché in console i global scope non
     * filtrano — e mancava solo dalla chiave.
     *
     * ⚠️ **Non è un allentamento dell'idempotenza.** Per `imminente`/`scaduta`
     * il guardiano vero è il `whereNotExists` di `NotificaScadenze`, che
     * confronta riferimento, tipo, transizione e data **senza** tenant: uno
     * stesso intervento non può generare due avvisi neppure cambiando Ente.
     * Per `obsoleta` il confronto è in PHP, per Ente, e la riga in più è
     * esattamente il comportamento voluto.
     *
     * Migration **additiva sul contenuto**: si ricrea solo un indice, nessuna
     * riga viene letta, scritta o cancellata.
     */
    public function up(): void
    {
        Schema::table('avvisi_scadenza', function (Blueprint $table) {
            $table->dropUnique('avvisi_scadenza_unico');

            // `tenant_id` in testa: è anche il prefisso più selettivo per le
            // letture per Ente di `AvvisiObsolescenza`, che filtrano su
            // tenant + morph + transizione.
            $table->unique(
                ['tenant_id', 'riferimento_type', 'riferimento_id', 'transizione', 'data_scadenza'],
                'avvisi_scadenza_unico',
            );
        });
    }

    public function down(): void
    {
        Schema::table('avvisi_scadenza', function (Blueprint $table) {
            $table->dropUnique('avvisi_scadenza_unico');

            $table->unique(
                ['riferimento_type', 'riferimento_id', 'transizione', 'data_scadenza'],
                'avvisi_scadenza_unico',
            );
        });
    }
};

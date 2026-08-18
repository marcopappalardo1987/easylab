<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report di fine lavoro sull'intervento (ERD §5.2 — S4 blocco 10).
     *
     * È ciò che il tecnico scrive chiudendo il lavoro: «cosa ho trovato, cosa ho
     * fatto». L'Elenco Funzionalità lo chiama «foglio di intervento» e lo vuole
     * archiviato al termine di ogni riparazione, e il wireframe §3 gli dà una
     * casella di testo accanto a «Chiudi intervento».
     *
     * **Colonna e non documento**, benché `TipoDocumento::ReportFineLavoro`
     * esista: quello è il PDF che l'export (STRETCH) produrrà *da* questo testo.
     * Chiedere al tecnico di caricare un file mentre è davanti alla macchina, col
     * telefono in mano, avrebbe reso il campo più scomodo del quaderno che deve
     * sostituire. Il testo è il dato; il foglio è una sua resa.
     *
     * **Nullable, e resta nullable**: gli interventi storici non ce l'hanno — su
     * questo database ce ne sono migliaia — e renderla obbligatoria per tutti
     * significherebbe o inventare un contenuto o impedire la chiusura di un
     * lavoro perché manca una nota. Se un domani si vorrà esigerla, il posto è
     * la validazione del form (guardia sul chiamante), non lo schema.
     *
     * Nessun indice: non ci si cerca né ci si ordina. È un testo da leggere una
     * riga alla volta, aperta la scheda della macchina.
     */
    public function up(): void
    {
        Schema::table('interventi', function (Blueprint $table) {
            $table->text('report_fine_lavoro')->nullable()->after('data_esecuzione');
        });
    }

    public function down(): void
    {
        Schema::table('interventi', function (Blueprint $table) {
            $table->dropColumn('report_fine_lavoro');
        });
    }
};

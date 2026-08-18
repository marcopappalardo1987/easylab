<?php

use App\Enums\VisibilitaGaranzieRicambio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visibilità delle garanzie ricambio al ruolo `Tenant`, per Ente (ADR-029).
     *
     * Ricalca `soglia_obsolescenza_anni` (ADR-014), che è il precedente esatto:
     * campo del solo nodo `ente`, sugli altri resta al default e non viene mai
     * letto. **NOT NULL con default e non nullable**, per la stessa ragione
     * scritta lì: con una colonna nullable il default vivrebbe in due posti —
     * un COALESCE in ogni forma SQL e un `??` in PHP — e le due copie
     * potrebbero divergere. Qui il valore è sempre sulla riga.
     *
     * **Il default `modifica` È la decisione di ADR-029**, non una comodità di
     * schema: chi paga l'abbonamento possiede i propri dati, e gli Enti già a
     * sistema devono trovarsi nello stato nuovo senza bisogno di un backfill.
     * Fino a ieri valeva l'opposto (ADR-004: mai al Tenant), quindi questa
     * migration **cambia il comportamento dei dati esistenti**: è voluto, ed è
     * il motivo per cui non porta un `->after()` cosmetico ma questo commento.
     *
     * Nessun CHECK in colonna: è lo stile del progetto (l'enum PHP è la
     * guardia, vedi `TipoIntervento`) e su SQLite un CHECK non è alterabile con
     * ALTER, il che bloccherebbe ogni futura modifica alla tabella.
     */
    public function up(): void
    {
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->string('visibilita_garanzie_ricambio')
                ->default(VisibilitaGaranzieRicambio::Modifica->value);
        });
    }

    public function down(): void
    {
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->dropColumn('visibilita_garanzie_ricambio');
        });
    }
};

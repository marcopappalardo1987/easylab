<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogo ricambi (ERD §7.1 — ADR-008, raffinato da ADR-022): voce
     * riutilizzabile e ricercabile, che cresce incrementalmente col collega-o-crea.
     *
     * La chiave pratica è `(tenant_id, nome_normalizzato)` e non il `codice`
     * (ADR-022): il cliente nomina il pezzo, non lo codifica, e durante un
     * intervento il codice costruttore spesso non è a portata di mano. `codice`
     * resta nullable per chi lo conosce e per il merge doppioni (V1.1).
     *
     * `nome_normalizzato` è una COLONNA e non una funzione in query (ERD §7.1):
     * con un `LOWER(TRIM(...))` nel WHERE l'indice non verrebbe usato, e la
     * regola di normalizzazione finirebbe scritta in due posti — PHP e SQL —
     * liberi di divergere. La sola definizione vive in `Ricambio::normalizzaNome()`.
     *
     * Solo data layer: l'autocomplete che consuma questo indice è il blocco 3
     * di S4 (form intervento).
     */
    public function up(): void
    {
        Schema::create('ricambi', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();               // confine Ente
            $table->unsignedBigInteger('reseller_id')->nullable();          // NULL in V1 (ADR-002)

            $table->string('nome');                                         // come lo scrive l'operatore
            $table->string('nome_normalizzato');                            // derivata: vedi indice unique sotto
            $table->string('codice')->nullable();                           // nullable da ADR-022
            $table->string('descrizione')->nullable();

            $table->timestamps();
            $table->softDeletes();                                          // merge doppioni (V1.1)

            // Ricerca per codice costruttore e merge doppioni (ERD §11). Il
            // catalogo è piccolo e a scrittura rara: il costo è nullo, e
            // aggiungerlo dopo sarebbe una migration su una tabella cresciuta.
            $table->index(['tenant_id', 'codice']);

            $table->foreign('tenant_id')->references('id')->on('unita_organizzativa');
        });

        // Chiave del collega-o-crea, come UNIQUE e non come semplice indice.
        //
        // ADR-022 la chiama «la chiave pratica del catalogo»: una chiave che
        // nessun vincolo difende è un'affermazione, non una garanzia. Senza
        // unique, un futuro errore di normalizzazione produce doppioni in
        // SILENZIO — cioè proprio la degradazione della ricerca incrociata che
        // ADR-008 esiste per impedire. (ERD §7.1 dice solo "indicizzata" perché
        // quella sezione elenca indici, non vincoli.)
        //
        // PARZIALE `where deleted_at is null` per due ragioni:
        //  - una voce cestinata non deve bloccare la ricreazione di un pezzo
        //    con lo stesso nome (il merge doppioni cestina per mestiere);
        //  - la variante ovvia `unique(tenant_id, nome_normalizzato, deleted_at)`
        //    NON vincolerebbe nulla fra righe vive: su Postgres come su SQLite
        //    due NULL non sono uguali nel confronto di unicità.
        //
        // Scritto con DB::statement perché lo schema builder di Laravel non sa
        // esprimere indici parziali (`IndexDefinition` non ha un `where`).
        // Identificatori double-quoted ANSI: la STESSA SQL vale su SQLite e su
        // Postgres, nessun ramo per driver.
        //
        // Serve anche in LETTURA: il `deleted_at is null` che SoftDeletes emette
        // è esattamente il predicato dell'indice, quindi collega-o-crea e
        // autocomplete ci cadono sopra. Nessun secondo indice necessario.
        //
        // ⚠️ Su SQLite il predicato non sopravvive a una ricostruzione della
        // tabella: `compileIndexes()` legge da `pragma_index_list` solo
        // nome/colonne/unicità, e `compileAlter()` ricrea gli indici da lì. Chi
        // in futuro farà un `Schema::table('ricambi', ...)` con foreign/change
        // si ritroverà un unique TOTALE — più stretto, e potenzialmente in
        // errore se nel frattempo esistono doppioni cestinati.
        DB::statement(
            'create unique index "ricambi_tenant_id_nome_normalizzato_unique" '.
            'on "ricambi" ("tenant_id", "nome_normalizzato") where "deleted_at" is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ricambi');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il marchio con cui escono le email di un Ente (ERD §4.1 — 🔗 ADR-011).
     *
     * **Due colonne, non una tabella nuova.** È la stessa scelta già presa due
     * volte su questa tabella (`soglia_obsolescenza_anni` di ADR-014,
     * `visibilita_garanzie_ricambio` di ADR-029): hanno senso **solo** sul nodo
     * `tipo = ente` e restano nulle su tutti gli altri. Una tabella a parte
     * sarebbe un modello di business in più — cioè un `BelongsToTenant` da
     * aggiungere, un factory, una policy e un meta-test da soddisfare — per due
     * scalari che nessuno interroga mai da solo.
     *
     * Nullable entrambe, e il nullo **significa** qualcosa: «nessun marchio
     * proprio», cioè l'email esce con il logo e il blu di Easy Lab. Non serve
     * un default in colonna perché il fallback vive in `MarchioEmail`, che è
     * anche il posto in cui vale per un Ente cestinato o inesistente.
     *
     * `marchio_colore` è un `#rrggbb`, sette caratteri: la validazione sta nel
     * componente (`regex:/^#[0-9a-fA-F]{6}$/`), e la lunghezza in colonna è la
     * seconda rete — un CHECK ramificherebbe per driver, e SQLite non ha
     * ADD CONSTRAINT.
     *
     * ⚠️ Additiva e reversibile: `down()` droppa le due colonne e basta. I file
     * dei loghi restano sul disco `documenti` — il down toglie lo schema, non
     * riscrive la storia (principio della migration `fix_garanzie`).
     */
    public function up(): void
    {
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->string('marchio_logo_path')->nullable();
            $table->string('marchio_colore', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->dropColumn(['marchio_logo_path', 'marchio_colore']);
        });
    }
};

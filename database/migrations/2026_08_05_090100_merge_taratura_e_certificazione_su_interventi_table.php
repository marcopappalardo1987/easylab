<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Taratura e certificazione" è **una voce sola** (ADR-021, precisazione del
     * cliente): la prima lettura dell'elenco l'aveva spezzata in due tipologie
     * distinte. Nel linguaggio del cliente le due cose viaggiano insieme — la
     * taratura si chiude con il certificato.
     *
     * Perché una seconda migration e non una correzione della precedente: la
     * `remap_tipo_su_interventi_table` è **già applicata** al database di
     * sviluppo, quindi riscriverla la renderebbe una bugia (girata una volta
     * con un contenuto, versionata con un altro) e imporrebbe un rollback su
     * dati reali. Le correzioni di dati vanno sempre in avanti.
     *
     * Come la precedente: query builder e non Eloquent, perché il modello casta
     * `tipo` all'enum già aggiornato e non riuscirebbe a idratare le righe
     * `taratura` che deve correggere.
     */
    public function up(): void
    {
        DB::table('interventi')
            ->whereIn('tipo', ['taratura', 'certificazione'])
            ->update(['tipo' => 'taratura_e_certificazione']);
    }

    /**
     * Anche questo rollback è a perdita dichiarata: fuse le due voci, non si sa
     * più quali righe fossero tarature e quali certificazioni. Si torna a
     * `taratura`, che è il caso di gran lunga prevalente nei dati esistenti e
     * l'unico dei due presente prima di questa tornata di modifiche.
     */
    public function down(): void
    {
        DB::table('interventi')
            ->where('tipo', 'taratura_e_certificazione')
            ->update(['tipo' => 'taratura']);
    }
};

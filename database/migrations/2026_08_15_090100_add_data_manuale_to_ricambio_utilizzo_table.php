<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Questa data l'ha messa una persona» (ERD §7.2 — S4, tab Ricambi).
     *
     * La data di montaggio diventa editabile, ma `Intervento::segnaFatto()` la
     * riscrive a ogni chiusura: senza un modo di distinguere le due origini,
     * una correzione umana sparirebbe in silenzio alla prima riapertura e
     * richiusura dell'intervento — che è esattamente il difetto che il docblock
     * di `allineaRicambiAllaEsecuzione()` chiedeva di **decidere** e non di
     * scoprire. Fra le tre uscite possibili è stata scelta questa (15 Ago
     * 2026): è l'unica che non perde silenziosamente il lavoro di qualcuno.
     *
     * **NOT NULL con default `false`** e non nullable, per la ragione già
     * ratificata da `soglia_obsolescenza_anni` e `visibilita_garanzie_ricambio`:
     * con una colonna nullable il default vivrebbe in due posti — un COALESCE
     * in SQL e un `??` in PHP — liberi di divergere.
     *
     * **Nessun backfill, e non per pigrizia**: tutte le righe esistenti sono
     * state scritte dall'automatismo, quindi `false` non è un ripiego ma il
     * valore vero. È il caso opposto a `qr_token`, dove il default non esisteva
     * e il backfill era l'unico modo di avere una tabella coerente.
     */
    public function up(): void
    {
        Schema::table('ricambio_utilizzo', function (Blueprint $table) {
            $table->boolean('data_manuale')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ricambio_utilizzo', function (Blueprint $table) {
            $table->dropColumn('data_manuale');
        });
    }
};

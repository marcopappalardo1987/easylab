<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La preferenza di tema dell'utente (🔗 ADR-034 — Design System §8.3).
     *
     * **Il DB è la verità, `localStorage` è la cache che evita il lampo.** Il
     * verso è quello: chi entra da un dispositivo nuovo ritrova la propria
     * scelta, e un tablet condiviso in laboratorio non impone a tutti quella
     * dell'ultimo che l'ha toccato.
     *
     * **NOT NULL con default e non nullable**, per la ragione già scritta su
     * `visibilita_garanzie_ricambio` (ADR-029) e su `soglia_obsolescenza_anni`
     * (ADR-014): con una colonna nullable il default vivrebbe in due posti — un
     * COALESCE in ogni forma SQL e un `??` in PHP — liberi di divergere. Qui il
     * valore è sempre sulla riga, e il default copre gli utenti già a sistema
     * senza backfill.
     *
     * **`sistema` come default è una decisione, non una comodità**: finché
     * nessuno ha scelto decide il dispositivo, cioè la preferenza di
     * accessibilità che l'utente ha già espresso al proprio sistema operativo.
     * Il valore si traduce in **nessun attributo** sull'`<html>` (ADR-034
     * punto 2), non in `data-theme="light"`.
     *
     * ## Perché il CHECK, qui, e perché arriva con la colonna
     *
     * `enum()` non è un tipo nativo su Postgres né su SQLite: Laravel lo rende
     * `varchar` **più un CHECK in colonna**. È il modo di avere il vincolo su
     * entrambi i driver — a differenza di `strumenti.forced_state`, dove il
     * CHECK è arrivato dopo, con un `ALTER TABLE … ADD CONSTRAINT` che **SQLite
     * non sa eseguire** e che là si salta, lasciando l'invariante assente
     * proprio nel driver su cui gira la suite. Qui la colonna nasce col
     * vincolo, quindi vale in locale come in CI.
     *
     * Il cast a `TemaUtente` sul modello rifiuta già i valori fuori dai tre (un
     * `ValueError` sull'assegnazione), ma copre solo le scritture che passano
     * da Eloquent: **una migration di correzione o un import** scrivono col
     * query builder e non incontrano né `$fillable` né i cast.
     *
     * ⚠️ E qui una riga fuori enum non resterebbe silenziosa a lungo, sarebbe
     * silenziosa **nel posto peggiore**: il valore lo rilegge il layout a ogni
     * pagina, quindi un `viola` finito in colonna farebbe esplodere il cast in
     * `<html>` — cioè un 500 su **ogni** schermata di quell'utente, login
     * compreso. Meglio rifiutare la scrittura dove avviene.
     *
     * ⚠️ Additiva e reversibile, ma va applicata **anche al DB di sviluppo**
     * (`php artisan migrate`): la suite gira su un database ricreato da zero,
     * quindi una migration mancante su Postgres non emerge dai test.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ⚠️ I valori si scrivono per esteso — anche il default — e
            // **non** si derivano da `App\Enums\TemaUtente`, per la ragione
            // già annotata sul CHECK di `forced_state`: una migration è la
            // fotografia dello schema al giorno in cui è stata scritta, e deve
            // continuare a produrre lo stesso DDL anche quando l'enum cambierà.
            // Derivarla farebbe dire a una migration già eseguita una cosa
            // diversa da quella che ha eseguito — e la renderebbe capace di
            // andare in fatal per una classe rinominata.
            // (`visibilita_garanzie_ricambio` cita l'enum: quella scelta è
            // precedente a questo ragionamento, non è il modello da imitare.)
            $table->enum('tema', ['sistema', 'chiaro', 'scuro'])->default('sistema');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tema');
        });
    }
};

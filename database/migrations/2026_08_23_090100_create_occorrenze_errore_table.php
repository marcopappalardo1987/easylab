<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le **occorrenze** dell'error tracker interno (S6 — 🔗 `docs/Architettura/
     * Error Tracker Interno (piano).md`).
     *
     * Una riga = un avvenimento singolo col suo contesto: lo stack trace, dove
     * stava succedendo, chi c'era. È la tabella che risponde a «con quali dati
     * si rompe», mentre `errori` risponde a «cosa e quante volte».
     *
     * **Non ce n'è una per occorrenza**, e la differenza è il punto: il
     * contatore su `errori` conta *tutti* gli avvenimenti, qui se ne conserva un
     * **campione** (max N per issue, uno ogni M secondi). Le due cifre vanno
     * quindi lette insieme e dette insieme in pagina — «occorrenze: 10.412 ·
     * contesti conservati: 20» — o la seconda si legge come la prima.
     *
     * **`cascadeOnDelete` e non `nullOnDelete`**: un contesto senza la sua issue
     * non è consultabile da nessuna pagina (si arriva alle occorrenze *dalla*
     * issue) e resterebbe a occupare spazio portando dati personali — stack
     * trace, ip, user agent, input. La cancellazione della issue è la sola porta
     * da cui questi dati escono dal database, e deve chiuderla del tutto.
     *
     * **Nessuna colonna `tenant_id`**, come `errori`: tabella di piattaforma.
     * `user_id` dice *chi* c'era, non *di chi* è la riga.
     *
     * ⚠️ **L'input arriva qui già sanificato** (blocco 4): la denylist si applica
     * in **scrittura**, non in lettura. Una riga scritta in chiaro resterebbe in
     * chiaro per sempre dietro un gate che nessuno tranne il Developer può
     * ispezionare, e i codici di recupero 2FA passano da `$request->all()`.
     *
     * Migration **additiva**: nessun dato esistente toccato.
     */
    public function up(): void
    {
        Schema::create('occorrenze_errore', function (Blueprint $table) {
            $table->id();

            // `constrained('errori')` **esplicito**: dal nome della colonna
            // Laravel dedurrebbe la tabella `errores`, che non esiste. Il plurale
            // italiano è la ragione per cui ogni FK di questo progetto nomina la
            // propria tabella.
            $table->foreignId('errore_id')->constrained('errori')->cascadeOnDelete();

            // Il messaggio **di questa occorrenza**: quello su `errori` è solo il
            // campione della prima volta, e i due divergono appena il messaggio
            // è interpolato («Utente 42 non trovato», «Utente 87 non trovato»).
            $table->text('messaggio');

            $table->text('stack_trace');

            // `path()`, non `fullUrl()`: la query string di una rotta firmata
            // porta `signature` ed `expires`. (La stessa coppia rientrerebbe da
            // `input`, che infatti è sanificato — blocco 4.)
            // `text` per la stessa ragione dello user agent: fuori da HTTP qui
            // finisce il comando o la classe del job, e un FQCN annidato con
            // parametri supera i 255 senza sforzo.
            $table->text('percorso');
            $table->string('metodo');

            // Nullable perché fuori da HTTP (console, coda) non c'è codice di
            // stato: la colonna dice «non pertinente», non «zero».
            $table->unsignedSmallInteger('codice_http')->nullable();

            // Chi ha subito l'errore. `nullOnDelete`: la prova sopravvive
            // all'utente, come `risolto_da` su `errori`.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Chi stava *davvero* agendo, se la sessione era un'impersonazione —
            // stesso timbro che l'audit mette in `properties.impersonato_da`.
            //
            // Colonna nuda e non una seconda FK, di proposito: questo è un
            // percorso caldo che gira **dentro il gestore delle eccezioni**, e
            // ogni vincolo in più è lavoro fatto mentre l'applicazione sta già
            // andando male.
            //
            // ⚠️ **E l'id PUÒ restare orfano**: la prima stesura scriveva che
            // «gli utenti hanno soft delete, la riga non sparisce» — falso,
            // verificato: `users` non ha `deleted_at` e non usa il trait. La
            // prova che è falso sta due righe sopra, dove `user_id` è una FK
            // `nullOnDelete`, che serve solo se gli utenti si cancellano davvero.
            // La scelta resta (una FK sul percorso caldo si paga a ogni
            // eccezione), ma chi legge la pagina deve gestire un id che non
            // risolve — blocco 5.
            $table->unsignedBigInteger('impersonato_da')->nullable();

            // 45 caratteri: la lunghezza di un IPv6 mappato IPv4. Nullable
            // perché in console un ip non c'è.
            $table->string('ip', 45)->nullable();
            // ⚠️ **`text` e non `string`, e non è prudenza generica.** Uno user
            // agent reale del browser in-app di Facebook su Android misura **273
            // caratteri** — misurato, non stimato — contro i 255 di un varchar.
            // Su Postgres non tronca: **errore**. E l'errore verrebbe inghiottito
            // dal `try/catch` nudo del gestore, lasciando una issue col contatore
            // incrementato e **zero contesti**, indistinguibile dal campionamento:
            // si perderebbe la prova proprio sulle occorrenze che contano di più,
            // quelle di un utente vero con un browser vero.
            $table->text('user_agent')->nullable();

            // Già ripulito dalle chiavi sensibili in scrittura.
            $table->json('input')->nullable();

            // http | console | coda. Dice come leggere le colonne accanto:
            // fuori da `http`, `percorso` è il comando o la classe del job e
            // `codice_http` è null.
            $table->string('contesto');

            $table->timestamp('avvenuta_at');

            // Il dettaglio di una issue: le sue occorrenze, dalla più recente.
            // Non `timestamps()`: una riga di log non si aggiorna mai, e
            // `created_at` direbbe la stessa cosa di `avvenuta_at` in un secondo
            // posto in cui potrebbe divergere.
            $table->index(['errore_id', 'avvenuta_at']);

            // ⚠️ **E `avvenuta_at` da sola**: l'indice composto qui sopra serve il
            // dettaglio di una issue, ma la potatura del blocco 7 filtra su
            // `avvenuta_at` **senza** `errore_id` — e lo fa sulla tabella che
            // cresce di più. Senza questo, una scansione sequenziale ogni notte.
            $table->index('avvenuta_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occorrenze_errore');
    }
};

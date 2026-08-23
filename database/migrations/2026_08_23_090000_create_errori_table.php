<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le **issue** dell'error tracker interno (S6 — 🔗 `docs/Architettura/Error
     * Tracker Interno (piano).md`, ADR-017 quando sarà scritto).
     *
     * Su Laravel Cloud `laravel.log` vive su un disco **effimero e per-replica**,
     * azzerato a ogni deploy e a ogni risveglio da scale-to-zero: un errore visto
     * da un cliente può non lasciare nulla di consultabile. Il database è l'unico
     * store condiviso e persistente dell'ambiente, quindi è lì che gli errori si
     * raggruppano.
     *
     * Una riga = un **punto d'origine**, non un avvenimento: gli avvenimenti
     * stanno in `occorrenze_errore`. Il raggruppamento passa da `impronta`, ed è
     * `unique` perché è *quella colonna* a rendere idempotente il
     * `firstOrCreate` del percorso caldo — senza il vincolo, due richieste
     * concorrenti creerebbero due issue per lo stesso bug e il contatore
     * mentirebbe.
     *
     * **Tabella di piattaforma: niente `tenant_id`.** Un errore non è un dato di
     * un Ente ma dell'applicazione, e la pagina che li legge è del solo
     * Developer (`system.logs.view`). L'esenzione è dichiarata in
     * `TenantScopeGuardrailTest::NON_TENANT_MODELS`, sul pattern di `Account`.
     *
     * **Il messaggio è fuori dall'impronta** (ed è la colonna `messaggio` qui,
     * tenuta come *campione*): è interpolato — «Utente 42 non trovato» — quindi
     * dentro l'impronta darebbe una issue per occorrenza, e in una colonna
     * `unique` ci finirebbero dati personali.
     *
     * **La contabilità del campionamento sta QUI, non sulle occorrenze**
     * (`contesti`, `ultimo_contesto_at`): il percorso caldo deve costare due
     * query — un `update` atomico su questa riga e, al più, un `insert` di
     * contesto. Contare le occorrenze già salvate per decidere se salvarne
     * un'altra sarebbe una terza query, sulla tabella che cresce.
     *
     * Migration **additiva**: nessun dato esistente toccato, e finché la cattura
     * non è agganciata (blocco 3) la tabella resta vuota.
     */
    public function up(): void
    {
        Schema::create('errori', function (Blueprint $table) {
            $table->id();

            // sha1 esadecimale: 40 caratteri esatti, sempre. La lunghezza
            // dichiarata è documentazione — se un domani l'impronta cambiasse
            // forma, questa riga costringe a saperlo invece di troncare in
            // silenzio (e su Postgres un varchar(40) troppo corto ERRORE, non
            // tronca: è il verso giusto in cui sbagliare).
            $table->string('impronta', 40)->unique();

            $table->string('classe');

            // `text` e non `string`: un messaggio di eccezione non ha limite —
            // un errore di query ci mette dentro l'SQL intero.
            $table->text('messaggio');

            // Percorso **relativo** alla base del progetto (lo garantisce chi
            // scrive, blocco 3): su Cloud la directory di deploy cambia a ogni
            // release, e un percorso assoluto azzererebbe il raggruppamento a
            // ogni deploy — la issue di ieri e quella di oggi sarebbero due.
            $table->string('file');
            $table->unsignedInteger('riga');

            // aperto | risolto | ignorato. Stringa e non enum di dominio: i tre
            // gesti che muovono questa colonna nascono nel blocco 6, e un enum
            // senza i suoi consumatori sarebbe una classe scritta per nessuno.
            $table->string('stato')->default('aperto');

            // Contatore atomico: cresce con un `increment` sul percorso caldo,
            // senza rileggere nulla. `unsignedBigInteger` perché un errore in un
            // loop caldo fa numeri che un int a 32 bit non regge.
            $table->unsignedBigInteger('occorrenze')->default(1);

            // Contabilità del campionamento (max N contesti per issue, uno ogni
            // M secondi — `config('easylab.errori')`). `contesti` è quante righe
            // di contesto sono state conservate per questa issue, e si **azzera
            // alla riapertura**: senza, dopo un tentativo di correzione la issue
            // sarebbe già al cap e non catturerebbe mai più la prova che serve a
            // rispondere a «l'ho corretto, perché succede ancora?».
            $table->unsignedInteger('contesti')->default(0);
            $table->timestamp('ultimo_contesto_at')->nullable();

            $table->timestamp('prima_occorrenza_at');
            $table->timestamp('ultima_occorrenza_at');

            // Regressione: la issue era risolta ed è tornata. Colonna distinta da
            // `ultima_occorrenza_at` perché è il fatto che fa scattare il secondo
            // alert (blocco 8) e la sola prova leggibile che una correzione non
            // ha tenuto.
            $table->timestamp('riaperto_automaticamente_at')->nullable();

            $table->timestamp('risolto_at')->nullable();
            // `nullOnDelete`: la riga dell'errore sopravvive a chi l'ha chiuso.
            // Cancellare un utente non deve cancellare la storia dei bug.
            $table->foreignId('risolto_da')->nullable()->constrained('users')->nullOnDelete();

            // Alert (blocco 8): quando è partita l'email per questa issue.
            //
            // ⚠️ **Nessuna coppia `alert_giorno`/`alert_conteggio`.** Il cap
            // `alert_max_giornalieri` protegge la casella da una tempesta di
            // issue nuove dopo un deploy sbagliato, quindi è un cap **globale**,
            // non per-issue: un contatore denormalizzato su questa riga non
            // saprebbe contare gli alert delle *altre* issue, cioè proprio ciò
            // che il cap deve limitare. Si legge invece dalla colonna qui sotto,
            // con un `count()` su `alert_inviato_at >= oggi` — una query, nessuna
            // colonna che possa divergere dal fatto che pretende di riassumere.
            $table->timestamp('alert_inviato_at')->nullable();

            $table->timestamps();

            // La lista di default: aperti, i più recenti in cima.
            $table->index(['stato', 'ultima_occorrenza_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('errori');
    }
};

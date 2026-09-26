<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il **cestino delle persone** (🔗 ADR-038).
     *
     * Fino a oggi un utente non poteva uscire di scena: `users` non ha soft
     * delete, e la colonna `is_active` che l'ERD nominava non è mai nata perché
     * 🔗 ADR-012 la rifiutò due volte — «sarebbe la terza sorgente di verità su
     * chi può entrare», accanto alla password e a `email_verified_at`.
     *
     * 🔴 **Questa colonna non è quel flag, ed è la ragione per cui il rifiuto
     * non si applica.** Un flag aggiunge uno stato da consultare, cioè un `if`
     * da ricordare in ogni punto che interroga gli utenti. `deleted_at`
     * **toglie la riga**: login negato, sparizione dalle tendine, dai
     * destinatari del digest e dai candidati all'impersonazione arrivano tutti
     * dallo **stesso** global scope, che si applica da sé.
     *
     * ⚠️ **La conseguenza da conoscere prima di usarla**: `users.email` è unique
     * **senza condizione**, quindi una persona cestinata continua a occupare la
     * propria email e a impedirne la ricreazione. È voluto — due persone con lo
     * stesso indirizzo sono la stessa persona — ma obbliga chi cerca per email
     * a leggere `withTrashed()` e a **proporre il ripristino** invece di
     * fallire sull'unique.
     *
     * ⚠️ E l'altra: le cinque relazioni di **attribuzione storica**
     * (`interventi.tecnico_id`, `documenti.caricato_da`, `strumenti.forced_by`,
     * `spostamenti_strumento.eseguito_da`, `errori.risolto_da`) più le due
     * letture del registro di audit vanno lette `withTrashed()`, o lo storico
     * smette di nominare chi se n'è andato — che è esattamente quando serve.
     *
     * Additiva e reversibile: senza nessuna riga cestinata il comportamento
     * dell'applicazione non cambia di una query.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

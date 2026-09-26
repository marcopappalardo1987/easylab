<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciò che il blocco Cashier porta sull'Account (ERD §4.3/§9 — ADR-032).
     *
     * La migration che `create_accounts_table` aveva **prenotato** nel proprio
     * docblock: «Le colonne Cashier (`stripe_id`, `pm_type`, …) NON sono qui:
     * arrivano con la migration del blocco Cashier, come `qr_token` arrivò con
     * il blocco QR e non con la tabella strumenti».
     *
     * Due gruppi di colonne, due ragioni distinte.
     *
     * **1. Il customer Stripe e il piano.** L'account è il Billable (ADR-032:
     * mai `users`, che ha soft delete ed è una credenziale, non un cliente;
     * mai il nodo ente, che darebbe N abbonamenti allo stesso cliente).
     * `piano` è una **stringa** e non un enum PHP perché i codici vivono in
     * `config/easylab.php` — un enum sarebbe una seconda dichiarazione degli
     * stessi valori, libera di divergere; a validare è `Piani::esiste()`.
     *
     * **2. La seconda sorgente del lockout.** `locked_at`/`locked_reason`
     * restano la sorgente **manuale** (ADR-013); `stripe_locked_at`/
     * `stripe_lock_reason` sono quella **automatica**. Sono separate perché
     * `Account::blocca()` è idempotente come no-op: con un solo motivo, un
     * account già bloccato da Stripe e poi bloccato a mano per contenzioso
     * conserverebbe il motivo di Stripe, e il primo pagamento riuscito
     * riaprirebbe il contenzioso. `is_locked` resta la colonna che il
     * middleware legge, e vale «almeno una delle due sorgenti è accesa».
     *
     * Migration **additiva**: nessun account preesistente cambia stato, e
     * `piano` nasce `free` — il default sicuro, perché nessuno deve diventare
     * a pagamento per effetto di una migration.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // 1. Customer Stripe e piano.
            //
            // `stripe_id` è UNIQUE e non un semplice indice: l'handler del
            // webhook risale all'account con `where('stripe_id', …)->first()`
            // e quella uguaglianza è l'**unico** filtro che lo protegge (in
            // console e nei job i global scope si ritirano — ADR-011 §292).
            // «Un customer Stripe = un account» dev'essere un fatto del DB,
            // non una speranza.
            $table->string('stripe_id')->nullable()->unique();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            // Indicizzato per la dashboard Superadmin di S6 (conteggi per
            // piano, MRR): costo trascurabile su una tabella di account.
            $table->string('piano')->default('free')->index();

            // 2. Sorgente automatica del lockout (webhook Stripe).
            $table->timestamp('stripe_locked_at')->nullable();
            $table->string('stripe_lock_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // `dropUnique` esplicito prima della colonna: su alcuni driver
            // l'indice non se ne va da solo col `dropColumn`.
            $table->dropUnique(['stripe_id']);
            $table->dropIndex(['piano']);

            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
                'trial_ends_at',
                'piano',
                'stripe_locked_at',
                'stripe_lock_reason',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gli abbonamenti Stripe (ERD §9 — tabella di pacchetto, non di business).
     *
     * **Scritta a mano invece di pubblicare quella di Cashier**, e non per
     * gusto: la migration del pacchetto crea `user_id`, mentre col Billable su
     * `Account` la chiave esterna che Cashier cerca è **`account_id`**
     * (`ManagesSubscriptions::subscriptions()` fa `hasMany(…, $this->getForeignKey())`,
     * e `Account::getForeignKey()` dà `account_id`). Pubblicarla per poi
     * correggerla avrebbe lasciato in `database/migrations` un file datato
     * 2019 che mente sulla propria origine.
     *
     * **Nessun `tenant_id`** (ERD §9): non è una tabella di business, e
     * l'intestatario è già `account_id`, che sta *sopra* i tenant. Per la
     * stessa ragione non esiste un `App\Models\Subscription`: il model di
     * Cashier vive in `vendor/`, dove i meta-test non lo vedono e non gli
     * chiedono `BelongsToTenant`.
     *
     * **La FK c'è**, a differenza di quella del pacchetto: il progetto vincola
     * già due volte verso `accounts` (`account_user`, `unita_organizzativa`),
     * e il soft delete non è un argomento contro — non emette DELETE, quindi
     * non può violare il vincolo. `cascadeOnDelete` vale solo per la
     * cancellazione definitiva, dove lasciare abbonamenti orfani sarebbe
     * peggio che perderli.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'stripe_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

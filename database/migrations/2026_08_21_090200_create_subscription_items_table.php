<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le righe di un abbonamento (ERD §9 — tabella di pacchetto).
     *
     * Qui la FK verso `subscriptions` è la stessa del pacchetto: nessuna
     * deroga, l'intestatario del rapporto sta un livello sopra.
     *
     * **Tre migration del pacchetto collassate in una**: Cashier crea la
     * tabella nel 2019 e nel 2025 le aggiunge `meter_id` e `meter_event_name`
     * (fatturazione a consumo). Creandola da zero le colonne nascono già qui —
     * e devono esserci anche se in V1 non fatturiamo a consumo, perché
     * `SubscriptionItem` le dichiara nei propri cast e `Subscription` le
     * scrive: senza, il primo `updateOrCreate` del webhook fallirebbe su una
     * colonna inesistente.
     *
     * `quantity` e `meter_*` restano nullable per la stessa ragione per cui lo
     * sono nel pacchetto: un item a prezzo fisso non ha quantità metrata.
     */
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};

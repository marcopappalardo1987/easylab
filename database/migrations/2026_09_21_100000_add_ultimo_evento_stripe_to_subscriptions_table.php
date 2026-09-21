<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il `created` dell'ultimo evento Stripe applicato a una subscription
 * (🔗 ADR-013 il lockout per insoluto, ADR-032 l'Account intestatario).
 *
 * 🔴 Stripe **non garantisce l'ordine** delle consegne, e ritenta per giorni un
 * evento fallito. Un `customer.subscription.updated` «active» di ieri, arrivato
 * col retry dopo l'«unpaid» di oggi, riapriva un account insoluto. Con questa
 * colonna `StripeWebhookController` scarta ogni evento più vecchio dell'ultimo
 * applicato.
 *
 * ⚠️ **Intero (epoch di Stripe) e non timestamp**: il confronto si fa in PHP sul
 * valore che Stripe manda, senza fusi né le differenze fra SQLite e Postgres
 * sulle date (CLAUDE.md). Nullable: le righe esistenti non hanno storia, e il
 * primo evento che arriva la comincia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('creazione_ultimo_evento_stripe')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('creazione_ultimo_evento_stripe');
        });
    }
};

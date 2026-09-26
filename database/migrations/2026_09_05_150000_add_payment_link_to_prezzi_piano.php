<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il Payment Link di Stripe che vende un price (🔗 ADR-035, ADR-039).
 *
 * ## Perché su `prezzi_piano` e non su `piani`
 *
 * 🔴 Un plink è legato a **un price**, non a un piano: i suoi `line_items` non
 * si possono ripuntare a un altro price, quindi cambiare prezzo obbliga a
 * disattivarlo e crearne un altro. Vive dov'è la cosa a cui è legato, e ruota
 * con lei.
 *
 * ⚠️ E soprattutto: le righe **storiche** restano. Una sessione di pagamento
 * completata su un plink ormai spento deve poter ancora dire a quale piano
 * appartiene — è la stessa ragione per cui `prezzi_piano` conserva i price
 * storici (`Piani::perPrice()`), e su `piani` la risposta sarebbe già persa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prezzi_piano', function (Blueprint $table) {
            // Unique: è la chiave con cui il webhook risale al piano partendo da
            // `checkout.session.payment_link`. Un duplicato significherebbe due
            // piani per lo stesso link, cioè un account su un piano arbitrario.
            $table->string('stripe_payment_link_id')->nullable()->unique();

            // L'URL da mandare al cliente. Ridondante in teoria (Stripe lo
            // ricostruisce dall'id) ma non in pratica: mostrarlo nella cabina
            // deve costare zero chiamate di rete, come tutto il resto di quella
            // pagina.
            $table->string('stripe_payment_link_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prezzi_piano', function (Blueprint $table) {
            $table->dropColumn(['stripe_payment_link_id', 'stripe_payment_link_url']);
        });
    }
};

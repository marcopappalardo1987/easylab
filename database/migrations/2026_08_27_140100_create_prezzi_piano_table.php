<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo **storico** dei Price di Stripe di ogni piano (🔗 ADR-035).
 *
 * 🔴 **Perché una seconda tabella e non una colonna su `piani`.** Su Stripe un
 * Price è **immutabile**: cambiare cifra non modifica il price esistente, ne
 * crea uno nuovo. La decisione di prodotto è che **chi è già abbonato resta al
 * suo** — le subscription in essere continuano a fatturare sul price vecchio,
 * anche archiviato.
 *
 * Se `Piani::perPrice()` guardasse soltanto il price **corrente**, al primo
 * cambio di listino il webhook `customer.subscription.updated` di ogni cliente
 * vecchio tornerebbe `null` e `accounts.piano` non si riallineerebbe più —
 * **in silenzio**, perché quel `null` è già oggi un esito legittimo e
 * `StripeWebhookController::applicaStato()` lo tratta come tale. Questa tabella
 * è ciò che tiene vivo il riallineamento.
 *
 * Append-only per costruzione: nessuna persona la scrive, la aggiorna solo la
 * sincronizzazione. Da qui l'esenzione dichiarata in
 * `AuditCoverageGuardrailTest::ESENZIONI`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prezzi_piano', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piano_id')->constrained('piani')->cascadeOnDelete();

            // Unique: è l'identità del price su Stripe, ed è la chiave con cui
            // il webhook risale al piano. Un duplicato qui significherebbe due
            // piani per lo stesso price, cioè un riallineamento arbitrario.
            $table->string('stripe_price_id')->unique();

            $table->unsignedInteger('importo_cent');
            $table->char('valuta', 3);

            // Uno solo per piano è `true`, ma il vincolo non è a schema: un
            // unique parziale non è portabile fra SQLite e Postgres, e la
            // regola vive in `GovernoListino`, che marca la vecchia riga prima
            // di inserire la nuova nella stessa transazione.
            $table->boolean('corrente')->default(true);

            $table->timestamps();

            $table->index(['piano_id', 'corrente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prezzi_piano');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il listino dei piani commerciali passa da `config/easylab.php` al database
 * (🔗 ADR-035, ADR-002, ADR-032).
 *
 * Fino a qui il catalogo era una chiave di config: cambiarlo voleva dire un
 * commit e un deploy, cioè uno sviluppatore. Da ADR-035 il listino si governa
 * da `/piattaforma/piani`, e la config resta il **bootstrap** — letto una volta
 * sola dalla migration di backfill che segue queste due.
 *
 * ⚠️ **Nessuna FK da `accounts.piano`, ed è deliberato.** Quella colonna è una
 * **stringa senza CHECK**: la scrivono il webhook Stripe e i comandi di console
 * per **codice**, e a validarla è `Piani::esiste()` (cioè `Account::cambiaPiano()`).
 * Una FK renderebbe non cancellabile un piano che comunque non si deve poter
 * cancellare — qui un piano si **archivia** (`attivo = false`), mai si elimina:
 * cancellarlo produrrebbe di colpo N clienti «fuori catalogo», che nell'MRR di
 * `MetrichePiattaforma` valgono **0 €**. Da qui l'altra metà della decisione,
 * che vive in `GovernoListino`: il `codice` è **immutabile dopo la creazione**.
 *
 * ⚠️ **Nessun `tenant_id`**: è una tabella di **piattaforma**, come `accounts`
 * ed `errori` — un listino non appartiene a un Ente. L'esenzione è dichiarata
 * per nome in `TenantScopeGuardrailTest::NON_TENANT_MODELS`.
 *
 * 💶 `prezzo_mensile_cent` in **centesimi interi e mai float**: un MRR su
 * decine di clienti in virgola mobile accumula errore, ed è la riga che nessuno
 * rilegge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piani', function (Blueprint $table) {
            $table->id();

            // È ciò che `accounts.piano` conserva, quindi è la chiave vera del
            // listino: unique a database e non solo validato in PHP.
            $table->string('codice', 30)->unique();

            $table->string('etichetta');

            // `null` = **illimitato**, non «non lo so»: è la forma che un piano
            // Enterprise avrebbe, e dichiararla evita che domani la si esprima
            // con un numero grande a caso (`Piani::maxEnti()`).
            $table->unsignedInteger('max_enti')->nullable();

            // La gratuità si **dichiara**, non si deduce dal prezzo a zero: un
            // piano omaggiato non ha customer né subscription (ADR-002), un
            // SaaS in promozione a 0 € sì. Immutabile dopo la creazione.
            $table->boolean('gratuito')->default(false);

            $table->unsignedInteger('prezzo_mensile_cent');

            // Fino a qui la valuta viveva **solo** in `cashier.currency`, e il
            // commento di config/easylab.php la dichiarava come divergenza non
            // presidiata. Registrarla sulla riga è ciò che rende possibile il
            // confronto con Stripe senza indovinare.
            $table->char('valuta', 3)->default('eur');

            // Governa **solo** l'offribilità (nuove sottoscrizioni, select del
            // provisioning), MAI l'esistenza: `Piani::esiste()` e `::codici()`
            // continuano a includere gli archiviati, o ogni account che li
            // tiene varrebbe 0 € nell'MRR.
            $table->boolean('attivo')->default(true);

            $table->unsignedSmallInteger('ordine')->default(0)->index();

            $table->string('stripe_product_id')->nullable();
            $table->timestamp('stripe_sincronizzato_at')->nullable();

            // Il fallimento della sincronizzazione è **visibile e non
            // silenzioso**: la riga locale resta, l'errore si legge in pagina.
            $table->text('stripe_ultimo_errore')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piani');
    }
};

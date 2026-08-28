<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registrazioni pubbliche in corso (ERD §4.3 — 🔗 ADR-012, ADR-032).
 *
 * 🔴 **Non è un utente, e la differenza è tutta la feature.** Chi compila il
 * modulo di `/registrati` non esiste ancora come `User`, non ha un `Account` e
 * non ha un Ente: la decisione di prodotto dice che l'account nasce **solo** se
 * il pagamento è andato a buon fine, quindi fra il modulo e Stripe serve un
 * posto in cui parcheggiare l'intenzione senza sporcare le tabelle di dominio.
 * Questa è quella sala d'attesa: finché `completata_at` è null non esiste
 * niente altrove, e se il pagamento non arriva la riga muore da sola con la
 * potatura (`App\Support\Retention`).
 *
 * **Tabella di piattaforma, niente `tenant_id`**: un Ente non c'è ancora, e
 * scoparla al tenant corrente la renderebbe invisibile proprio al percorso che
 * la scrive — che gira senza nessuno autenticato. L'esenzione è dichiarata per
 * nome in `TenantScopeGuardrailTest::NON_TENANT_MODELS`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrazioni', function (Blueprint $table) {
            $table->id();

            // Come si chiamerà l'Ente e chi lo amministra: gli stessi due
            // argomenti che `easylab:provision-tenant` chiede da console.
            $table->string('nome_ente');
            $table->string('nome_referente');

            // ⚠️ **Nessun `unique`, e non è una dimenticanza.** Una
            // registrazione abbandonata mesi fa non deve impedire alla stessa
            // persona di riprovare: la riga pendente si **riusa** (il modulo
            // cerca `whereNull('completata_at')`), e quelle completate restano
            // come storico. Il vincolo che conta è altrove: dopo il
            // completamento la stessa email è già un `User`, e `users.email` è
            // unique.
            $table->string('email')->index();

            // 🔴 L'hash della password scelta al modulo, **azzerato appena
            // l'utente nasce**. È un segreto in transito, non un dato da
            // conservare: dal momento in cui `users.password` esiste, questa
            // colonna non ha più nessuna ragione di essere valorizzata.
            // `CompletaRegistrazione` la svuota nella stessa transazione.
            $table->string('password_hash')->nullable();

            // Il codice del piano scelto, come `accounts.piano`: una stringa
            // senza foreign key, perché il listino vive a database ma può
            // archiviare un piano (ADR-035) e un vincolo lo renderebbe
            // incancellabile.
            $table->string('piano');

            // La verifica della casella: finché è null il checkout non si apre
            // (guardia in `RegistrazionePubblica::versoStripe`).
            $table->timestamp('email_verificata_at')->nullable();

            // ⛔ **UNIQUE, ed è metà dell'idempotenza.** `lockForUpdate()` è un
            // no-op su SQLite (dove gira la suite), quindi il lock protegge in
            // produzione e questo indice protegge ovunque: due consegne dello
            // stesso evento non possono produrre due righe che si credono
            // entrambe la sessione di pagamento.
            $table->string('stripe_session_id')->nullable()->unique();

            // Il timbro «da qui in poi esiste un account»: è la guardia di
            // idempotenza letta DENTRO la transazione di completamento.
            $table->timestamp('completata_at')->nullable();

            // L'account nato da questa registrazione. `nullOnDelete` e non
            // `cascade`: la riga è anche la traccia di come quel cliente è
            // arrivato, e cancellarla insieme all'account toglierebbe la
            // risposta a «da dove è entrato?».
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->timestamps();

            // La potatura interroga `completata_at IS NULL AND created_at < ?`:
            // l'indice composto la serve senza scansione.
            $table->index(['completata_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrazioni');
    }
};

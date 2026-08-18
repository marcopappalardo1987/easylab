<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'Account: l'intestatario del rapporto commerciale (ERD §4.3 — ADR-032).
     *
     * Vive **sopra** gli Enti, a livello piattaforma come `resellers` — quindi
     * niente `tenant_id` e niente `reseller_id`: non è un dato *di* un tenant,
     * è ciò che i tenant li possiede. È la chiusura della «Scelta documentata»
     * di ERD §4.1: la tabella lì prenotata come 1-1 col nodo ente è arrivata
     * prima dei dati, ed è 1-N — un account copre N Enti.
     *
     * Qui nascono (non si spostano: a DB non sono mai esistite altrove):
     * - i **dati fiscali** (ADR-010) — l'intestatario della fattura è chi paga,
     *   non la singola sede; `codice_destinatario_sdi` con doppia semantica
     *   privato/PA (avvertenza in ADR-002);
     * - il **lockout** (ADR-013) — l'insoluto è del rapporto commerciale:
     *   `is_locked` qui blocca tutti gli Enti dell'account. Il middleware che
     *   lo fa rispettare arriva col punto S5 dedicato.
     *
     * Le colonne Cashier (`stripe_id`, `pm_type`, …) NON sono qui: arrivano con
     * la migration del blocco Cashier, come `qr_token` arrivò con il blocco QR
     * e non con la tabella strumenti.
     *
     * Migration **additiva**: finché nessun Ente punta qui, non cambia nulla.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('ragione_sociale');

            // Dati fiscali (ADR-010): predisposizione e-invoicing, nullable
            // perché il provisioning Free li raccoglie dopo, a contratto.
            $table->string('partita_iva')->nullable();
            $table->string('codice_fiscale')->nullable();
            $table->string('pec')->nullable();
            $table->string('codice_destinatario_sdi')->nullable();

            // Lockout insoluto (ADR-013): a livello account, mai per-Ente.
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

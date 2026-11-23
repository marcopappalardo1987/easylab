<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il registro delle email che l'applicazione consegna al server di posta
 * (🔗 ADR-064). Una riga per tentativo: nasce «in invio» quando l'email sta per
 * partire e diventa «accettata» quando il server la prende in carico.
 *
 * ⛔ Niente corpo del messaggio: tipo, destinatari, oggetto e mittente bastano
 * a rispondere a «che cosa è partito, e a chi», e il resto non si conserva.
 * Niente `tenant_id`: è un registro di piattaforma (vedi `EmailInviata`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_inviate', function (Blueprint $table) {
            $table->id();
            // La voce di `CatalogoEmail`; null per un'email fuori catalogo (la prova di invio).
            $table->string('tipo', 40)->nullable()->index();
            // La classe che l'ha mandata, senza namespace: dice che cosa era anche fuori catalogo.
            $table->string('classe', 120)->nullable();
            $table->text('destinatari');
            $table->string('oggetto', 500)->nullable();
            $table->string('mittente')->nullable();
            // Il mailer configurato (ses, smtp, log…): distingue un invio vero da uno finto.
            $table->string('mailer', 30)->nullable();
            $table->string('stato', 20)->index();
            // L'id che il server di posta dà al messaggio (per SES, quello con cui si cercano i rimbalzi).
            $table->string('message_id')->nullable();
            // L'id della notifica di Laravel: lega fra loro i tentativi della stessa email.
            $table->uuid('notifica_id')->nullable()->index();
            $table->timestamp('accettata_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_inviate');
    }
};

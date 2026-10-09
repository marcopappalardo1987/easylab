<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli interruttori di piattaforma delle email (🔗 ADR-047, ADR-011 le notifiche).
 *
 * Una riga per ogni email che qualcuno ha acceso o spento a mano da
 * Piattaforma → Email. **L'assenza della riga non vuol dire «spenta»**: vuol
 * dire «mai toccata», e lo stato è quello con cui quell'email nasce
 * (`CatalogoEmail`). È ciò che permette alle email nuove di nascere spente
 * senza una riga di seeding da ricordarsi a ogni deploy.
 *
 * ⚠️ **Nessun `tenant_id`, e non è una svista**: sono interruttori della
 * piattaforma, valgono per tutti i clienti insieme. La scelta della singola
 * persona sta su `users` (le colonne `riceve_email_*`).
 *
 * La chiave è una stringa senza vincoli: l'elenco delle email vive nel codice,
 * e una riga rimasta su un'email tolta dal catalogo non deve far fallire
 * nessuna migration. Chi legge chiede prima al catalogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interruttori_email', function (Blueprint $table) {
            $table->string('chiave')->primary();
            $table->boolean('attiva');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interruttori_email');
    }
};

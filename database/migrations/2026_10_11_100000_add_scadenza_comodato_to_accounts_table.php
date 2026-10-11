<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La scadenza di un piano in comodato d'uso (🔗 ADR-058; ADR-002 il piano senza
 * canone, ADR-050 il suo nome).
 *
 * `comodato_scade_il` è la data fino a cui il comodato vale: la sceglie chi
 * assegna il piano (12, 24 o 36 mesi, o un giorno preciso) e si può cambiare
 * dopo. `null` = senza scadenza, che è ciò che ogni account era fino a oggi.
 *
 * `comodato_avvisato_per` è la memoria dell'avviso dei trenta giorni: porta la
 * **scadenza di cui si è già scritto**. Una data e non un flag, perché spostare
 * la scadenza deve riarmare l'avviso da sé — un `true` rimasto lì lo terrebbe
 * muto per sempre.
 *
 * Entrambe nullable e senza backfill. Additiva e reversibile. Va applicata
 * anche al DB di sviluppo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->date('comodato_scade_il')->nullable();
            $table->date('comodato_avvisato_per')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['comodato_scade_il', 'comodato_avvisato_per']);
        });
    }
};

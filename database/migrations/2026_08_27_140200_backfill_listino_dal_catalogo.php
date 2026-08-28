<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il bootstrap del listino: `config('easylab.piani.catalogo')` → tabella `piani`
 * (🔗 ADR-035).
 *
 * 🔴 **È una migration e non un seeder, e la differenza è la feature.**
 * `config/rbac.php` ha un seeder rilanciabile, e proprio quello rende
 * distruttivo il gesto corretto: `syncPermissions()` detacha tutto e riattacca
 * dai default, cancellando ogni personalizzazione di runtime (CLAUDE.md). Qui
 * la stessa trappola non si documenta: si rende **inesprimibile**. Non esiste
 * nessun `PianiSeeder` che qualcuno possa rilanciare per riflesso dopo aver
 * toccato la config — il listino, dopo questa riga, si governa da
 * `/piattaforma/piani`.
 *
 * Effetto collaterale gradito: `RefreshDatabase` migra, quindi i due piani
 * esistono in **ogni** test senza toccare `tests/Pest.php` né le factory.
 *
 * ⚠️ **Idempotente** (`updateOrInsert` sul `codice`) e **muta** se la chiave di
 * config fosse assente: una data migration che lancia blocca il deploy per un
 * dato che si può inserire a mano dalla schermata.
 *
 * ⚠️ Su Laravel Cloud la config è cachata in **build** e questa migration gira
 * **dopo**: legge quindi `stripe_price`, cioè `env('STRIPE_PRICE_SAAS')`
 * risolta dentro `config/` (l'unico posto legittimo). Se quella variabile
 * mancasse al momento del deploy, il piano `saas` nasce **senza** price id —
 * non è un guasto irreversibile: la schermata ha l'azione «aggancia un price
 * esistente», che è la via giusta. Ricreare un price duplicherebbe il prodotto
 * su Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalogo = config('easylab.piani.catalogo');

        if (! is_array($catalogo)) {
            return;
        }

        $valuta = (string) config('cashier.currency', 'eur');
        $ordine = 0;

        foreach ($catalogo as $codice => $definizione) {
            if (! is_array($definizione)) {
                continue;
            }

            $ordine++;

            DB::table('piani')->updateOrInsert(
                ['codice' => $codice],
                [
                    'etichetta' => $definizione['etichetta'] ?? $codice,
                    // `array_key_exists` e non `??`: `null` è un valore
                    // **dichiarato** legittimo (illimitato), una chiave assente
                    // è un piano scritto a metà. È la stessa distinzione che
                    // `Piani::attributo()` faceva sulla config.
                    'max_enti' => array_key_exists('max_enti', $definizione) ? $definizione['max_enti'] : null,
                    'gratuito' => (bool) ($definizione['gratuito'] ?? false),
                    'prezzo_mensile_cent' => (int) ($definizione['prezzo_mensile_cent'] ?? 0),
                    'valuta' => $valuta,
                    'attivo' => true,
                    'ordine' => $ordine,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $price = $definizione['stripe_price'] ?? null;

            if (! is_string($price) || $price === '') {
                continue;
            }

            $pianoId = DB::table('piani')->where('codice', $codice)->value('id');

            DB::table('prezzi_piano')->updateOrInsert(
                ['stripe_price_id' => $price],
                [
                    'piano_id' => $pianoId,
                    'importo_cent' => (int) ($definizione['prezzo_mensile_cent'] ?? 0),
                    'valuta' => $valuta,
                    'corrente' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // Le due tabelle si svuotano, non si cancellano: lo schema lo tolgono
        // le due migration che precedono.
        DB::table('prezzi_piano')->delete();
        DB::table('piani')->delete();
    }
};

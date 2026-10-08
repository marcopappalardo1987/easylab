<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\Piano;
use App\Support\Billing\AbbonamentoStripe;
use App\Support\Listino\CatalogoPiani;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\AbbonamentoStripeFinto;

/**
 * Il banco dei test dell'attivazione e del cambio di piano (🔗 ADR-045).
 *
 * La suite nasce con due piani a listino — `free` (gratuito) e `saas` (49 €) —
 * e **nessuna** riga in `prezzi_piano`: la migration di backfill ne crea una
 * solo se `STRIPE_PRICE_SAAS` è valorizzata, e `phpunit.xml` la azzera. Qui si
 * costruisce un listino con più gradini, che è ciò che serve per provare «mai
 * un piano che costa meno».
 *
 * ⚠️ Ogni scrittura **dimentica il memo** del catalogo: senza, il test
 * leggerebbe il listino di prima e passerebbe (o fallirebbe) per caso.
 */
final class BancoAbbonamento
{
    /**
     * Un piano a pagamento in vendita, col suo price corrente `price_{codice}`.
     * Se il piano esiste già (è il caso di `saas`) gli si aggiunge solo il price.
     *
     * @param  array<string, mixed>  $attributi
     */
    public static function piano(string $codice, int $centesimi, array $attributi = []): Piano
    {
        $piano = self::pianoSenzaPrice($codice, $centesimi, $attributi);

        self::prezzo($codice, 'price_'.$codice, $centesimi);

        return $piano->refresh();
    }

    /**
     * Un piano a listino senza nessun price su Stripe: gratuito, oppure a
     * pagamento e non ancora sincronizzato.
     *
     * @param  array<string, mixed>  $attributi
     */
    public static function pianoSenzaPrice(string $codice, int $centesimi, array $attributi = []): Piano
    {
        $piano = Piano::query()->where('codice', $codice)->first();

        if ($piano === null) {
            $piano = new Piano;
            $piano->forceFill(array_merge([
                'codice' => $codice,
                'etichetta' => Str::title($codice),
                'max_enti' => 10,
                'gratuito' => false,
                'prezzo_mensile_cent' => $centesimi,
                'valuta' => 'eur',
                'attivo' => true,
                // L'ordine del listino segue il prezzo: è l'ordine in cui le
                // offerte devono uscire, e un test lo asserisce. Il tetto sta
                // sotto lo `smallint` di Postgres, dove gira la CI.
                'ordine' => (int) min(30000, $centesimi / 100),
            ], $attributi))->save();
        } elseif ($attributi !== []) {
            $piano->forceFill($attributi)->save();
        }

        self::dimentica();

        return $piano;
    }

    /** Una riga di `prezzi_piano`: il price corrente, o uno storico. */
    public static function prezzo(string $codice, string $priceId, int $centesimi, bool $corrente = true): void
    {
        $piano = Piano::query()->where('codice', $codice)->firstOrFail();

        if ($corrente) {
            $piano->prezzi()->where('corrente', true)->update(['corrente' => false]);
        }

        $piano->prezzi()->create([
            'stripe_price_id' => $priceId,
            'importo_cent' => $centesimi,
            'valuta' => 'eur',
            'corrente' => $corrente,
        ]);

        self::dimentica();
    }

    /**
     * Una riga in `subscriptions` scritta a mano: la suite non parla con
     * Stripe, e `Laravel\Cashier\Subscription` vive in `vendor/`, dove il
     * progetto non ha fixture proprie.
     */
    public static function abbonamento(Account $account, string $priceId, string $stato = 'active', ?string $endsAt = null): string
    {
        $stripeId = 'sub_'.Str::lower(Str::random(12));

        DB::table('subscriptions')->insert([
            'account_id' => $account->getKey(),
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => $stato,
            'stripe_price' => $priceId,
            'quantity' => 1,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $stripeId;
    }

    /**
     * L'account su un piano a pagamento, col customer e l'abbonamento che lo
     * reggono: è lo stato in cui lo lascia un pagamento andato a buon fine.
     */
    public static function abbona(Account $account, string $piano, string $stato = 'active', ?string $price = null): string
    {
        $account->forceFill([
            'piano' => $piano,
            'stripe_id' => $account->stripe_id ?? 'cus_test_'.$account->getKey(),
        ])->save();

        return self::abbonamento($account, $price ?? 'price_'.$piano, $stato);
    }

    public static function stripeFinto(): AbbonamentoStripeFinto
    {
        $finto = new AbbonamentoStripeFinto;

        app()->instance(AbbonamentoStripe::class, $finto);

        return $finto;
    }

    public static function dimentica(): void
    {
        app(CatalogoPiani::class)->dimentica();
    }
}

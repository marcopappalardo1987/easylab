<?php

namespace Tests\Support;

use App\Models\Piano;
use App\Models\PrezzoPiano;
use App\Support\Listino\CatalogoPiani;
use App\Support\Registrazione\PortaleCheckout;
use Illuminate\Support\Str;
use Tests\Fakes\PortaleCheckoutFinto;

/**
 * Il banco del self-signup pubblico (🔗 ADR-012, ADR-032, ADR-035).
 *
 * ⚠️ **Esiste perché una registrazione non parte da un database appena
 * migrato.** `phpunit.xml` azzera `STRIPE_PRICE_SAAS`, quindi la migration di
 * backfill del listino crea i due piani **senza nessuna riga in
 * `prezzi_piano`**: `PianiRegistrabili::codici()` esce vuoto e il modulo
 * risponde «chiuso per guasto» — cioè ogni test del percorso felice sarebbe
 * verde per la ragione sbagliata, o rosso senza dire perché. Qui il prezzo si
 * mette una volta sola, e con esso il `dimentica()` del memo per-richiesta di
 * `CatalogoPiani`, che il fixture deve fare da sé (le azioni della cabina lo
 * fanno da sole, una scrittura a mano no).
 *
 * ⛔ **Una classe e non funzioni globali in un file di test.** I quattro file
 * di questo blocco girano nella stessa suite: due `function apri()` dichiarate
 * in due file sarebbero un fatal, non un fallimento — la cicatrice che
 * `PortaleCheckoutFinto` cita già col nome di `snapshotDa()`.
 */
final class BancoRegistrazione
{
    /** Il price id finto del piano `saas`, l'unico vendibile in suite. */
    public const PRICE_SAAS = 'price_saas_finto';

    /**
     * Il modulo aperto e un piano davvero vendibile, con la porta verso Stripe
     * sostituita da una finta che conta le chiamate.
     */
    public static function apri(): PortaleCheckoutFinto
    {
        config(['easylab.registrazione.aperta' => true]);

        self::listinoVendibile();

        return self::portaleFinto();
    }

    /** Dà al piano `saas` un price corrente, cioè lo rende offribile al pubblico. */
    public static function listinoVendibile(string $codice = 'saas', string $price = self::PRICE_SAAS): void
    {
        $piano = Piano::query()->where('codice', $codice)->firstOrFail();

        PrezzoPiano::query()->create([
            'piano_id' => $piano->getKey(),
            'stripe_price_id' => $price,
            'importo_cent' => $piano->prezzo_mensile_cent,
            'valuta' => 'EUR',
            'corrente' => true,
        ]);

        self::dimentica();
    }

    /**
     * Un secondo piano vendibile, per i test che devono archiviarne uno e
     * lasciare comunque qualcosa in vetrina: senza, «piano archiviato» e
     * «niente da vendere» sarebbero lo stesso caso e il test proverebbe l'altro.
     *
     * `forceFill`: `codice` e `gratuito` sono fuori dal `$fillable` di `Piano`
     * perché immutabili dopo la creazione (ADR-035).
     */
    public static function pianoDiProva(string $codice = 'pro', int $centesimi = 9900): void
    {
        $piano = new Piano;
        $piano->forceFill([
            'codice' => $codice,
            'etichetta' => Str::title($codice),
            'max_enti' => 10,
            'gratuito' => false,
            'prezzo_mensile_cent' => $centesimi,
            'valuta' => 'EUR',
            'attivo' => true,
            'ordine' => 99,
        ])->save();

        self::dimentica();
        self::listinoVendibile($codice, 'price_'.$codice.'_finto');
    }

    /** Archivia un piano: resta a catalogo (ADR-035) ma non si vende più. */
    public static function archivia(string $codice): void
    {
        Piano::query()->where('codice', $codice)->firstOrFail()->update(['attivo' => false]);

        self::dimentica();
    }

    /** Toglie del tutto un piano dal catalogo: non esiste più nemmeno per chi ci sta sopra. */
    public static function cancellaDalCatalogo(string $codice): void
    {
        $piano = Piano::query()->where('codice', $codice)->firstOrFail();
        $piano->prezzi()->delete();
        $piano->delete();

        self::dimentica();
    }

    /** La finta di Stripe, registrata nel container al posto della porta vera. */
    public static function portaleFinto(): PortaleCheckoutFinto
    {
        $finto = new PortaleCheckoutFinto;

        app()->instance(PortaleCheckout::class, $finto);

        return $finto;
    }

    /** Il memo per-richiesta del listino, che in un test la richiesta non chiude mai. */
    public static function dimentica(): void
    {
        app(CatalogoPiani::class)->dimentica();
    }
}

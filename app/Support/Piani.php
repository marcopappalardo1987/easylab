<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Accesso tipizzato al catalogo dei piani (config/easylab.php → `piani`).
 *
 * Stesso patto di App\Support\Rbac ↔ config/rbac.php e di App\Support\Semaforo
 * ↔ `easylab.semaforo`: la config è la fonte, questa classe è l'unica porta.
 * Chi vuole sapere quanti Enti può avere un account non legge `config()`, che
 * fra sei mesi sarebbe scritto in quattro posti con quattro default diversi.
 *
 * 🔗 ADR-032 (il limite di Enti è un attributo del piano, fatto rispettare al
 * provisioning e non nello scope), ADR-002 (Free omaggiato vs SaaS a pagamento).
 *
 * **Perché i getter lanciano invece di ripiegare su un default.** Un piano che
 * non esiste nel catalogo è un dato corrotto — `accounts.piano` è fuori
 * `$fillable` e lo scrive solo `Account::cambiaPiano()`, che valida. Un `?? 1`
 * silenzioso trasformerebbe la corruzione in un limite sbagliato applicato a un
 * cliente vero, cioè nel genere di errore che si scopre dal reclamo.
 */
class Piani
{
    /** I codici a catalogo, nell'ordine in cui sono dichiarati. */
    public static function codici(): array
    {
        return array_keys(config('easylab.piani.catalogo', []));
    }

    public static function esiste(string $piano): bool
    {
        return array_key_exists($piano, config('easylab.piani.catalogo', []));
    }

    /** Il piano con cui nasce un account che non ne ha scelto uno. */
    public static function predefinito(): string
    {
        return config('easylab.piani.predefinito', 'free');
    }

    public static function etichetta(string $piano): string
    {
        return self::attributo($piano, 'etichetta');
    }

    /**
     * Quanti Enti può avere un account su questo piano.
     *
     * `null` significa **illimitato**, non «non lo so»: nessun piano V1 lo usa,
     * ma è la forma che un piano Enterprise avrebbe, e dichiararla ora evita
     * che domani la si esprima con un numero grande a caso.
     */
    public static function maxEnti(string $piano): ?int
    {
        $max = self::attributo($piano, 'max_enti');

        return $max === null ? null : (int) $max;
    }

    public static function stripePrice(string $piano): ?string
    {
        return self::attributo($piano, 'stripe_price');
    }

    /**
     * Il prezzo di **listino** del piano, in centesimi interi.
     *
     * Alimenta l'MRR della dashboard di piattaforma (S6). Centesimi e non
     * euro-float: un totale su decine di clienti in virgola mobile accumula
     * errore, e nessuno rilegge la riga che lo fa.
     *
     * ⚠️ È il listino, non l'incassato: lo stesso prezzo vive su Stripe e i due
     * possono divergere (promozioni, prezzi storici, modifiche in dashboard).
     * Il commento in `config/easylab.php` lo dice per esteso, e la pagina che
     * mostra l'MRR lo ripete a chi legge.
     *
     * Come gli altri getter, **lancia** su un piano fuori catalogo. Chi lo
     * consuma in una vista aggregata non deve però propagare l'eccezione: la
     * dashboard di piattaforma itera su `codici()` e raccoglie il resto in una
     * voce «piano sconosciuto», perché è l'unica schermata da cui si
     * ripareprebbe un `accounts.piano` corrotto — e morire proprio lì sarebbe
     * il modo peggiore di segnalarlo.
     */
    public static function prezzoMensileCent(string $piano): int
    {
        return (int) self::attributo($piano, 'prezzo_mensile_cent');
    }

    /**
     * Un piano **omaggiato**: nessun customer Stripe, nessuna subscription
     * (ADR-002 — il Free è a fronte di un contratto di manutenzione fisico,
     * fatturato fuori dal software).
     *
     * ⚠️ È una dichiarazione del catalogo e non l'assenza di `stripe_price`, e
     * la differenza si vede in `easylab:abbona`: un piano a pagamento con il
     * price id non configurato è un **errore di deploy** da segnalare per nome,
     * non un piano gratuito. Dedurre la gratuità dal prezzo mancante li
     * confonderebbe, e la variabile dimenticata resterebbe invisibile dietro un
     * messaggio rassicurante.
     */
    public static function eGratuito(string $piano): bool
    {
        return (bool) self::attributo($piano, 'gratuito');
    }

    /** L'inverso di `eGratuito()`: questo piano passa da Stripe. */
    public static function richiedeStripe(string $piano): bool
    {
        return ! self::eGratuito($piano);
    }

    /**
     * Il piano che corrisponde a un price id di Stripe, o `null` se quel price
     * non è a catalogo.
     *
     * Serve al webhook per riallineare `accounts.piano` a ciò che il cliente ha
     * davvero su Stripe. `null` è un esito legittimo e non un errore: un price
     * creato a mano in dashboard, o un piano dismesso dal catalogo, non deve
     * far esplodere un handler di webhook — chi chiama decide cosa farne.
     */
    public static function perPrice(?string $priceId): ?string
    {
        if ($priceId === null) {
            return null;
        }

        foreach (config('easylab.piani.catalogo', []) as $codice => $piano) {
            if (($piano['stripe_price'] ?? null) === $priceId) {
                return $codice;
            }
        }

        return null;
    }

    private static function attributo(string $piano, string $chiave): mixed
    {
        $definizione = config("easylab.piani.catalogo.{$piano}");

        if ($definizione === null) {
            throw new InvalidArgumentException(
                "Piano «{$piano}» non a catalogo. Piani validi: ".implode(', ', self::codici()).'.'
            );
        }

        // `array_key_exists` e non `?? null`: `null` è un valore **dichiarato**
        // legittimo (`stripe_price` del Free, `max_enti` illimitato), mentre una
        // chiave **assente** è un piano scritto a metà. Confonderli è come il
        // `?? 0` che questa classe rifiuta in ogni suo getter: trasformerebbe un
        // catalogo incompleto in un piano da zero euro, in silenzio, dentro una
        // somma di denaro. Trovato dal confronto sul blocco A di S6.
        if (! array_key_exists($chiave, $definizione)) {
            throw new InvalidArgumentException(
                "Il piano «{$piano}» non dichiara «{$chiave}»: il catalogo in config/easylab.php è incompleto."
            );
        }

        return $definizione[$chiave];
    }
}

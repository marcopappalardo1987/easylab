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

        return $definizione[$chiave] ?? null;
    }
}

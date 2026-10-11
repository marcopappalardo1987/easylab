<?php

namespace App\Support\Listino;

/**
 * Le parole che il cliente non deve mai leggere (🔗 ADR-050; ADR-002 il piano
 * senza abbonamento).
 *
 * Il piano senza canone di Easy Lab non è un regalo: accompagna un contratto di
 * manutenzione, ed è dato in **comodato d'uso**. Davanti al cliente «free»,
 * «gratis» e «gratuito» non si scrivono, da nessuna parte (decisione di Marco
 * del 10 Ott 2026).
 *
 * Qui sta la definizione, in un posto solo: la usa il listino per rifiutare
 * un'etichetta, e la usano i guardrail che leggono viste e sorgenti. `free`
 * resta il **codice** del piano in `accounts.piano`, come `gratuito` resta il
 * nome della colonna: sono identificatori, e il cliente non li vede.
 *
 * Questo è il solo file dell'applicazione in cui quelle parole compaiono in una
 * frase: per dire quali sono.
 */
final class LessicoCommerciale
{
    /** Come si chiama un piano senza canone, ovunque lo si nomini. */
    public const COMODATO = "comodato d'uso";

    /** Parole intere, maiuscole o no: «freezer» e «Freemium» non sono «free». */
    public const VIETATE = '/\b(free|gratis|gratuit\w*)\b/iu';

    public const ETICHETTA_RIFIUTATA = "L'etichetta è ciò che il cliente legge: «free», «gratis» e «gratuito» non si usano. Un piano senza canone è in comodato d'uso.";

    public static function vietato(string $testo): bool
    {
        return preg_match(self::VIETATE, $testo) === 1;
    }
}

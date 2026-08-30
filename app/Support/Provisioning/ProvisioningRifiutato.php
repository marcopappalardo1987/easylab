<?php

namespace App\Support\Provisioning;

use RuntimeException;

/**
 * Il provisioning è stato rifiutato **prima di scrivere qualunque cosa**.
 *
 * Esiste come eccezione e non come valore di ritorno perché i due chiamanti —
 * il comando di console e la cabina di regia — hanno due modi diversi di dirlo
 * (stderr e un errore di form) ma la stessa identica regola. Il messaggio è già
 * in italiano e già destinato a un umano: chi la cattura lo mostra, non lo
 * reinterpreta.
 *
 * ⚠️ **Il `codice` esiste perché il messaggio non è un'API.** Il comando
 * aggiungeva una riga di aiuto facendo `str_contains($messaggio, 'specificare
 * quale')`: riformulare quella frase — cosa che è successa il giorno stesso in
 * cui la cabina ha reso quel messaggio azionabile anche da UI — spegneva il
 * suggerimento **senza rompere niente e senza dirlo**. Chi deve ramificare
 * ramifica sul codice; il testo resta libero di migliorare.
 */
class ProvisioningRifiutato extends RuntimeException
{
    public const ACCOUNT_INESISTENTE = 'account_inesistente';

    public const AMBIGUO = 'ambiguo';

    public const GIA_AMMINISTRA = 'gia_amministra';

    public const PIANO_FUORI_CATALOGO = 'piano_fuori_catalogo';

    public const LIMITE_RAGGIUNTO = 'limite_raggiunto';

    /**
     * L'email appartiene a una persona **cestinata** (🔗 ADR-038).
     *
     * ⛔ Non si riusa e non si ripristina di nascosto: `users.email` è unique
     * senza condizione, quindi la riga cestinata occupa quell'indirizzo e un
     * `firstOrCreate` cieco sbatterebbe sull'unique — un 500 raggiungibile
     * dalla superficie pubblica. Ma nemmeno si resuscita per effetto
     * collaterale: rimettere in servizio una persona è un gesto che qualcuno
     * deve fare **guardandolo**, dalla schermata Utenti.
     */
    public const UTENTE_CESTINATO = 'utente_cestinato';

    public function __construct(string $messaggio, public readonly string $codice)
    {
        parent::__construct($messaggio);
    }
}

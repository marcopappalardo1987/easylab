<?php

namespace App\Support\Utenti;

use App\Models\User;
use RuntimeException;

/**
 * L'invito è stato rifiutato **prima di scrivere qualunque cosa** (🔗 ADR-038).
 *
 * Gemello di `App\Support\Provisioning\ProvisioningRifiutato`, e con la stessa
 * postura: eccezione e non valore di ritorno, perché i chiamanti hanno modi
 * diversi di dirlo — un errore di campo, una riga in console — ma la stessa
 * identica regola. Il messaggio è già in italiano e già destinato a un umano.
 *
 * ⚠️ **Il `codice` esiste perché il messaggio non è un'API.** Chi deve
 * ramificare — «offro il ripristino?» — ramifica su quello; il testo resta
 * libero di migliorare senza spegnere un comportamento in silenzio. È la
 * lezione già pagata dal comando di provisioning, che ramificava con
 * `str_contains()` su una frase poi riscritta.
 */
class InvitoRifiutato extends RuntimeException
{
    /** L'indirizzo è di una persona **attiva**: non si invita due volte. */
    public const EMAIL_GIA_USATA = 'email_gia_usata';

    /**
     * L'indirizzo è di una persona **cestinata**.
     *
     * ⛔ Non si riusa e non si ripristina di nascosto: rimettere in servizio
     * qualcuno è un gesto che si fa guardandolo. Chi cattura questo codice
     * **offre il ripristino**, non fallisce e basta.
     */
    public const UTENTE_CESTINATO = 'utente_cestinato';

    /** Il ruolo non è conferibile da quell'interfaccia (🔗 `RuoliAssegnabili`). */
    public const RUOLO_NON_AMMESSO = 'ruolo_non_ammesso';

    public function __construct(string $messaggio, public readonly string $codice)
    {
        parent::__construct($messaggio);
    }

    /**
     * Il rifiuto giusto per un'email già in tabella, coi due casi distinti.
     *
     * Distinguerli è il punto: «esiste già» manda a cercare una persona che in
     * elenco **non c'è**, perché il cestino la nasconde. Il messaggio deve dire
     * dov'è finita, o chi legge cerca un difetto.
     */
    public static function per(User $utente, string $email): self
    {
        return $utente->trashed()
            ? new self(
                "L'indirizzo {$email} appartiene a una persona cestinata: ripristinala invece di crearne una nuova.",
                self::UTENTE_CESTINATO,
            )
            : new self(
                "L'indirizzo {$email} è già di una persona di questa piattaforma.",
                self::EMAIL_GIA_USATA,
            );
    }
}

<?php

namespace App\Support\Utenti;

use DomainException;

/**
 * Un'assegnazione di laboratori che le regole non ammettono (🔗 ADR-057).
 *
 * È un rifiuto **di dominio**, con un messaggio fatto per essere letto da chi
 * ha compiuto il gesto: la schermata lo mostra accanto al campo. Non è un 403:
 * chi arriva qui il permesso ce l'ha, ed è ciò che ha chiesto a non reggere.
 */
final class AssegnazioneRifiutata extends DomainException
{
    public static function nonResponsabile(): self
    {
        return new self('I laboratori si assegnano solo a chi ha il ruolo Responsabile Reparto.');
    }

    public static function laboratorioEstraneo(): self
    {
        return new self('Uno dei laboratori scelti non è di questo Ente, o non esiste più: ricarica la pagina e riprova.');
    }
}

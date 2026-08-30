<?php

namespace App\Support\Utenti;

use App\Models\User;

/**
 * Cos'è successo davvero quando si invita una persona (🔗 ADR-038).
 *
 * Gemello di `App\Support\Provisioning\EsitoProvisioning`, e per la stessa
 * ragione: ⚠️ **`invitoAccodato`, non «inviato»**. `InvitoUtente` è
 * `ShouldQueue` dal 21 Ago 2026, quindi chi chiama consegna il messaggio alla
 * coda e **non può più sapere** se è partito — dire «inviato» sarebbe una bugia
 * non più verificabile, proprio sul gesto la cui unica prova, per chi lo compie,
 * è la frase che legge subito dopo.
 *
 * `invitoFallito` non è un errore dell'invito: la persona è stata creata e le
 * scritture sono committate. Ripetere il gesto riprova a consegnare.
 */
final class EsitoInvito
{
    public function __construct(
        public readonly User $utente,
        public readonly bool $utenteNuovo,
        public readonly bool $invitoAccodato = false,
        public readonly ?string $invitoFallito = null,
    ) {}
}

<?php

namespace App\Support\Provisioning;

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;

/**
 * Cos'è successo davvero, per chi deve raccontarlo (console o UI).
 *
 * ⚠️ **`invitoAccodato`, non «inviato», e il nome è la decisione.** Dal 21 Ago
 * 2026 `InvitoUtente` è `ShouldQueue`: chi chiama consegna il messaggio alla
 * coda e **non può più sapere** se è partito davvero. Dire «inviato» sarebbe
 * una bugia non più verificabile da nessuna parte — il difetto peggiore
 * possibile su un gesto la cui unica prova, per l'operatore, è la frase che
 * legge. Il campo è stato rinominato apposta: ogni chiamante ha dovuto
 * riscrivere la propria frase invece di ereditarne una diventata falsa.
 *
 * *Con `QUEUE_CONNECTION=sync` — locale e test — il dispatch è sincrono e
 * «accodato» significa «davvero inviato»: il campo resta onesto in entrambi i
 * mondi, perché afferma la sola cosa che il chiamante sa in tutti e due.*
 *
 * `invitoFallito` non è un errore del provisioning: le scritture sono
 * committate, e ripetere il gesto riprova. Resta valorizzato quando il
 * fallimento è **visibile al chiamante**, cioè in modalità sincrona o se è la
 * consegna alla coda a fallire; con la coda attiva un fallimento successivo lo
 * racconta `InvitoUtente::failed()`, sul log di audit.
 */
final class EsitoProvisioning
{
    public function __construct(
        public readonly UnitaOrganizzativa $ente,
        public readonly User $admin,
        public readonly Account $account,
        public readonly bool $accountNuovo,
        public readonly bool $adminNuovo,
        public readonly bool $invitoAccodato = false,
        public readonly ?string $invitoFallito = null,
    ) {}
}

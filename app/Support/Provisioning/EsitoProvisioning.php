<?php

namespace App\Support\Provisioning;

use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;

/**
 * Cos'è successo davvero, per chi deve raccontarlo (console o UI).
 *
 * `invitoFallito` non è un errore del provisioning: le scritture sono
 * committate: rilanciare lo stesso gesto reinvia. Distinguerlo da
 * `invitoInviato` serve a non far sembrare fallito ciò che è a DB — che è
 * esattamente il messaggio che il comando dava già, e che la UI dovrà dare
 * uguale invece di reinventarlo.
 */
final class EsitoProvisioning
{
    public function __construct(
        public readonly UnitaOrganizzativa $ente,
        public readonly User $admin,
        public readonly Account $account,
        public readonly bool $accountNuovo,
        public readonly bool $adminNuovo,
        public readonly bool $invitoInviato = false,
        public readonly ?string $invitoFallito = null,
    ) {}
}

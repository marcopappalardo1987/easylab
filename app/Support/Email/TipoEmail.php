<?php

namespace App\Support\Email;

/**
 * Una voce del catalogo delle email (🔗 ADR-047).
 *
 * Dice di un'email ciò che serve a tre posti diversi senza che nessuno dei tre
 * lo riscriva: la pagina Piattaforma → Email (nome, quando, a chi,
 * interruttore), `via()` delle notifiche (accesa? la persona la vuole?) e la
 * pagina delle preferenze (quale colonna la spegne per sé).
 */
final readonly class TipoEmail
{
    /**
     * @param  bool  $sospendibile  ha un interruttore di piattaforma. Le email di
     *                              servizio (invito, recupero password…) no:
     *                              spegnerle romperebbe l'accesso.
     * @param  bool  $nataAccesa  lo stato finché nessuno tocca l'interruttore.
     * @param  ?string  $preferenza  la colonna di `users` con cui il destinatario
     *                               la spegne per sé; `null` = non si può.
     */
    public function __construct(
        public string $chiave,
        public string $nome,
        public string $quando,
        public string $aChi,
        public bool $sospendibile = false,
        public bool $nataAccesa = true,
        public ?string $preferenza = null,
    ) {}
}

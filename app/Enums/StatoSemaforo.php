<?php

namespace App\Enums;

/**
 * Stato semaforo (ADR-005). `Rosso` esiste già nell'enum ma il motore
 * calcolato NON lo produce mai: arriva solo dalla forzatura manuale (punto 5,
 * colonne forced_*). La presentazione (colore + forma + etichetta) vive in
 * x-ui.semaforo, non qui: gli enum del progetto restano nudi.
 */
enum StatoSemaforo: string
{
    case Verde = 'verde';
    case Arancione = 'arancione';
    case Rosso = 'rosso';

    /**
     * Come si chiama lo stato per chi legge (Design System §4): le stesse tre
     * parole del componente `x-ui.semaforo`.
     *
     * ⚠️ Le viste hanno ancora le loro copie di queste etichette: questo
     * metodo è nato il 9 Ott 2026 per le email (🔗 ADR-047), che non passano
     * da un componente Blade. Chi ne cambia una qui le cambi anche là.
     */
    public function etichetta(): string
    {
        return match ($this) {
            self::Verde => 'In regola',
            self::Arancione => 'Azione richiesta',
            self::Rosso => 'Non idoneo',
        };
    }
}

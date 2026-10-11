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
     * Come si chiama lo stato di **una** macchina, per chi legge
     * (🔗 ADR-052; Design System §4).
     *
     * Le diciture sono di Marco, del 10 Ott 2026, e sostituiscono «In regola»,
     * «Azione richiesta» e «Non idoneo». Questo metodo e `etichettaInsieme()`
     * sono la **sola** fonte: il componente `x-ui.semaforo`, i filtri degli
     * elenchi, la forzatura e le email leggono da qui. Fino a quel giorno ogni
     * vista ne aveva una copia, e cambiarle voleva dire cercarle una per una.
     */
    public function etichetta(): string
    {
        return match ($this) {
            self::Verde => 'Strumentazione idonea',
            self::Arancione => 'Interventi necessari',
            self::Rosso => 'Strumento non idoneo',
        };
    }

    /**
     * Come si chiama l'**insieme** delle macchine in questo stato: i riquadri
     * della dashboard, le voci dei filtri, le legende.
     *
     * Cambia solo il rosso, che lì è un plurale: «Strumenti non idonei» sopra
     * un conteggio, «Strumento non idoneo» sulla scheda di una macchina.
     */
    public function etichettaInsieme(): string
    {
        return $this === self::Rosso ? 'Strumenti non idonei' : $this->etichetta();
    }
}

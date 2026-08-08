<?php

namespace App\Support;

use App\Enums\StatoSemaforo;

/**
 * Esito del motore semaforo (ADR-024): non più solo uno stato, ma lo stato
 * **e i motivi che lo determinano**.
 *
 * **Lo stato è derivato nel costruttore, mai iniettato.** È la scelta che porta
 * tutto il peso: l'invariante «arancione ⇔ almeno un motivo» diventa
 * strutturale invece che asserita, quindi non esiste modo di costruire una
 * diagnosi arancione senza spiegazione né una verde che nasconde un motivo.
 * È anche il motivo per cui il Rosso resta irraggiungibile da qui: non compare
 * in questa derivazione, e continua ad arrivare solo dalla forzatura manuale
 * (ADR-005).
 *
 * I motivi arrivano già filtrati da `Semaforo::diagnostica()`: qui dentro non
 * si confronta nessuna data con la soglia — quel confronto vive in un posto
 * solo, il motore.
 */
final class DiagnosiSemaforo
{
    public readonly StatoSemaforo $stato;

    /** @param  list<MotivoSemaforo>  $motivi  già filtrati e ordinati per scadenza */
    public function __construct(public readonly array $motivi)
    {
        $this->stato = $motivi === [] ? StatoSemaforo::Verde : StatoSemaforo::Arancione;
    }
}

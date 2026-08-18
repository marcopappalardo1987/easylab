<?php

namespace App\Enums;

/**
 * Il passaggio di stato che ha meritato un avviso (ADR-011, scheduler S5).
 *
 * Non è lo stato della scadenza ma il suo *cambio*: l'email del futuro parte
 * quando qualcosa diventa imminente e di nuovo quando scade, non ogni giorno in
 * cui resta tale. È per questo che la memoria anti-duplicati (`avvisi_scadenza`)
 * è indicizzata su questo enum e non su uno `StatoSemaforo`: una scadenza
 * attraversa entrambe le transizioni una volta sola.
 *
 * String-backed come TipoMotivoSemaforo, e per lo stesso motivo: finisce nel
 * payload serializzato della notifica.
 */
enum TransizioneAvviso: string
{
    case Imminente = 'imminente';
    case Scaduta = 'scaduta';
}

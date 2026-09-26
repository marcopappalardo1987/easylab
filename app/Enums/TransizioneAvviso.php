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
 *
 * 🔴 **`Obsoleta` non è della stessa specie delle altre due, e la differenza
 * governa il ciclo di vita della riga di log** (🔗 ADR-014, 27 Ago 2026).
 * `Imminente` e `Scaduta` sono transizioni di una SCADENZA: un fatto avvenuto,
 * che il tempo non disfa — una scadenza superata resta superata per sempre, e la
 * sua riga in `avvisi_scadenza` non si cancella mai. `Obsoleta` è invece la
 * transizione di uno STATO DERIVATO da una soglia **mutabile per Ente**
 * (`soglia_obsolescenza_anni`): se l'Admin la rialza, la macchina torna sotto
 * la linea e l'avviso registrato va **rimosso**, o non potrebbe mai essere
 * riavvisata quando la soglia riscendesse. È l'unica transizione la cui riga si
 * cancella — vedi `App\Support\Notifiche\AvvisiObsolescenza` e il docblock di
 * `App\Models\AvvisoScadenza`.
 *
 * ⚠️ **E non è un `TipoMotivoSemaforo`**: l'obsolescenza NON è un motivo del
 * semaforo (ADR-024), e infilarcela farebbe esplodere con `UnhandledMatchError`
 * i `match` esaustivi di `mail/digest-scadenze.blade.php` e
 * `strumenti/_panoramica.blade.php` — nel corpo di un'email, dove nessun test
 * di lista se ne accorgerebbe. Su QUESTO enum, invece, nessun `match` esaustivo
 * insiste: verificato prima di aggiungere il caso.
 */
enum TransizioneAvviso: string
{
    case Imminente = 'imminente';
    case Scaduta = 'scaduta';
    case Obsoleta = 'obsoleta';
}

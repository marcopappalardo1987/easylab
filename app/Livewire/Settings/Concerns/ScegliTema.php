<?php

namespace App\Livewire\Settings\Concerns;

use App\Enums\TemaUtente;
use Illuminate\Validation\ValidationException;

/**
 * La scelta del tema dell'utente autenticato (🔗 ADR-034 — DS §8.3).
 *
 * ## Perché è un trait, e non due copie
 *
 * Fino al 26 Ago 2026 questo metodo viveva dentro `PreferenzeNotifiche` e basta,
 * perché il consumatore era **uno solo**. Con la scorciatoia in top bar (F3) i
 * consumatori diventano due — la pagina Preferenze e `SelettoreTema` — e due
 * copie della stessa guardia **divergono**: il giorno in cui una delle due
 * dimenticasse `tryFrom` non ci sarebbe nessun sintomo visibile, solo un
 * endpoint in più che scrive nella colonna ciò che il client gli manda. Questo
 * progetto ha già pagato la divergenza fra due copie più volte (le due
 * definizioni di «documenti della macchina», le due di «casella vuota»): qui la
 * si evita prima invece di correggerla dopo.
 *
 * ⚠️ **Il valore si converte con `tryFrom` prima di toccare la colonna.** Un
 * componente Livewire è un endpoint a tutti gli effetti: il parametro arriva dal
 * client e non dai tre bottoni resi dal server. `from()` lancerebbe un
 * `ValueError` (500), un cast cieco scriverebbe. La colonna ha un `CHECK` a
 * database che rifiuterebbe comunque, ma un vincolo che scatta è un errore di
 * sistema, non una risposta — e su SQLite e Postgres non ha nemmeno lo stesso
 * testo.
 *
 * ⛔ **L'identità viene da `auth()`, mai da un parametro.** Non esiste una firma
 * in cui si possa nominare *un altro utente*: è il solo modo per cui «cambiare
 * il tema di qualcun altro» non è una richiesta esprimibile, invece che una
 * richiesta respinta da un controllo che si può dimenticare. Un test misura il
 * **numero di parametri** del metodo proprio per questo — l'assenza è la
 * garanzia, e un `$utente` aggiunto per comodità la toglierebbe in silenzio.
 *
 * ⚠️ **`forceFill` e non `fill`**: `tema` sta fuori dall'attributo `Fillable` di
 * `User`, come `tenant_id` (ADR-032) e `riceve_email_scadenze` (ADR-029). La
 * preferenza di una persona non deve poter arrivare dal form di un'altra.
 *
 * **Nessun «Salva»**, ed è deliberato: Alpine ha già cambiato il colore della
 * pagina nel millisecondo del click. Se il database restasse indietro fino a un
 * bottone, il primo ricaricamento riporterebbe il tema di prima — cioè
 * l'interruttore sembrerebbe aver dimenticato, che è peggio del non averlo.
 */
trait ScegliTema
{
    public function scegliTema(string $tema): void
    {
        $scelto = TemaUtente::tryFrom($tema);

        if ($scelto === null) {
            throw ValidationException::withMessages([
                'tema' => 'Tema non riconosciuto.',
            ]);
        }

        auth()->user()->forceFill(['tema' => $scelto])->save();
    }
}

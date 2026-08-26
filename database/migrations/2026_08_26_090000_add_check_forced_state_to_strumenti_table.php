<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 `strumenti.forced_state` accetta solo i tre valori dell'enum, o NULL
 * (🔗 ADR-005 — S6, dopo le dashboard per ruolo).
 *
 * ## Perché un vincolo di schema e non una guardia in più
 *
 * La colonna nasce `string` nullable **senza CHECK** (migration del 2 Ago 2026),
 * e finora l'invariante era un'ipotesi sui dati sostenuta dalla forma del
 * codice: `forced_state` sta fuori da `$fillable`, e l'unica via di scrittura è
 * `Strumento::forzaSemaforo()`. Ha retto, e il conteggio lo conferma — 5.105
 * strumenti sul database di sviluppo, 16 forzati, **zero** fuori enum.
 *
 * ⚠️ **Ma da S6 quell'ipotesi porta un peso che prima non aveva.** I quattro
 * numeri della dashboard poggiano sulla **partizione**: verde + arancione +
 * rosso = tutto il parco. Con un valore fuori enum le tre letture del progetto
 * rispondono **tre cose diverse** — il filtro (`scopeConStato`) fa sparire la
 * riga da tutti e tre gli insiemi, l'ordinamento (`scopeOrdinaPerStato`) la
 * tratta come **verde** per via del ramo `else 0`, e il calcolo per-model
 * **lancia** un `ValueError` sul cast dell'enum. Una riga sola basterebbe a far
 * dire alla dashboard un numero plausibile e sbagliato.
 *
 * Ciò da cui il vincolo protegge non è l'applicazione: è **una migration di
 * correzione o un import**, che scrivono con il query builder e non incontrano
 * né `$fillable` né gli eventi del model. È la stessa forma di rischio già
 * annotata su `unita_organizzativa.tipo` — «una guardia sul modello, non un
 * CHECK: il suo stesso docblock dice che lo schema sarebbe stata la sede
 * giusta» — e qui la sede giusta si prende.
 *
 * ## Su SQLite non si applica, e va detto
 *
 * ⚠️ SQLite **non sa** aggiungere un vincolo a una tabella esistente: non
 * esiste `ALTER TABLE … ADD CONSTRAINT`, e l'unica strada sarebbe ricostruire
 * la tabella. Qui si salta, e la conseguenza è concreta invece che teorica: la
 * suite in locale gira su SQLite (`phpunit.xml`), quindi **in locale
 * l'invariante non c'è** — mentre in CI, che gira su Postgres, c'è. I due test
 * che toccano questo confine si escludono a vicenda per driver, e ciascuno dice
 * nel proprio nome quale metà del mondo sta descrivendo.
 *
 * Additiva e reversibile: `php artisan migrate` in avanti, e il `down()` toglie
 * il solo vincolo senza toccare un dato.
 */
return new class extends Migration
{
    private const NOME = 'strumenti_forced_state_check';

    public function up(): void
    {
        if ($this->nonSupportato()) {
            return;
        }

        // ⚠️ I valori si scrivono per esteso e **non** si derivano da
        // `StatoSemaforo::cases()`: una migration è una fotografia dello schema
        // al giorno in cui è stata scritta, e deve continuare a produrre lo
        // stesso DDL anche quando l'enum cambierà. Derivarla farebbe dire a una
        // migration già eseguita una cosa diversa da quella che ha eseguito.
        DB::statement(
            'alter table strumenti add constraint '.self::NOME.
            " check (forced_state is null or forced_state in ('verde', 'arancione', 'rosso'))"
        );
    }

    public function down(): void
    {
        if ($this->nonSupportato()) {
            return;
        }

        DB::statement('alter table strumenti drop constraint '.self::NOME);
    }

    /** SQLite non aggiunge vincoli a una tabella che esiste già. */
    private function nonSupportato(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};

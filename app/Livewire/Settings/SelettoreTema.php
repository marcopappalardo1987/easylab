<?php

namespace App\Livewire\Settings;

use App\Enums\TemaUtente;
use App\Livewire\Settings\Concerns\ScegliTema;
use Livewire\Component;

/**
 * La scorciatoia del tema nel menù utente della top bar (🔗 ADR-034 — DS §8.3).
 *
 * ## Perché esiste un componente Livewire per tre bottoni
 *
 * ⛔ **`wire:click` fuori da Livewire è un attributo inerte.** La tendina del
 * menù utente è markup del layout, non un componente Livewire: montandoci dentro
 * `<x-ui.selettore-tema>` nudo, i tre bottoni non avrebbero chiamato **nessuno**
 * — senza errori, senza console, senza un 500 da leggere. Alpine avrebbe
 * cambiato il colore della pagina e `users.tema` sarebbe rimasta ferma; al
 * caricamento successivo `riallinea()` avrebbe riportato `localStorage` al
 * valore del database, cioè **annullato la scelta** facendola sembrare una
 * dimenticanza del browser. Serve un ospite Livewire, e questo è quell'ospite:
 * il terzo figlio annidato della top bar, dopo `notifiche.campanella` e
 * `tenancy.switcher-ente`.
 *
 * ## Le tre decisioni che questa classe porta
 *
 * 1. **Nessun `mount()`, nessuna property pubblica.** Si monta su ogni pagina di
 *    ogni richiesta (disciplina della Campanella): il costo dev'essere zero
 *    query, e lo è — `auth()->user()` è già in sessione e `tema` è una sua
 *    colonna. Una `public string $tema` costerebbe anche di più: sarebbe
 *    **scrivibile dal client**, e `aria-pressed` — cioè l'unica cosa che dice a
 *    uno screen reader quale tema è attivo — riporterebbe ciò che il browser ha
 *    mandato invece di ciò che il server sa. È la regola del combobox letta
 *    sull'accessibilità.
 * 2. **La guardia sta nel trait**, condiviso con `PreferenzeNotifiche`: due
 *    copie di `tryFrom` divergono, e la copia che diverge è sempre quella che
 *    nessuno rilegge.
 * 3. **Componente inline**, senza un file di vista: sono quattro righe di markup
 *    che montano `<x-ui.selettore-tema>`, e la forma vera del selettore vive già
 *    in quel componente Blade — un secondo file conterrebbe solo un involucro,
 *    cioè un posto in più in cui la stessa cosa può divergere.
 */
class SelettoreTema extends Component
{
    use ScegliTema;

    /**
     * Il tema che il **server** dice essere attivo.
     *
     * ⚠️ **Un metodo e non una property, e la differenza è `aria-pressed`.** Una
     * property pubblica è scrivibile dal client: l'unica cosa che dice a uno
     * screen reader quale dei tre temi è attivo riporterebbe ciò che il browser
     * ha mandato, non ciò che la colonna sa. Qui si rilegge a ogni render, così
     * il morph che segue un click rimette l'evidenziazione su ciò che il
     * database dice davvero — se il valore fosse stato rifiutato, l'anticipo di
     * Alpine tornerebbe indietro da sé.
     *
     * ⚠️ Dev'essere **pubblico** perché una vista Blade lo chiami su `$this`;
     * è una lettura pura e senza parametri, quindi anche invocato come azione
     * non fa altro che rispondere.
     *
     * ⚠️ **`?->` non è pessimismo.** Questo componente vive nel layout dell'area
     * autenticata, dove un ospite non arriva mai; ma `oSistema()` copre già il
     * caso del `User` costruito in memoria che non ha riletto il default dello
     * schema, e far esplodere la **top bar di ogni pagina** per un attributo
     * mancante sarebbe un prezzo fuori scala rispetto a «segui il sistema», che
     * è il default anche a database.
     */
    public function temaCorrente(): TemaUtente
    {
        return TemaUtente::oSistema(auth()->user()?->tema);
    }

    public function render()
    {
        // ⚠️ **L'etichetta è visibile, e non è ridondante con gli `sr-only` del
        // selettore.** Quelli nominano i tre *stati* («Tema chiaro», «Tema
        // scuro», «Tema di sistema»); questa dice di cosa parla la riga a chi
        // guarda tre glifi dentro una tendina. Il nome accessibile del gruppo
        // continua a venire dall'`aria-label` del componente, che non si tocca.
        return <<<'HTML'
        <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-2">
            <span class="text-sm text-ink-2">Tema</span>
            <x-ui.selettore-tema :corrente="$this->temaCorrente()" class="shrink-0" />
        </div>
        HTML;
    }
}

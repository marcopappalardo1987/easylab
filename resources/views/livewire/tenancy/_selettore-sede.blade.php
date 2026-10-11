{{--
    La tendina in cui si sceglie dove si lavora, filtrabile scrivendo
    (🔗 ADR-061). Stato e azioni sono in `SceglieSede`.

    Parametri: `selettore` (da `selettoreSedi()`), `etichetta`, `segnaposto`,
    `aiuto`, `idDom`.

    🔴 «Aperto» lo dice il server, non Alpine: è la regola di
    `TendineLivewireGuardrailTest`. Alpine porta il fuoco nel campo e muove
    l'evidenziazione con le frecce; Invio fa `click()` sul bottone evidenziato,
    quindi la strada per scegliere resta una sola, quella del server.

    Il pannello sta nel flusso del form e non sopra: dentro una modale un
    riquadro in sovrimpressione verrebbe tagliato dal suo bordo.

    ⚠️ Non coperto dai test (non eseguono Alpine): frecce, Invio, Esc, fuoco.
--}}
@php
    use App\Support\Tenancy\SediSeguite;

    $cercato = trim($cercaSede);
@endphp

<div wire:key="selettore-sede-{{ $idDom }}">
    <span id="{{ $idDom }}-etichetta" class="block text-sm font-medium text-ink">{{ $etichetta }}</span>

    <button type="button"
            wire:click="{{ $selettoreSede ? 'chiudiSedi' : 'apriSedi' }}"
            aria-haspopup="listbox"
            aria-expanded="{{ $selettoreSede ? 'true' : 'false' }}"
            aria-labelledby="{{ $idDom }}-etichetta"
            data-selettore-sede
            class="mt-1 flex min-h-[44px] w-full items-center justify-between gap-2 rounded-md border border-border-strong bg-surface px-3 py-2.5 text-left text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
        @if ($selettore['scelta'])
            <span data-sede-scelta>{{ SediSeguite::etichetta($selettore['scelta']) }}</span>
        @else
            <span class="text-ink-3">{{ $segnaposto }}</span>
        @endif
        <span aria-hidden="true" class="text-ink-3">▾</span>
    </button>

    @if ($selettoreSede)
        <div class="mt-1 rounded-md border border-border bg-surface shadow-md"
             x-data="uiCombobox()"
             x-on:keydown.escape.stop="$wire.chiudiSedi()"
             data-pannello-sedi>
            <div class="border-b border-border p-2">
                <label for="{{ $idDom }}-cerca" class="sr-only">Scrivi per filtrare</label>
                <input id="{{ $idDom }}-cerca" type="text" autocomplete="off"
                       role="combobox" aria-autocomplete="list" aria-expanded="true"
                       aria-controls="{{ $idDom }}-lista"
                       placeholder="Scrivi per filtrare…"
                       x-ref="cerca"
                       x-init="$nextTick(() => $refs.cerca.focus())"
                       wire:model.live.debounce.250ms="cercaSede"
                       x-on:input="riapri()"
                       x-on:keydown.arrow-down.prevent="giu({{ $selettore['righe']->count() }})"
                       x-on:keydown.arrow-up.prevent="su()"
                       x-on:keydown.enter.prevent="scegli($refs.lista)"
                       class="block min-h-[44px] w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
            </div>

            <ul id="{{ $idDom }}-lista" role="listbox" x-ref="lista" class="max-h-60 overflow-y-auto">
                @forelse ($selettore['righe'] as $i => $sede)
                    <li role="option" wire:key="{{ $idDom }}-opt-{{ $sede->id }}"
                        :aria-selected="attivo === {{ $i }} ? 'true' : 'false'">
                        <button type="button" wire:click="scegliSede({{ $sede->id }})"
                                class="flex min-h-[44px] w-full items-center px-3 text-left text-sm text-ink hover:bg-brand-soft"
                                :class="attivo === {{ $i }} && 'bg-brand-soft font-medium'">{{ SediSeguite::etichetta($sede) }}</button>
                    </li>
                @empty
                    <li class="px-3 py-3 text-sm text-ink-3" data-nessuna-sede>Nessun nome contiene «{{ $cercato }}».</li>
                @endforelse
            </ul>

            <div class="flex items-center justify-between gap-2 border-t border-border p-2">
                <span class="px-1 text-xs text-ink-3" data-sedi-conto>{{ $selettore['righe']->count() }} di {{ $selettore['inTutto'] }}</span>
                <x-ui.button variant="ghost" wire:click="chiudiSedi" class="!px-2 !py-1 text-sm">Chiudi</x-ui.button>
            </div>
        </div>
    @endif

    @if ($aiuto)
        <p class="mt-1 text-xs text-ink-3">{{ $aiuto }}</p>
    @endif
    @error('sedeId') <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p> @enderror
</div>

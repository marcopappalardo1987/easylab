{{--
    Il selettore del fornitore (🔗 ADR-051): una tendina che si scorre, si
    filtra scrivendo e, se il fornitore manca, lo crea senza uscire dalla
    schermata. Lo stato e le azioni sono in `SceglieFornitore`.

    Parametri: `campo` (la chiave del campo: `strumento`, `ricambio.2`,
    `correzione`), `etichetta`, `sceltoId` (ciò che il form tiene), `erroreSu`
    (la chiave di validazione), `facoltativo`.

    🔴 **«Aperto» lo dice il server** (`selettoreFornitore`), non Alpine: è la
    regola di `TendineLivewireGuardrailTest`. L'elenco arriva con un giro sul
    server, e uno stato tenuto nel browser ripartirebbe da capo proprio quando
    Livewire ridisegna per riempirlo. Alpine qui porta il fuoco nel campo di
    ricerca e muove l'evidenziazione con le frecce; Invio fa `click()` sul
    bottone evidenziato, quindi la strada per scegliere resta una sola, quella
    del server, ed è quella che i test percorrono.

    ⛔ Niente «chiudi al click fuori»: il bottone di un altro selettore è
    «fuori», e il suo click aprirebbe quello mentre questo gesto lo richiude.
    Si chiude scegliendo, con «Chiudi», con Esc o aprendone un altro.

    Il pannello sta nel flusso del form e non sopra: dentro una modale un
    riquadro in sovrimpressione verrebbe tagliato dal suo bordo.

    ⚠️ Non coperto dai test (i test Livewire non eseguono Alpine): frecce,
    Invio, Esc e il fuoco sul campo di ricerca.
--}}
@php
    $scelto = $fornitoriScelti->get((int) $sceltoId);
    $aperto = $selettoreFornitore === $campo && $elencoFornitori !== null;
    $idDom = 'fornitore-'.str_replace('.', '-', $campo);
    $cercato = trim($cercaFornitore);
@endphp

<div wire:key="selettore-{{ $campo }}">
    <span id="{{ $idDom }}-etichetta" class="block text-sm font-medium text-ink">{{ $etichetta }}</span>

    <button type="button"
            wire:click="{{ $aperto ? 'chiudiFornitori' : "apriFornitori('{$campo}')" }}"
            aria-haspopup="listbox"
            aria-expanded="{{ $aperto ? 'true' : 'false' }}"
            aria-labelledby="{{ $idDom }}-etichetta"
            data-selettore-fornitore="{{ $campo }}"
            class="mt-1 flex min-h-[44px] w-full items-center justify-between gap-2 rounded-md border border-border-strong bg-surface px-3 py-2.5 text-left text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
        @if ($scelto)
            <span>{{ $scelto->ragione_sociale }}{{ $scelto->trashed() ? ' (cestinato)' : '' }}</span>
        @else
            <span class="text-ink-3">{{ $facoltativo ? '— Nessun fornitore —' : '— Scegli un fornitore —' }}</span>
        @endif
        <span aria-hidden="true" class="text-ink-3">▾</span>
    </button>

    @if ($aperto)
        <div class="mt-1 rounded-md border border-border bg-surface shadow-md"
             x-data="uiCombobox()"
             x-on:keydown.escape.stop="$wire.chiudiFornitori()"
             data-pannello-fornitori="{{ $campo }}">
            @if ($nuovoFornitoreAperto)
                {{-- Il mini-form. Non è un `<form>`: sarebbe dentro quello della
                     schermata, e l'HTML non li annida. Invio qui crea il
                     fornitore invece di salvare la macchina a metà. --}}
                <div class="space-y-3 p-3" data-nuovo-fornitore>
                    <p class="text-sm font-medium text-ink">Nuovo fornitore</p>

                    <x-ui.input name="nuovoFornitore.ragione_sociale" label="Ragione sociale"
                                wire:model="nuovoFornitore.ragione_sociale"
                                wire:keydown.enter.prevent="creaFornitore"
                                x-init="$nextTick(() => $el.focus())" />

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.input name="nuovoFornitore.email" label="Email" type="email"
                                    wire:model="nuovoFornitore.email"
                                    wire:keydown.enter.prevent="creaFornitore" />
                        <x-ui.input name="nuovoFornitore.telefono" label="Telefono"
                                    wire:model="nuovoFornitore.telefono"
                                    wire:keydown.enter.prevent="creaFornitore" />
                    </div>

                    <p class="text-xs text-ink-3">
                        Email e telefono sono facoltativi: il resto si completa dalla pagina Fornitori.
                    </p>

                    <div class="flex justify-end gap-2">
                        <x-ui.button variant="secondary" wire:click="annullaNuovoFornitore">Annulla</x-ui.button>
                        <x-ui.button wire:click="creaFornitore" wire:loading.attr="disabled" data-crea-fornitore>Crea e scegli</x-ui.button>
                    </div>
                </div>
            @else
                <div class="border-b border-border p-2">
                    <label for="{{ $idDom }}-cerca" class="sr-only">Cerca un fornitore</label>
                    <input id="{{ $idDom }}-cerca" type="text" autocomplete="off"
                           role="combobox" aria-autocomplete="list" aria-expanded="true"
                           aria-controls="{{ $idDom }}-lista"
                           placeholder="Scrivi per cercare…"
                           x-ref="cerca"
                           x-init="$nextTick(() => $refs.cerca.focus())"
                           wire:model.live.debounce.250ms="cercaFornitore"
                           x-on:input="riapri()"
                           x-on:keydown.arrow-down.prevent="giu({{ $elencoFornitori['righe']->count() }})"
                           x-on:keydown.arrow-up.prevent="su()"
                           x-on:keydown.enter.prevent="scegli($refs.lista)"
                           class="block min-h-[44px] w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                </div>

                <ul id="{{ $idDom }}-lista" role="listbox" x-ref="lista" class="max-h-60 overflow-y-auto">
                    @forelse ($elencoFornitori['righe'] as $i => $fornitore)
                        <li role="option" wire:key="{{ $idDom }}-opt-{{ $fornitore->id }}"
                            :aria-selected="attivo === {{ $i }} ? 'true' : 'false'">
                            <button type="button" wire:click="scegliFornitore({{ $fornitore->id }})"
                                    class="flex min-h-[44px] w-full items-center px-3 text-left text-sm text-ink hover:bg-brand-soft"
                                    :class="attivo === {{ $i }} && 'bg-brand-soft font-medium'">{{ $fornitore->ragione_sociale }}{{ $fornitore->trashed() ? ' (cestinato)' : '' }}</button>
                        </li>
                    @empty
                        <li class="px-3 py-3 text-sm text-ink-3" data-nessun-fornitore>
                            {{ $cercato !== '' ? 'Nessun fornitore con questo nome.' : 'Nessun fornitore registrato.' }}
                        </li>
                    @endforelse
                </ul>

                @if ($elencoFornitori['altri'])
                    <p class="border-t border-border px-3 py-2 text-xs text-ink-3" data-altri-fornitori>
                        Ce ne sono altri: scrivi per restringere l'elenco.
                    </p>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border p-2">
                    @can('fornitori.create')
                        <x-ui.button variant="ghost" wire:click="apriNuovoFornitore" class="!px-2 !py-1 text-sm" data-apri-nuovo-fornitore>
                            ＋ Nuovo fornitore{{ $cercato !== '' ? ' «'.$cercato.'»' : '' }}
                        </x-ui.button>
                    @endcan

                    <span class="ml-auto flex gap-1">
                        @if ($facoltativo && $scelto)
                            <x-ui.button variant="ghost" wire:click="togliFornitore" class="!px-2 !py-1 text-sm">Nessun fornitore</x-ui.button>
                        @endif
                        <x-ui.button variant="ghost" wire:click="chiudiFornitori" class="!px-2 !py-1 text-sm">Chiudi</x-ui.button>
                    </span>
                </div>
            @endif
        </div>
    @endif

    @error($erroreSu)
        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
    @enderror
</div>

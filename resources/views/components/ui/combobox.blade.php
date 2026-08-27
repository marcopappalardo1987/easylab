@props([
    'name',
    'label' => null,
    'suggerimenti' => [],
    'onSelect',
    'index' => null,
    'stato' => null,
    'placeholder' => null,
])

{{--
    Combobox con creazione "al volo" (Design System §5.6 — ADR-008/022).

    **Alpine non scrive mai il valore, ed è il motivo per cui questo componente
    è testabile.** Il dropdown è renderizzato dal SERVER e ogni suggerimento è
    un `<button wire:click>` vero; Alpine apre/chiude, sposta l'evidenziazione e
    su Invio fa `click()` sul bottone già evidenziato. Esiste quindi un solo
    percorso di selezione — quello server-side — e non una seconda
    implementazione in JS che i test non vedrebbero. Conseguenza gradita: senza
    JavaScript il campo continua a funzionare a click.

    **Primi `aria-*` del progetto.** Il Design System non li menziona (finora
    c'erano solo `role="dialog"` nella modale e gli `sr-only` dei glifi), ma un
    combobox senza `role`/`aria-expanded`/`aria-activedescendant` è, per uno
    screen reader, una casella di testo con del rumore accanto. Sono una scelta,
    non un'importazione da un altro progetto.

    ⚠️ Non coperto dai test (i test Livewire non eseguono Alpine): tastiera,
    click-outside, aggiornamento degli aria dinamici, target da 44px reali,
    sopravvivenza dello stato al morph. Vedi la checklist manuale in roadmap.

    **I colori sono solo token semantici** (DS §8.2, campione `.el-drop`): il
    tema si scambia sotto, e per questo qui non compare nessuna variante
    `dark:`. 🔴 Il testo d'errore è `text-bad-soft-ink` e non `text-danger-600`
    come scrive §5.6: su `--surface` scuro `danger-600` fa 2,64:1 — le misure e
    la decisione sono in DS §8.2, nota 4. Il placeholder è `text-ink-3`, mai
    più chiaro (DS §2.2). `min-h-[44px]` resta il bersaglio touch di DS §5.1.
--}}
<div class="relative" x-data="uiCombobox()" @click.outside="chiudi()">
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-ink">{{ $label }}</label>
    @endif

    <input
        id="{{ $name }}"
        type="text"
        role="combobox"
        autocomplete="off"
        aria-autocomplete="list"
        aria-controls="{{ $name }}-lista"
        aria-expanded="{{ count($suggerimenti) > 0 ? 'true' : 'false' }}"
        :aria-activedescendant="attivo === null ? null : '{{ $name }}-opt-' + attivo"
        x-on:input="riapri()"
        x-on:keydown.arrow-down.prevent="giu({{ count($suggerimenti) }})"
        x-on:keydown.arrow-up.prevent="su()"
        x-on:keydown.enter.prevent="scegli($refs.lista)"
        x-on:keydown.escape.stop="chiudi()"
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        {{ $attributes }}
        class="mt-1 block min-h-[44px] w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">

    {{-- ⚠️ La lista esiste **se e solo se** il server ha dei suggerimenti, e la
         sua visibilità NON dipende da uno stato Alpine "aperto".

         Prima dipendeva, e non funzionava: digitando, Livewire rifà il render
         dopo il debounce, il nodo viene rimpiazzato e `x-data` si
         reinizializza — quindi il flag tornava `false` e il dropdown non
         compariva mai, mentre il testo «Collegato»/«Nuovo», renderizzato dal
         server, si vedeva benissimo. È lo stesso principio già scelto per la
         selezione («Alpine non scrive mai il valore»), che qui mancava alla
         visibilità: il server è la fonte di verità, Alpine aggiunge solo
         tastiera e chiusura. `chiuso` riparte da `false` a ogni render, ed è
         voluto: se stai ancora digitando, i suggerimenti nuovi si rivedono. --}}
    @if (count($suggerimenti) > 0)
        <ul
            id="{{ $name }}-lista"
            role="listbox"
            x-ref="lista"
            x-show="!chiuso"
            class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border border-border bg-surface shadow-md">
            @foreach ($suggerimenti as $i => $s)
                <li id="{{ $name }}-opt-{{ $i }}" role="option" :aria-selected="attivo === {{ $i }} ? 'true' : 'false'">
                    <button
                        type="button"
                        class="flex min-h-[44px] w-full items-center px-3 text-left text-sm text-ink hover:bg-brand-soft"
                        :class="attivo === {{ $i }} && 'bg-brand-soft font-medium'"
                        wire:click="{{ $onSelect }}({{ $index }}, @js($s['nome']))">{{ $s['nome'] }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Solo il caso «creo»: è l'informazione che la lista NON può dare.
         C'era anche un «Collegato a una voce già a catalogo», ma con il
         dropdown visibile ripeteva ciò che le opzioni mostrano già — e finché
         il dropdown non si vedeva era pure l'unico segno di vita, il che lo
         rendeva fuorviante. Glifo + testo, mai solo colore (DS §1/§4). --}}
    @if ($stato === 'nuovo')
        <p class="mt-1 flex items-center gap-1 text-sm text-ink-2" role="status" aria-live="polite">
            <span aria-hidden="true">＋</span> Nuovo ricambio: verrà creato a catalogo.
        </p>
    @endif

    @error($name)
        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
    @enderror
</div>

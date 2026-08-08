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
--}}
<div class="relative" x-data="uiCombobox()" @click.outside="chiudi()">
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-neutral-800">{{ $label }}</label>
    @endif

    <input
        id="{{ $name }}"
        type="text"
        role="combobox"
        autocomplete="off"
        aria-autocomplete="list"
        aria-controls="{{ $name }}-lista"
        :aria-expanded="aperto ? 'true' : 'false'"
        :aria-activedescendant="attivo === null ? null : '{{ $name }}-opt-' + attivo"
        x-on:focus="apri()"
        x-on:input="apri()"
        x-on:keydown.arrow-down.prevent="giu({{ count($suggerimenti) }})"
        x-on:keydown.arrow-up.prevent="su()"
        x-on:keydown.enter.prevent="scegli($refs.lista)"
        x-on:keydown.escape.stop="chiudi()"
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        {{ $attributes }}
        class="mt-1 block min-h-[44px] w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">

    <ul
        id="{{ $name }}-lista"
        role="listbox"
        x-ref="lista"
        x-show="aperto && {{ count($suggerimenti) }} > 0"
        x-cloak
        class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-md">
        @foreach ($suggerimenti as $i => $s)
            <li id="{{ $name }}-opt-{{ $i }}" role="option" :aria-selected="attivo === {{ $i }} ? 'true' : 'false'">
                <button
                    type="button"
                    class="flex min-h-[44px] w-full items-center px-3 text-left text-sm text-neutral-800 hover:bg-primary-50"
                    :class="attivo === {{ $i }} && 'bg-primary-50 font-medium'"
                    wire:click="{{ $onSelect }}({{ $index }}, @js($s['nome']))">{{ $s['nome'] }}</button>
            </li>
        @endforeach
    </ul>

    {{-- Stato del gesto: "collego" o "creo" sono due cose diverse, e chi scrive
         deve saperlo prima di salvare. Fuori dal listbox perché non è
         un'opzione selezionabile; glifo + testo e mai solo colore (DS §1/§4). --}}
    @if ($stato === 'nuovo')
        <p class="mt-1 flex items-center gap-1 text-sm text-neutral-600" role="status" aria-live="polite">
            <span aria-hidden="true">＋</span> Nuovo ricambio: verrà creato a catalogo.
        </p>
    @elseif ($stato === 'collegato')
        <p class="mt-1 flex items-center gap-1 text-sm text-neutral-600" role="status" aria-live="polite">
            <span aria-hidden="true">🔗</span> Collegato a una voce già a catalogo.
        </p>
    @endif

    @error($name)
        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
    @enderror
</div>

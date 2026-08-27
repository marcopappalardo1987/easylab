@props([
    'label' => null,
    'name',
    'type' => 'text',
    'rivelabile' => false,
])

{{--
    Campo di testo (Design System §5.6, §8.2 — campione `.el-field` / `.el-in`).

    Solo token **semantici**: il tema si scambia sotto, e per questo qui non
    compare nessuna variante `dark:` (DS §8.1).

    Tre scelte che non si leggono dal codice:

    - 🔴 **Il testo d'errore è `text-bad-soft-ink`, non `text-danger-600`.**
      §5.6 scrive `danger-600`, ma su `--surface` scuro (`#111C2E`) fa 2,64:1:
      illeggibile proprio nel tema in cui un errore conta di più.
      `--bad-soft-ink` fa 8,31:1 in chiaro e 9,00:1 in scuro, ed è ciò che il
      campione monta (`.el-field .err{color:var(--bad-soft-ink)}`). Misure e
      decisione in DS §8.2, nota 4.
    - **Il placeholder è `text-ink-3`**, mai più chiaro: a `neutral-400` faceva
      2,56:1 su bianco, sotto AA (DS §2.2).
    - **`bg-surface` è esplicito** e non ereditato: la preflight di Tailwind
      rende trasparenti i controlli di form, quindi un campo posato su
      `bg-canvas` prenderebbe il fondo della pagina invece della propria
      superficie. Il campione lo dichiara: `.el-in{background:var(--surface)}`.

    ## `rivelabile` — il pulsante che mostra la password in chiaro

    Prop **opzionale**, spenta di default: si accende dove serve, e la
    definizione del campo resta una sola. Oggi la usa `/login`; accenderla su
    reset, invito e conferma password è una parola.

    ⛔ **Non usa Alpine, e non è una preferenza di stile.** `guest-layout` non
    carica `@livewireScripts`, e Alpine arriva **da quel bundle**: su una pagina
    di autenticazione un `x-data` è **inerte** — nessun errore, semplicemente
    non succede niente. È la stessa trappola del `wire:click` fuori da Livewire
    già incontrata nel menù utente. Il comportamento vive quindi in
    `resources/js/app.js`, che `@vite` carica su **entrambi** i layout, come
    ascoltatore delegato su `document`.

    ⚠️ **Il pulsante nasce `hidden` e lo rivela il JavaScript.** Senza JS non
    comparirebbe affatto — invece di restare lì come un comando morto che non fa
    nulla quando lo si preme. È la stessa disciplina del combobox, che senza
    JavaScript resta usabile a click.

    ⚠️ **Il bersaglio è 44×44px** (DS §5.1) mentre il campo ne è alto 42: il
    pulsante sborda di un pixel sopra e sotto, invisibile, perché la soglia
    riguarda l'**area premibile**, non la scatola che la contiene.

    ⚠️ **Le icone sono SVG di tratto, non glifi.** Un `👁` avrebbe presentazione
    emoji su macOS e **ignorerebbe `currentColor`** — è già successo con `☀ ☾`
    nel selettore di tema e con `⏳` nel badge obsoleto, entrambi misurati.
--}}
<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-ink">{{ $label }}</label>
    @endif

    @php
        // Con il pulsante il campo perde `mt-1` (passa al contenitore, che deve
        // coincidere con la scatola dell'input perché l'assoluto si centri) e
        // guadagna spazio a destra, o il testo scorrerebbe sotto l'icona.
        $classiCampo = 'block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink placeholder:text-ink-3 focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-3';
        $classiCampo .= $rivelabile ? ' pr-12' : ' mt-1';
    @endphp

    <div @class(['relative mt-1' => $rivelabile])>
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
            {{ $attributes->merge(['class' => $classiCampo]) }}>

        @if ($rivelabile)
            <button type="button" data-mostra-password hidden
                aria-controls="{{ $name }}" aria-pressed="false" aria-label="Mostra la password"
                class="absolute top-1/2 right-0 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-md text-ink-2 hover:text-ink focus:ring-2 focus:ring-ring focus:outline-none">
                {{-- Occhio: «premi per mostrare». Visibile finché la password è nascosta. --}}
                <svg data-icona="mostra" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
                {{-- Occhio sbarrato: «premi per nascondere». --}}
                <svg data-icona="nascondi" class="h-5 w-5" hidden fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.7A9.6 9.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3.2 3.9M6.5 7.4A17 17 0 0 0 2.5 12S6 18.5 12 18.5c.9 0 1.7-.1 2.5-.4" />
                </svg>
            </button>
        @endif
    </div>

    @error($name)
        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
    @enderror
</div>

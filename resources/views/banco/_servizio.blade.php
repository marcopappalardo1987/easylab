{{-- Stati di servizio — 🔗 DS §5.9, §8.4, campione `.el-empty` / `.el-sk` / `.el-toast`. --}}

@component('banco.pezzo', [
    'titolo' => 'Vuoto — icona neutra, frase che spiega, UNA azione',
    'token' => 'bg-surface-sunken + inset-ring-border (il cerchio) · text-ink-3 (icona) · text-ink (titolo) · text-ink-2 (frase)',
    'nota' => "Una azione, mai tre: se ce ne fossero tre, nessuna sarebbe quella giusta. L'icona è dentro un cerchio incassato col filo di bordo interno — la stessa forma del badge neutro, e per la stessa ragione: su una card la superficie incassata è quasi dello stesso colore del fondo.",
])
    <div class="flex w-full flex-col items-center gap-2 px-4 py-9 text-center text-ink-2">
        <span class="grid size-11 place-items-center rounded-full bg-surface-sunken text-ink-3 inset-ring inset-ring-border">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z" />
            </svg>
        </span>
        <strong class="text-base font-semibold text-ink">Nessun intervento pianificato</strong>
        <p class="max-w-[34ch] text-sm">Questo strumento non ha scadenze future. Programmane uno per farlo comparire nel semaforo.</p>
        <x-ui.button class="mt-2">Nuovo intervento</x-ui.button>
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'Caricamento — skeleton della forma reale, non un rettangolo',
    'token' => 'bg-surface-sunken · animate-pulse · rounded',
    'nota' => "⚠️ Lo skeleton sta su `surface-sunken`, che è un token semantico: nei due temi è la stessa superficie incassata di `<thead>` e del campo disabilitato, quindi non ha bisogno di una regola propria. DS §5.9 lo cita col gradino `neutral-100`, che è pre-tema — sul fondo scuro sarebbe una fascia chiara. Il campione scrive infatti `background: var(--surface-sunken)`. Nessuno spinner a pagina intera dove è evitabile: lo scheletro dice quanto contenuto sta arrivando.",
])
    <div class="w-full space-y-2" aria-hidden="true">
        @for ($i = 0; $i < 3; $i++)
            <div class="flex items-center gap-3">
                <span class="size-4 animate-pulse rounded-full bg-surface-sunken"></span>
                <span class="h-3.5 flex-1 animate-pulse rounded bg-surface-sunken"></span>
                <span class="h-3.5 w-20 animate-pulse rounded bg-surface-sunken"></span>
            </div>
        @endfor
    </div>
    <p class="w-full text-xs text-ink-3">
        ⚠️ Uno skeleton è decorazione fino a quando non lo si annuncia: nell'applicazione va accompagnato da
        un <span class="font-mono">aria-busy</span> o da un <span class="font-mono">role="status"</span> sul contenitore che si sta riempiendo.
    </p>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'Toast — conferma ciò che è già successo',
    'token' => 'SCALA e non semantici: neutral-900 in chiaro, neutral-700 in scuro (DS §8.4) · icona su ok-dot · shadow-md',
    'nota' => "🔴 È l'unico pezzo del banco che non usa i token semantici, ed è la regola e non un'eccezione mia. DS §8.4: il toast galleggia SOPRA la pagina, la sua superficie non è una superficie della pagina, e su fondo scuro `neutral-900` sparirebbe dentro `--bg`. ⚠️ Le tre regole che lo colorano stanno in un `<style>` qui accanto perché l'applicazione non ha ancora un toast: quando ne nascerà uno vero, quelle righe diventano un token (o una `@utility`) in `app.css`, il componente entra in `esenzioniDiSuperficie()` con la sua ragione, e questo blocco lo monta come tutti gli altri. Dichiarato apposta: un colore scritto in CSS a mano è invisibile a `SuperficiTokenizzateGuardrailTest`, che legge classi e non stili — e una scorciatoia non dichiarata varrebbe meno di un debito scritto.",
])
    {{-- ⛔ **Un foglio di stile dentro una vista è ciò che questo progetto evita**
         (è il difetto per cui `welcome.blade.php` è in `DA_MIGRARE`). Sta qui
         per tre righe e con una data di scadenza: il toast è il solo elemento di
         DS §8.4 che **cambia col tema restando su token di scala**, e le classi
         Tailwind non sanno esprimerlo senza una variante `dark:` — che DS §8.1
         vieta. I tre selettori sono gli stessi di `app.css`, nello stesso
         ordine, ed è quello che il campione scrive per `.el-toast`. --}}
    <style>
        [data-banco-toast] { background-color: var(--color-neutral-900); color: #fff; }
        :root[data-theme='dark'] [data-banco-toast] { background-color: var(--color-neutral-700); }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme='light']) [data-banco-toast] { background-color: var(--color-neutral-700); }
        }
    </style>

    <div data-banco-toast class="flex w-full max-w-md items-center gap-3 rounded-lg px-4 py-3 text-sm shadow-md"
         role="status" aria-live="polite">
        <svg class="size-5 shrink-0 text-ok-dot" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
        </svg>
        <span class="flex-1">Permessi del ruolo Tecnico salvati.</span>
        {{-- 44×44 anche qui: è un comando a sola icona (DS §5.1). --}}
        <button type="button" class="-m-2 inline-flex size-11 shrink-0 items-center justify-center rounded-md opacity-70 hover:opacity-100" aria-label="Chiudi">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
@endcomponent

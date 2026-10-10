{{-- Navigazione e marchio — 🔗 DS §5.5, §5.7, §8.2, ADR-033/034. --}}

@component('banco.pezzo', [
    'titolo' => 'x-app.nav-link — attiva e inattiva, sul fondo in cui vive',
    'token' => 'attiva: bg-brand-soft-strong + font-semibold + aria-current · inattiva: text-ink-2 hover:bg-surface hover:text-ink',
    'fondo' => 'bg-surface-sunken',
    'nota' => "🔴 Il pezzo è posato su `bg-surface-sunken` perché è lì che la voce vive: nel campione la sidebar è incassata e la superficie piena è il contenuto. Da cui l'hover che EMERGE (`hover:bg-surface`) invece di affondare — su un fondo già incassato un hover più scuro non si vedrebbe. Va provato col mouse: è l'unico stato che uno screenshot non mostra. La voce attiva non si distingue solo per colore — porta `aria-current=\"page\"` e il grassetto.",
])
    <nav class="w-full max-w-xs space-y-1">
        <x-app.nav-link href="#navigazione" active>
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
            Dashboard
        </x-app.nav-link>

        <x-app.nav-link href="#navigazione">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
            Strumenti
        </x-app.nav-link>

        <x-app.nav-link href="#navigazione">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            Laboratori
        </x-app.nav-link>
    </nav>

    {{-- La stessa navigazione sulla superficie PIENA: è il confronto che rende
         visibile perché la sidebar ha cambiato ruolo in F3. --}}
    <nav class="w-full max-w-xs space-y-1 rounded-md border border-border bg-surface p-2">
        <x-app.nav-link href="#navigazione" active>Su bg-surface (attiva)</x-app.nav-link>
        <x-app.nav-link href="#navigazione">Su bg-surface (inattiva)</x-app.nav-link>
    </nav>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-piattaforma.nav — la barra delle sezioni di piattaforma',
    'token' => 'attivo: border-brand + text-brand + font-semibold · inattivo: text-ink-2 hover:border-border-strong hover:text-ink · min-h-11 · overflow-x-auto',
    'nota' => "⚠️ Qui è VUOTA, ed è corretto. Ogni voce dichiara il proprio permesso e si filtra su `auth()->user()?->can(...)`: sul banco non c'è nessun utente, quindi la collezione è vuota e il componente rende il solo `<nav>` col bordo. È l'unico componente dell'applicazione che il banco non può mostrare pieno — riempirlo vorrebbe dire fabbricare un utente e dei permessi, cioè far dipendere una pagina di sola presentazione da una regola di autorizzazione. Il confronto sotto rende la stessa forma senza il filtro.",
])
    <div class="w-full">
        <x-piattaforma.nav />
    </div>

    {{-- ⚠️ La copia statica NON è il componente, ed è etichettata come tale: è
         il markup che `x-piattaforma.nav` produrrebbe per un utente che può
         vedere tutte e quattro le voci. Sta qui perché altrimenti i colori del
         tab attivo — che sono l'unica cosa che il banco deve poter mostrare —
         non si vedrebbero in nessun tema. Se un giorno divergesse dal
         componente, questo è il pezzo sbagliato, non quello sopra. --}}
    <div class="w-full">
        <p class="mb-2 text-xs text-ink-3">Copia statica, per i soli colori:</p>
        <nav class="flex gap-1 overflow-x-auto border-b border-border" aria-label="Sezioni della piattaforma (esempio)">
            <a href="#navigazione" aria-current="page" class="-mb-px flex min-h-11 items-center border-b-2 border-brand px-3 text-sm font-semibold whitespace-nowrap text-brand">Clienti</a>
            <a href="#navigazione" class="-mb-px flex min-h-11 items-center border-b-2 border-transparent px-3 text-sm font-medium whitespace-nowrap text-ink-2 hover:border-border-strong hover:text-ink">Registro di audit</a>
            <a href="#navigazione" class="-mb-px flex min-h-11 items-center border-b-2 border-transparent px-3 text-sm font-medium whitespace-nowrap text-ink-2 hover:border-border-strong hover:text-ink">Ruoli e permessi</a>
            <a href="#navigazione" class="-mb-px flex min-h-11 items-center border-b-2 border-transparent px-3 text-sm font-medium whitespace-nowrap text-ink-2 hover:border-border-strong hover:text-ink">Errori</a>
        </nav>
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-brand-logo — compatto e completo',
    'token' => 'due FILE, non due token: [data-marchio] scambiato da app.css sui tre selettori del tema',
    'nota' => "⚠️ Un `<img>` non eredita le variabili CSS (ADR-033): sul fondo scuro il blu profondo farebbe 1,9:1, quindi esistono due file e nel secondo il blu si alza a `primary-200` invece di invertirsi. Le due immagini portano lo stesso `alt` e quella nascosta esce dall'albero di accessibilità: ne viene annunciata una sola. ⚠️ Un `src` verso un file assente non rompe niente — pagina 200 e un rettangolo vuoto: se uno dei quattro riquadri qui sotto è vuoto, manca il file.",
])
    <div class="flex flex-wrap items-center gap-6 rounded-md border border-border bg-surface p-4">
        <x-brand-logo />
        <x-brand-logo variante="completo" class="h-12 w-auto" />
    </div>

    <div class="flex flex-wrap items-center gap-6 rounded-md border border-border bg-surface-sunken p-4">
        <x-brand-logo />
        <x-brand-logo variante="completo" class="h-12 w-auto" />
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.selettore-tema — i tre stati',
    'token' => 'bg-surface + border-border · premuto: aria-pressed:bg-brand-soft-strong + aria-pressed:text-brand-soft-ink · size-11 (44px esatti)',
    'nota' => "⚠️ I tre esempi rendono i tre valori possibili di `aria-pressed`, che nell'applicazione viene dal database. Qui non c'è un utente: cliccarli cambia davvero il tema (è Alpine a scrivere `data-theme` e `localStorage`), ma `wire:click` è inerte e nessuna colonna si muove. Al ricaricamento il colore viene da `localStorage` mentre l'evidenziazione riparte da «sistema» — è una proprietà del banco, non del componente. La riga premuta è legata all'ATTRIBUTO e non a una classe calcolata: la stessa regola vale per ciò che rende il server e per ciò che Alpine anticipa.",
])
    <div class="flex flex-wrap items-center gap-4 rounded-md border border-border bg-surface p-4">
        <x-ui.selettore-tema :corrente="App\Enums\TemaUtente::Chiaro" />
        <x-ui.selettore-tema :corrente="App\Enums\TemaUtente::Scuro" />
        <x-ui.selettore-tema :corrente="App\Enums\TemaUtente::Sistema" />
    </div>

    {{-- ⚠️ Sulla superficie INCASSATA, che è dove sta davvero nella scorciatoia
         della top bar e nella pagina Preferenze: il selettore porta un
         `bg-surface` proprio, quindi qui deve EMERGERE dal fondo. Se sparisse,
         il difetto sarebbe suo e non della pagina che lo ospita. --}}
    <div class="flex flex-wrap items-center gap-4 rounded-md border border-border bg-surface-sunken p-4">
        <x-ui.selettore-tema :corrente="App\Enums\TemaUtente::Chiaro" />
        <x-ui.selettore-tema :corrente="App\Enums\TemaUtente::Sistema" />
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-parco.nav — le schede del Parco clienti',
    'token' => 'attivo: border-brand + text-brand + font-semibold · inattivo: border-transparent + text-ink-2 · hover: border-border-strong + text-ink',
    'nota' => "⚠️ Nessuna scheda risulta attiva qui, ed è corretto: lo stato attivo viene da `request()->routeIs()`, e il banco non sta su nessuna di quelle rotte. Ciò che questo pezzo mostra sono le tinte di riposo e il bordo inferiore — cioè le due cose che nei due temi si guardano.",
])
    <x-parco.nav />
@endcomponent

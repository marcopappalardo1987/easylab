@props(['title' => null])
@php
    $user = auth()->user();
    $role = $user?->getRoleNames()->first();
    $initials = \Illuminate\Support\Str::of($user?->name ?? '')
        ->explode(' ')->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');

    // Il tema, deciso QUI e non dal browser (🔗 ADR-034 punto 3 — DS §8.3).
    //
    // Per l'autenticato la preferenza sta in `users.tema` e il server la sa già
    // mentre compone la pagina: renderla nell'`<html>` significa **zero lampo e
    // zero JavaScript nel percorso critico**. Lo script che serve agli ospiti —
    // che una preferenza a database non ce l'hanno — qui sarebbe un
    // peggioramento: girerebbe dopo il primo layout, cioè dopo il lampo che
    // esiste per evitare.
    //
    // `oSistema()` e non `->tema` nudo: la colonna è NOT NULL, ma un `User`
    // costruito in memoria (una factory, un utente non ancora riletto) non ha
    // riletto il default dello schema e porta `null`. Segue il sistema, come da
    // default — mai un errore su ogni pagina per un attributo mancante.
    $tema = \App\Enums\TemaUtente::oSistema($user?->tema);
    $attributoTema = $tema->attributoHtml();
@endphp
<!DOCTYPE html>
{{-- ⚠️ Con `sistema` l'attributo `data-theme` **non si scrive affatto**: è
     l'assenza a far decidere il sistema operativo (ADR-034 punto 2). Un
     `data-theme=""` non sarebbe la stessa cosa — inciamperebbe nel
     `:not([data-theme="light"])` di `app.css` e spegnerebbe in silenzio la
     media query, cioè proprio la preferenza che si voleva rispettare.

     `data-tema-utente` porta invece **sempre** il valore di dominio, i tre
     stati distinti: serve al client per accorgersi che `localStorage` dice
     un'altra cosa rispetto al database e riallinearsi. Senza di esso «segui il
     sistema» e «il server non ha detto niente» sarebbero indistinguibili. --}}
<html lang="it" class="h-full" data-tema-utente="{{ $tema->value }}"@if ($attributoTema !== null) data-theme="{{ $attributoTema }}"@endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Easy Lab') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles

        {{-- Stili per-pagina: serve alle regole di STAMPA, che sono specifiche
             del foglio e non hanno senso nel bundle di tutta l'app (@page,
             per esempio, è globale e non si può accendere per una sola vista
             da Tailwind). Prima uso: l'etichetta QR. --}}
        @stack('styles')
    </head>
    {{-- In STAMPA restano solo i contenuti: sidebar, top bar e banner di
         impersonation portano `print:hidden`. La regola sta nel layout e non
         nelle singole pagine perché vale per tutte — l'etichetta QR (ADR-003) è
         solo la prima, e i report PDF di S5/S7 avranno lo stesso bisogno.
         Il fondo torna bianco: il grigio dell'app si stampa come una campitura
         che consuma toner e non dice niente. --}}
    <body class="h-full bg-canvas font-sans text-ink antialiased print:bg-white">
        <div x-data="{ sidebarOpen: false }" class="min-h-full">

            {{-- Banner impersonation persistente (Design System §5.8).

                 ⚠️ **Dice entrambi i nomi**, non solo l'impersonato. Chi sta
                 impersonando lo sa; non lo sa il collega che guarda lo stesso
                 schermo, e non lo sa chi legge lo screenshot allegato a un
                 ticket sei mesi dopo — dove «Stai impersonando Mario Rossi» non
                 dice **chi** stesse guardando, cioè l'unica cosa che serve per
                 ricostruire un gesto. L'impersonatore si legge dal servizio,
                 perché `auth()->user()` è già l'impersonato. --}}
            @if (app('impersonate')->isImpersonating())
                @php
                    // `rescue()` e non un `??`: `getImpersonator()` **non**
                    // restituisce null se l'utente non c'è più — chiama
                    // `findUserById()` e **lancia** `ModelNotFoundException`,
                    // che qui sarebbe un 500 su ogni pagina della sessione
                    // impersonata, uscita compresa. Il banner degrada a dire un
                    // nome solo; il pulsante «Esci» resta.
                    $impersonatore = rescue(fn () => app('impersonate')->getImpersonator(), null, false);
                @endphp
                <div class="flex items-center justify-between gap-3 bg-warning-500 px-4 py-2 text-sm text-neutral-900 print:hidden">
                    <span>
                        Stai impersonando <strong>{{ $user->name }}</strong>@if ($user->ente) ({{ $user->ente->nome }})@endif
                        @if ($impersonatore)
                            — sei <strong>{{ $impersonatore->name }}</strong>
                        @endif
                    </span>
                    <a href="{{ route('impersonate.leave') }}"
                       class="rounded-md bg-neutral-900/10 px-3 py-1 font-medium hover:bg-neutral-900/20">
                        Esci dall'impersonation
                    </a>
                </div>
            @endif

            <div class="flex">

                {{-- Backdrop drawer mobile --}}
                <div x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"
                     class="fixed inset-0 z-30 bg-overlay md:hidden"></div>

                {{-- Sidebar: drawer su mobile, colonna FISSA su desktop.

                     🗓️ **`md:sticky md:top-0 md:h-screen` dal 29 Ago 2026**, su
                     richiesta di Marco. Era `md:static md:min-h-screen`: la
                     colonna scorreva insieme alla pagina, quindi su un elenco
                     lungo — gli strumenti, il registro di audit — il menù usciva
                     dallo schermo e per cambiare area bisognava prima risalire
                     in cima.

                     ⚠️ `h-screen` e non `min-h-screen`: un elemento `sticky`
                     deve essere alto quanto la finestra, o «si incolla» a un
                     bordo che sta già fuori. E `overflow-y-auto` sulla
                     navigazione, perché il giorno in cui le voci non ci
                     staranno più il menù deve scorrere per conto suo invece di
                     tagliare le ultime. --}}
                <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-border bg-surface-sunken transition-transform duration-200 md:sticky md:top-0 md:h-screen md:translate-x-0 print:hidden"
                       :class="sidebarOpen && 'translate-x-0'">
                    <div class="flex h-14 items-center border-b border-border px-4">
                        {{-- Il marchio vero (ADR-033), al posto dell'icona a becher
                             disegnata a mano e del testo «Easy Lab»: il logo porta già
                             il nome, e ripeterlo accanto lo direbbe due volte. --}}
                        <a href="{{ route('dashboard') }}" class="flex items-center" aria-label="Easy Lab — vai alla dashboard">
                            <x-brand-logo class="h-7 w-auto" />
                        </a>
                    </div>

                    <nav class="flex-1 space-y-1 overflow-y-auto p-3">
                        <x-app.nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                            Dashboard
                        </x-app.nav-link>
                        {{-- 🗓️ **«Sicurezza» tolta dalla barra il 28 Ago 2026**, su
                             richiesta di Marco: la pagina è già nel menù utente in
                             alto, insieme a «Preferenze» e «Abbonamento».

                             Non è solo deduplicazione. La barra elenca le **aree di
                             dato dell'Ente** — anagrafica, strumenti, documenti,
                             fornitori — mentre sicurezza, preferenze e abbonamento
                             riguardano **chi guarda**, non ciò che guarda: la loro
                             casa è il menù dell'utente. Averla in due posti diceva
                             che fossero due cose diverse.

                             ⚠️ La ROTTA resta, e resta raggiungibile: è dove
                             `EnsureTwoFactorIsEnabled` manda chi deve ancora
                             attivare il 2FA. Togliere la voce non toglie la
                             pagina. --}}

                        @can('unita_organizzativa.view')
                            <x-app.nav-link :href="route('anagrafica.index')" :active="request()->routeIs('anagrafica.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                                Anagrafica
                            </x-app.nav-link>
                        @endcan
                        @can('strumenti.view')
                            <x-app.nav-link :href="route('strumenti.index')" :active="request()->routeIs('strumenti.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
                                Strumenti
                            </x-app.nav-link>
                        @endcan
                        @can('documenti.view')
                            <x-app.nav-link :href="route('documenti.index')" :active="request()->routeIs('documenti.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                Documenti
                            </x-app.nav-link>
                        @endcan
                        @can('fornitori.view')
                            <x-app.nav-link :href="route('fornitori.index')" :active="request()->routeIs('fornitori.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" /></svg>
                                Fornitori
                            </x-app.nav-link>
                        @endcan
                        @can('interventi.view')
                            <x-app.nav-link :href="route('campo.index')" :active="request()->routeIs('campo.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" /></svg>
                                Campo
                            </x-app.nav-link>
                        @endcan
                        @can('interventi.view')
                            <x-app.nav-link :href="route('scadenzario.index')" :active="request()->routeIs('scadenzario.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" /></svg>
                                Scadenzario
                            </x-app.nav-link>
                        @endcan
                        @can('ricambi.view')
                            <x-app.nav-link :href="route('ricambi.index')" :active="request()->routeIs('ricambi.*')">
                                <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" /></svg>
                                Ricambi
                            </x-app.nav-link>
                        @endcan

                        {{-- 🔴 Cabina di regia (S6): l'unica voce che porta fuori dal proprio
                             Ente. Gatata sul permesso di piattaforma e non sul ruolo, come tutte
                             le altre — e durante un'impersonazione sparisce da sé, perché `@can`
                             interroga l'utente impersonato. --}}
                        @can('tenants.view_all')
                            <x-app.nav-link :href="route('piattaforma.index')" :active="request()->routeIs('piattaforma.*')">
                                <svg class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                                </svg>
                                Piattaforma
                            </x-app.nav-link>
                        @endcan

                        {{-- La sezione «Prossimamente» con la voce «Interventi»
                             disabilitata è stata tolta il 25 Ago 2026, ed è la stessa
                             bugia della card rimossa dalla dashboard il 21 Ago, in un
                             altro punto dello schermo: gli interventi esistono da S3 —
                             nel tab della scheda, in `/campo`, nel digest — e ciò che
                             non esisteva era un elenco **cross-macchina**. Un
                             «prossimamente» senza referente è peggio di un'assenza:
                             promette a chi guarda una pagina che nessuno sta
                             scrivendo.

                             ✅ **Aggiornato il 27 Ago 2026**: metà di quella lacuna
                             è chiusa — «cosa scade su tutto il parco» ha una pagina,
                             ed è la voce «Scadenzario» qui sopra. La voce si chiama
                             così e **non** «Interventi» di proposito: `AppShellTest`
                             asserisce che quella parola non compaia in questo blocco,
                             perché rimetterla riaprirebbe la promessa che è costata
                             la rimozione del 25 Ago.

                             ⚠️ Resta scoperta l'altra metà: gli **spostamenti**.
                             `spostamenti.view` ha un solo consumatore, il tab della
                             scheda, e non c'è nessuna vista «dov'è stata questa
                             macchina» che attraversi il parco. La lacuna è dichiarata
                             in roadmap come voce V1.1. --}}
                    </nav>
                </aside>

                {{-- Colonna contenuto --}}
                {{-- ⚠️ `min-w-0` non è decorativo, ed è la riga che rende mobile
                     l'intera applicazione (S4 blocco 10). Un flex item ha
                     `min-width: auto`, quindi si RIFIUTA di restringersi sotto la
                     larghezza intrinseca del proprio contenuto: senza questa
                     classe la colonna cresceva fino alla tabella più larga della
                     pagina — 503px su un telefono da 390 — e a scorrere in
                     orizzontale era il documento intero, header compreso.
                     Conseguenza meno ovvia: gli `overflow-x-auto` che avvolgono
                     le tabelle non entravano MAI in funzione, perché il loro
                     contenitore aveva sempre spazio a sufficienza. C'erano tutti
                     e non servivano a niente. --}}
                <div class="flex min-h-screen min-w-0 flex-1 flex-col">

                    {{-- Top bar (Design System §5.7) --}}
                    <header class="sticky top-0 z-20 flex h-14 items-center justify-between border-b border-border bg-surface px-4 shadow-sm print:hidden">
                        {{-- ⚠️ **`min-w-0` + `overflow-hidden` qui, `shrink-0` sul gruppo
                             di destra**, ed è la stessa lezione che questo file porta già
                             scritta sulla colonna del contenuto (S4 blocco 10): un flex
                             item ha `min-width:auto` e **si rifiuta** di restringersi sotto
                             la larghezza del proprio contenuto. Misurato a 360px prima di
                             toccarlo: hamburger + «Easy Lab» + il nome dell'Ente
                             (`livewire:tenancy.switcher-ente`) fanno ~340px, e i 88px della
                             campanella e del menù utente venivano **spinti fuori dallo
                             schermo** — cioè il logout raggiungibile solo scorrendo in
                             orizzontale. Il difetto è precedente a questo task, verificato
                             rendendo il layout di prima.

                             ⚠️ **Resta un pezzo, e non è chiudibile da qui**: il nome
                             dell'Ente si taglia di netto invece di finire in `…`. I puntini
                             li fa il `truncate` che lo switcher ha già, ma solo se **la sua
                             radice** riceve a sua volta `min-w-0` — e quel file è ancora da
                             migrare (F4). Fino ad allora si sceglie fra un nome tagliato e
                             un menù utente irraggiungibile. --}}
                        {{-- ⛔ **NIENTE `overflow-hidden` qui**, ed è la riga che ha
                             tenuto chiusa la tendina delle sedi per tre giri di
                             correzioni sbagliate (28 Ago 2026).

                             Il pannello dello switcher è `absolute` e cade SOTTO
                             questa intestazione, che è alta `h-14`: con
                             `overflow-hidden` su questo contenitore veniva
                             **ritagliato via**. Si apriva davvero — Alpine
                             toglieva `x-cloak` e il DOM conteneva le sedi — e non
                             si vedeva niente, senza un errore in console.

                             La prova è differenziale: il **menù utente** usa lo
                             stesso identico schema e ha sempre funzionato, e sta
                             nel contenitore accanto, che `overflow-hidden` non ce
                             l'ha. Come la campanella.

                             ⚠️ Il troncamento del nome dell'Ente non dipendeva da
                             qui: lo fanno `min-w-0` su questo flex e `max-w-56` +
                             `truncate` dentro il componente. Chi rimettesse
                             `overflow-hidden` per «sicurezza» richiuderebbe la
                             tendina — c'è un test che lo rende rosso. --}}
                        <div class="flex min-w-0 items-center gap-2">
                            <button type="button" @click="sidebarOpen = true"
                                    class="-ml-1 flex size-11 items-center justify-center rounded-md text-ink-2 hover:bg-surface-sunken hover:text-ink md:hidden"
                                    aria-label="Apri menù">
                                <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                            </button>
                            <span class="text-base font-semibold text-ink md:hidden">Easy Lab</span>

                            {{-- Contesto Ente + switcher fra le proprie sedi
                                 (ADR-032, Design System §5.7). --}}
                            <livewire:tenancy.switcher-ente />
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            {{-- Notifiche in-app (ADR-011). Unico componente
                                 Livewire annidato del progetto: si monta su ogni
                                 pagina, quindi al mount fa solo un conteggio. --}}
                            <livewire:notifiche.campanella />

                            {{-- Menù utente --}}
                            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                <button type="button" @click="open = !open"
                                        class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-surface-sunken">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand text-sm font-semibold text-brand-ink">{{ $initials ?: '·' }}</span>
                                    <span class="hidden text-left sm:block">
                                        <span class="block text-sm font-medium text-ink">{{ $user->name }}</span>
                                        @if ($role)
                                            <span class="block text-xs text-ink-3">{{ $role }}</span>
                                        @endif
                                    </span>
                                    <svg class="h-4 w-4 text-ink-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>

                                <div x-show="open" x-cloak x-transition
                                     class="absolute right-0 z-30 mt-2 w-64 overflow-hidden rounded-md border border-border bg-surface shadow-md">
                                    <div class="border-b border-border px-4 py-3">
                                        <p class="text-sm font-medium text-ink">{{ $user->name }}</p>
                                        <p class="truncate text-xs text-ink-3">{{ $user->email }}</p>
                                    </div>

                                    {{-- La scorciatoia del tema (🔗 ADR-034 — DS §8.3).

                                         ⛔ **Un `<x-ui.selettore-tema>` nudo qui NON avrebbe
                                         funzionato**, ed è il difetto per cui questa riga è un
                                         componente Livewire invece di un tag Blade: la tendina non
                                         è un componente Livewire, e `wire:click` fuori da Livewire
                                         è un attributo **inerte** — non dà errore, semplicemente
                                         non chiama nessuno. La pagina avrebbe cambiato colore
                                         (Alpine) e `users.tema` no; al caricamento successivo
                                         `riallinea()` avrebbe riportato `localStorage` al valore
                                         del database, cioè **annullato la scelta** senza dire
                                         perché. Terzo figlio Livewire della top bar, accanto allo
                                         switcher e alla campanella, per la stessa ragione per cui
                                         quelli lo sono.

                                         ⚠️ Il componente si monta su **ogni** pagina: per questo
                                         non ha `mount()` e il suo `render()` legge la colonna
                                         dell'utente già in sessione, senza una query in più
                                         (disciplina della Campanella). --}}
                                    <livewire:settings.selettore-tema />

                                    {{-- Le voci (`.el-menu` nel campione: testo su `--ink`, hover
                                         su `--surface-sunken`, la sola voce distruttiva su
                                         `--bad-soft-ink`).

                                         ⚠️ `min-h-11` sono i 44px di DS §5.1: con `py-2.5` la riga
                                         misurava ~38px, e questa tendina si apre col pollice tanto
                                         quanto col mouse. Il campione sta a 2.25rem, ma sui
                                         bersagli il Design System vince sul campione.

                                         ⚠️ **«Preferenze» e non più «Notifiche»**: dal 26 Ago 2026
                                         quella pagina porta anche il tema, e un'etichetta che
                                         dicesse «Notifiche» ne nasconderebbe metà. L'`href` non si
                                         tocca — la rotta resta `settings.notifiche`, perché è
                                         citata dal piè di pagina del digest, cioè da email già
                                         spedite. --}}
                                    <a href="{{ route('settings.security') }}" class="flex min-h-11 items-center px-4 text-sm text-ink hover:bg-surface-sunken">Sicurezza</a>
                                    <a href="{{ route('settings.notifiche') }}" class="flex min-h-11 items-center px-4 text-sm text-ink hover:bg-surface-sunken">Preferenze</a>
                                    {{-- «Abbonamento» sta QUI e non in barra laterale: la sidebar
                                         elenca le aree di DATO dell'Ente, mentre l'abbonamento è il
                                         rapporto commerciale dell'**Account** (ADR-032) — la stessa
                                         famiglia di «Sicurezza» e «Preferenze», che sono dell'utente.

                                         ⚠️ Doppio cancello, e servono entrambi: `?->account` perché
                                         un utente senza Ente (il Developer, ADR-018) non ne ha uno, e
                                         `@can('manage', …)` perché vedere la voce e poterla usare
                                         devono essere la stessa domanda — la rotta vive fuori dal
                                         gruppo protetto e non ha un `can:` a raccoglierla. --}}
                                    @php($accountFatturazione = auth()->user()->ente?->account)
                                    @if ($accountFatturazione)
                                        @can('manage', $accountFatturazione)
                                            <a href="{{ route('abbonamento.index') }}" class="flex min-h-11 items-center px-4 text-sm text-ink hover:bg-surface-sunken">Abbonamento</a>
                                        @endcan
                                    @endif
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="flex min-h-11 w-full items-center px-4 text-left text-sm text-bad-soft-ink hover:bg-surface-sunken">Esci</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </header>

                    <main class="flex-1">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>

        @livewireScripts
    </body>
</html>

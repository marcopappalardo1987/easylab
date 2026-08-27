{{--
    🔬 **Il banco dei componenti** — `/design-system`, solo in `local` (F1.5).

    È la traduzione in Blade di `docs/Design/design-system.html`, limitata a
    **ciò che l'applicazione monta davvero**: ogni componente di
    `resources/views/components/`, in ogni stato che ha, nei due temi, con
    accanto i token che usa.

    **Cosa NON è.** Non è un secondo Design System: il contratto resta
    `docs/Design/Design System Base.md`, e questa pagina non decide niente — lo
    mostra. Se il banco e il DS dicessero cose diverse, il difetto è qui.

    ⚠️ **Nessun oggetto arriva dal database.** I tre componenti che vogliono un
    modello (`semaforo-forzato`, `obsoleto`, `errori.cifre`) ricevono istanze
    costruite **in memoria** e mai salvate: è ciò che rende la pagina
    deterministica e ciò che le permette di esistere senza autenticazione. Le
    fixture stanno nel blocco PHP del pezzo che le usa, non in cima: così si
    leggono accanto a ciò che spiegano.

    ⚠️ E in questo commento la parola «php» preceduta da una chiocciola non si
    scrive: `storeUncompiledBlocks()` gira **prima** che i commenti vengano
    tolti, quindi Blade aggancerebbe da qui il primo `@endphp` del file e
    tratterebbe tutto ciò che sta in mezzo come codice. Costa un `ParseError` su
    una riga che non esiste.

    ⛔ **Solo token semantici.** Il banco nasce dopo la rete di F0.5, quindi non
    è in `DA_MIGRARE` e `SuperficiTokenizzateGuardrailTest` lo controlla dal
    primo giorno: una classe di scala scritta qui dentro è rossa oggi, non fra
    sei mesi. L'unica eccezione è il toast, e la sua ragione è scritta dove sta.

    ⚠️ **Come si guarda.** I due temi si scambiano con l'interruttore in testa,
    che qui scrive `localStorage` e l'attributo `data-theme` (Alpine) ma **non**
    il database: non c'è un utente. Le due misure che contano sono **360px** e
    **1280px** — a 360 la tabella diventa una lista di card e la barra di
    piattaforma scorre.
--}}
<x-guest-layout title="Banco dei componenti — Easy Lab">
    {{-- ⚠️ **`h-full` sul `<body>` del guest layout** non lascia crescere una
         pagina lunga come questa: il contenitore si dichiara `min-h-full`. --}}
    <div class="min-h-full">
        @php
            // L'indice: la stessa lista serve la navigazione in testa e l'ordine
            // degli `@include`. Scriverla due volte vorrebbe dire che il giorno
            // in cui una sezione si sposta l'indice resta indietro.
            $sezioni = [
                'azioni' => 'Superfici e azioni',
                'form' => 'Campi',
                'stato' => 'Stato',
                'navigazione' => 'Navigazione e marchio',
                'dati' => 'Tabella',
                'servizio' => 'Stati di servizio',
            ];
        @endphp

        <header class="sticky top-0 z-30 border-b border-border bg-surface print:hidden">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <x-brand-logo class="h-7 w-auto" alt="Easy Lab" />

                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-base font-semibold text-ink">Banco dei componenti</h1>
                    <p class="truncate text-xs text-ink-3">
                        {{ count($sezioni) }} sezioni · ogni pezzo porta i token che usa · <span class="font-mono">{{ app()->environment() }}</span>
                    </p>
                </div>

                <x-ui.selettore-tema />
            </div>
        </header>

        <nav class="mx-auto max-w-6xl px-4 pt-6" aria-label="Sezioni del banco">
            <ul class="flex flex-wrap gap-2">
                @foreach ($sezioni as $ancora => $titolo)
                    <li>
                        <a href="#{{ $ancora }}"
                           class="inline-flex min-h-11 items-center rounded-full border border-border bg-surface px-4 text-sm font-medium text-ink-2 hover:bg-surface-sunken hover:text-ink">
                            {{ $titolo }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <main class="mx-auto max-w-6xl space-y-10 px-4 py-8">
            @foreach ($sezioni as $ancora => $titolo)
                <section id="{{ $ancora }}" class="scroll-mt-24 space-y-4">
                    <h2 class="border-b border-border pb-2 text-lg font-semibold text-ink">{{ $titolo }}</h2>
                    @include('banco._'.$ancora)
                </section>
            @endforeach
        </main>

        <footer class="mx-auto max-w-6xl px-4 pb-12">
            <p class="text-xs text-ink-3">
                🔗 <span class="font-mono">docs/Design/Design System Base.md</span> (normativo) ·
                <span class="font-mono">docs/Design/design-system.html</span> (campione).
                Questa pagina non esiste fuori dall'ambiente <span class="font-mono">local</span>.
            </p>
        </footer>
    </div>
</x-guest-layout>

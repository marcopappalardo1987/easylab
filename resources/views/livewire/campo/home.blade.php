<div class="mx-auto w-full max-w-2xl px-4 py-6 sm:px-6">

    <h1 class="text-2xl font-bold tracking-tight text-ink">Campo</h1>
    <p class="mt-1 text-sm text-ink-2">
        Inquadra il QR sulla macchina, oppure scegli fra i tuoi interventi.
    </p>

    {{-- 1. Scansione QR --}}
    <x-ui.card class="mt-5" x-data="scannerQr" x-on:beforeunload.window="spegni()">
        {{-- `x-cloak` sui rami che dipendono da Alpine: senza, per un istante
             comparirebbero insieme il pulsante e il messaggio di fallback. --}}
        <div x-show="!attivo" x-cloak>
            <template x-if="supportato">
                <div>
                    <x-ui.button class="w-full" x-on:click="avvia()">
                        <span aria-hidden="true">📷</span> Scansiona QR
                    </x-ui.button>
                    <p class="mt-2 text-center text-xs text-ink-3">
                        La fotocamera si spegne appena il codice è letto.
                    </p>
                </div>
            </template>

            {{-- Nessuna simulazione dove l'API non c'è: il QR contiene un'URL
                 firmata (ADR-003) e la fotocamera di sistema la apre da sola.
                 Dire come si fa è più utile di un pulsante che non funziona. --}}
            <template x-if="!supportato">
                <p class="py-2 text-center text-sm text-ink-2">
                    <span aria-hidden="true">📷</span>
                    Inquadra il QR con la <strong>fotocamera del telefono</strong>:
                    aprirà direttamente la scheda della macchina.
                </p>
            </template>
        </div>

        <div x-show="attivo" x-cloak>
            {{-- `playsinline` o iOS aprirebbe il video a tutto schermo, uscendo
                 dalla pagina; `muted` perché senza, `play()` viene bloccata.

                 ⚠️ Il fondo era `bg-neutral-900`: non è una superficie della
                 pagina né un testo, è il riempimento dietro il flusso video
                 finché la fotocamera non dipinge il primo frame. Il ruolo più
                 vicino di DS §8.2 è lo **skeleton** (stessa riga di
                 `--surface-sunken`): un placeholder in attesa di contenuto. --}}
            <video x-ref="video" playsinline muted
                class="aspect-square w-full rounded-md bg-surface-sunken object-cover"></video>
            <x-ui.button variant="secondary" class="mt-3 w-full" x-on:click="spegni()">Annulla</x-ui.button>
        </div>

        <p x-show="errore" x-cloak x-text="errore" class="mt-3 text-sm text-bad-soft-ink"></p>

        {{-- Un QR può contenere qualunque cosa, e chiunque può stamparne uno e
             attaccarlo su una macchina: quello che non porta a questo sito si
             MOSTRA e non si segue.

             ⚠️ Il bordo era `border-warning-500`: come nel registro di audit,
             il campione non porta un bordo colorato sull'alert — lo fa solo
             il fondo — e il testo segue lo stesso `warn-soft-ink` del fondo. --}}
        <div x-show="codiceEstraneo" x-cloak class="mt-3 rounded-md border border-transparent bg-warn-soft p-3">
            <p class="text-sm font-medium text-warn-soft-ink">Questo codice non porta a Easy Lab.</p>
            <p class="mt-1 text-xs break-all text-warn-soft-ink" x-text="codiceEstraneo"></p>
        </div>
    </x-ui.card>

    {{-- 2. I miei interventi --}}
    <h2 class="mt-8 text-sm font-semibold tracking-wide text-ink-3 uppercase">
        I miei interventi ({{ $totale }})
    </h2>

    @if ($totale > $interventi->count())
        <p class="mt-2 text-xs text-ink-3">
            Qui sotto i {{ $interventi->count() }} più urgenti. Per gli altri, inquadra il QR
            sulla macchina o cercala fra gli strumenti.
        </p>
    @endif

    @forelse ($interventi as $intervento)
        @php $scaduto = $intervento->isScaduto(); @endphp
        <a href="{{ route('strumenti.show', $intervento->strumento_id) }}" wire:navigate
            wire:key="int-{{ $intervento->id }}"
            class="mt-3 flex items-start justify-between gap-3 rounded-lg border border-border bg-surface p-4 shadow-sm transition hover:border-brand-line hover:bg-brand-soft">
            <div class="min-w-0">
                <p class="truncate font-medium text-ink">
                    {{ $intervento->strumento?->nome ?? 'Macchina non più disponibile' }}
                </p>
                <p class="mt-0.5 truncate text-sm text-ink-2">{{ $intervento->descrizione }}</p>
                <p class="mt-1 text-xs text-ink-3">
                    {{-- Ubicazione: sul campo è come si trova la macchina, ed è
                         il motivo per cui ADR-030 è stato emendato. --}}
                    {{ $intervento->strumento?->percorsoUbicazione($nodi) }}
                </p>
            </div>
            <div class="shrink-0 text-right">
                {{-- Colore + simbolo + testo, mai il solo colore (DS §4). --}}
                <span class="{{ $scaduto ? 'text-bad-dot' : 'text-ink-2' }} text-sm font-medium tabular-nums">
                    <span aria-hidden="true">{{ $scaduto ? '✗' : '◷' }}</span>
                    {{ $intervento->data_scadenza->format('d/m/Y') }}
                </span>
                @if ($scaduto)
                    <span class="mt-0.5 block text-xs text-bad-dot">Scaduto</span>
                @endif
            </div>
        </a>
    @empty
        <x-ui.card class="mt-3">
            <p class="py-6 text-center text-sm text-ink-3">
                Nessun intervento assegnato a te da fare.
            </p>
        </x-ui.card>
    @endforelse
</div>

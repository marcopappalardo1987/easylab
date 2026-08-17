<div class="mx-auto w-full max-w-2xl px-4 py-6 sm:px-6">

    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Campo</h1>
    <p class="mt-1 text-sm text-neutral-600">
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
                    <p class="mt-2 text-center text-xs text-neutral-400">
                        La fotocamera si spegne appena il codice è letto.
                    </p>
                </div>
            </template>

            {{-- Nessuna simulazione dove l'API non c'è: il QR contiene un'URL
                 firmata (ADR-003) e la fotocamera di sistema la apre da sola.
                 Dire come si fa è più utile di un pulsante che non funziona. --}}
            <template x-if="!supportato">
                <p class="py-2 text-center text-sm text-neutral-600">
                    <span aria-hidden="true">📷</span>
                    Inquadra il QR con la <strong>fotocamera del telefono</strong>:
                    aprirà direttamente la scheda della macchina.
                </p>
            </template>
        </div>

        <div x-show="attivo" x-cloak>
            {{-- `playsinline` o iOS aprirebbe il video a tutto schermo, uscendo
                 dalla pagina; `muted` perché senza, `play()` viene bloccata. --}}
            <video x-ref="video" playsinline muted
                class="aspect-square w-full rounded-md bg-neutral-900 object-cover"></video>
            <x-ui.button variant="secondary" class="mt-3 w-full" x-on:click="spegni()">Annulla</x-ui.button>
        </div>

        <p x-show="errore" x-cloak x-text="errore" class="mt-3 text-sm text-danger-600"></p>

        {{-- Un QR può contenere qualunque cosa, e chiunque può stamparne uno e
             attaccarlo su una macchina: quello che non porta a questo sito si
             MOSTRA e non si segue. --}}
        <div x-show="codiceEstraneo" x-cloak class="mt-3 rounded-md border border-warning-500 bg-warning-100 p-3">
            <p class="text-sm font-medium text-neutral-800">Questo codice non porta a Easy Lab.</p>
            <p class="mt-1 text-xs break-all text-neutral-600" x-text="codiceEstraneo"></p>
        </div>
    </x-ui.card>

    {{-- 2. I miei interventi --}}
    <h2 class="mt-8 text-sm font-semibold tracking-wide text-neutral-400 uppercase">
        I miei interventi ({{ $totale }})
    </h2>

    @if ($totale > $interventi->count())
        <p class="mt-2 text-xs text-neutral-500">
            Qui sotto i {{ $interventi->count() }} più urgenti. Per gli altri, inquadra il QR
            sulla macchina o cercala fra gli strumenti.
        </p>
    @endif

    @forelse ($interventi as $intervento)
        @php $scaduto = $intervento->isScaduto(); @endphp
        <a href="{{ route('strumenti.show', $intervento->strumento_id) }}" wire:navigate
            wire:key="int-{{ $intervento->id }}"
            class="mt-3 flex items-start justify-between gap-3 rounded-lg border border-neutral-200 bg-white p-4 shadow-sm transition hover:border-primary-300 hover:bg-primary-50">
            <div class="min-w-0">
                <p class="truncate font-medium text-neutral-900">
                    {{ $intervento->strumento?->nome ?? 'Macchina non più disponibile' }}
                </p>
                <p class="mt-0.5 truncate text-sm text-neutral-600">{{ $intervento->descrizione }}</p>
                <p class="mt-1 text-xs text-neutral-400">
                    {{-- Ubicazione: sul campo è come si trova la macchina, ed è
                         il motivo per cui ADR-030 è stato emendato. --}}
                    {{ $intervento->strumento?->percorsoUbicazione($nodi) }}
                </p>
            </div>
            <div class="shrink-0 text-right">
                {{-- Colore + simbolo + testo, mai il solo colore (DS §4). --}}
                <span class="{{ $scaduto ? 'text-danger-600' : 'text-neutral-600' }} text-sm font-medium tabular-nums">
                    <span aria-hidden="true">{{ $scaduto ? '✗' : '◷' }}</span>
                    {{ $intervento->data_scadenza->format('d/m/Y') }}
                </span>
                @if ($scaduto)
                    <span class="mt-0.5 block text-xs text-danger-600">Scaduto</span>
                @endif
            </div>
        </a>
    @empty
        <x-ui.card class="mt-3">
            <p class="py-6 text-center text-sm text-neutral-400">
                Nessun intervento assegnato a te da fare.
            </p>
        </x-ui.card>
    @endforelse
</div>

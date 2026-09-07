<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6">

    <div class="flex items-baseline justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Guida</h1>
            <p class="mt-1 text-sm text-ink-2">
                Ogni funzione spiegata in un video breve, e sotto gli stessi passi da leggere.
            </p>
        </div>
        {{-- Il conteggio dice sempre la verità sul filtro: «3 di 5» non lascia
             dubbi sul perché l'indice si sia accorciato. --}}
        <p class="shrink-0 text-sm text-ink-3">
            @if ($trovate === $totali)
                {{ $totali }} {{ $totali === 1 ? 'guida' : 'guide' }}
            @else
                {{ $trovate }} di {{ $totali }}
            @endif
        </p>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">

        {{-- ══ Indice ══ --}}
        <aside class="lg:sticky lg:top-6 lg:self-start">
            <x-ui.input name="ricerca" wire:model.live.debounce.300ms="ricerca"
                placeholder="Cerca nella guida…" />

            @forelse ($gruppi as $argomento => $guide)
                <p class="mt-6 px-1 text-xs font-medium tracking-wide text-ink-3 uppercase">{{ $argomento }}</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($guide as $voce)
                        <li>
                            {{-- `wire:key` sul li: filtrando, Livewire riordina questa
                                 lista, e senza chiave riuserebbe il DOM sbagliato
                                 lasciando evidenziata la voce di prima. --}}
                            <button type="button" wire:click="apri('{{ $voce['slug'] }}')"
                                    wire:key="voce-{{ $voce['slug'] }}"
                                    @class([
                                        'block w-full rounded-md px-3 py-2.5 text-left transition',
                                        'bg-brand-soft text-brand-soft-ink' => $voce['slug'] === $slug,
                                        'text-ink-2 hover:bg-surface-sunken hover:text-ink' => $voce['slug'] !== $slug,
                                    ])>
                                <span class="block text-sm font-medium">{{ $voce['titolo'] }}</span>
                                <span class="mt-0.5 block text-xs opacity-75">
                                    {{ $voce['passi'] }} passi · {{ floor($voce['durata'] / 60) }}′{{ str_pad($voce['durata'] % 60, 2, '0', STR_PAD_LEFT) }}″
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @empty
                <p class="mt-6 rounded-md border border-border bg-surface px-4 py-8 text-center text-sm text-ink-3">
                    Nessuna guida per «{{ $ricerca }}».
                </p>
            @endforelse
        </aside>

        {{-- ══ Lettore ══ --}}
        <div>
            @if ($guida)
                <x-ui.card wire:key="guida-{{ $guida['slug'] }}">
                    <h2 class="text-xl font-bold tracking-tight text-ink">{{ $guida['titolo'] }}</h2>
                    <p class="mt-1 text-sm text-ink-2">{{ $guida['sottotitolo'] }}</p>

                    {{-- L'apertura della guida scritta: a chi serve e che cosa
                         si porta a casa. Sta SOPRA il video perché è ciò che fa
                         decidere se guardarlo. Nel filmato non c'è — lì la
                         stessa cosa la fa la testata. --}}
                    @if ($guida['premessa'])
                        <p class="mt-4 max-w-prose text-sm leading-relaxed text-ink-2">{{ $guida['premessa'] }}</p>
                    @endif

                    {{-- `wire:key` sul video, altrimenti cambiando guida Livewire
                         riusa l'elemento e il lettore resta sul filmato di prima.
                         `preload=metadata`: la durata serve subito, i 12 MB no. --}}
                    <video wire:key="video-{{ $guida['slug'] }}"
                           x-ref="lettore"
                           class="mt-5 w-full rounded-lg border border-border bg-canvas"
                           controls preload="metadata" playsinline
                           @if ($guida['copertina']) poster="{{ $guida['copertina'] }}" @endif>
                        <source src="{{ $guida['video'] }}" type="video/mp4">
                        Il tuo browser non riproduce questo video.
                        <a href="{{ $guida['video'] }}" class="text-brand">Scaricalo</a> per guardarlo.
                    </video>

                    <div class="mt-8 space-y-8">
                        @foreach ($guida['capitoli'] as $capitolo)
                            <section>
                                @if ($capitolo['titolo'])
                                    <div class="flex items-baseline gap-3">
                                        <span class="text-xs font-medium tracking-wide text-brand uppercase">{{ $capitolo['occhiello'] }}</span>
                                        <h3 class="font-semibold text-ink">{{ $capitolo['titolo'] }}</h3>
                                    </div>
                                @endif

                                {{-- Nel video il cartello dice solo il titolo, e
                                     per due secondi e mezzo. Qui c'è lo spazio
                                     per dire perché questa parte esiste. --}}
                                @if ($capitolo['premessa'])
                                    <p class="mt-2 max-w-prose text-sm leading-relaxed text-ink-2">{{ $capitolo['premessa'] }}</p>
                                @endif

                                <ol class="mt-3 space-y-2">
                                    @foreach ($capitolo['passi'] as $passo)
                                        <li>
                                            {{-- Ogni passo scritto salta al proprio istante nel
                                                 video: sono lo stesso passo raccontato in due modi,
                                                 e poterci passare da uno all'altro è metà del
                                                 motivo per cui stanno nella stessa pagina.
                                                 ⚠️ `items-start` e non `items-baseline`: il testo
                                                 scritto è un paragrafo, e col baseline numero e
                                                 minutaggio si allineerebbero all'ULTIMA riga. --}}
                                            <button type="button"
                                                    x-on:click="$refs.lettore.currentTime = {{ $passo['inizio'] }}; $refs.lettore.play()"
                                                    class="flex w-full items-start gap-3 rounded-md px-3 py-2 text-left transition hover:bg-surface-sunken">
                                                <span class="w-6 shrink-0 text-sm font-semibold leading-relaxed text-ink-3 tabular-nums">{{ $passo['n'] }}</span>
                                                <span class="text-sm leading-relaxed font-medium text-ink">{{ $passo['testo'] }}</span>
                                                <span class="ml-auto shrink-0 text-xs leading-relaxed text-ink-3 tabular-nums">
                                                    {{ floor($passo['inizio'] / 60) }}:{{ str_pad(floor($passo['inizio']) % 60, 2, '0', STR_PAD_LEFT) }}
                                                </span>
                                            </button>

                                            {{-- L'approfondimento sta FUORI dal bottone: dentro,
                                                 un paragrafo diventerebbe un bersaglio di click
                                                 grande quanto sé stesso, e il video partirebbe
                                                 mentre si legge. È ciò che la guida scritta ha in
                                                 più del video, e la ragione per cui non è una
                                                 trascrizione dei sottotitoli. --}}
                                            @if ($passo['dettaglio'])
                                                <p class="pr-3 pb-3 pl-12 text-sm leading-relaxed text-ink-2">{{ $passo['dettaglio'] }}</p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </section>
                        @endforeach

                        @if ($guida['chiusura'])
                            <section class="rounded-lg bg-surface-sunken p-5">
                                <h3 class="font-semibold text-ink">{{ $guida['chiusura']['titolo'] }}</h3>
                                <ul class="mt-3 space-y-2">
                                    @foreach ($guida['chiusura']['punti'] as $punto)
                                        <li class="flex gap-3 text-sm text-ink-2">
                                            <span class="text-brand" aria-hidden="true">·</span>
                                            <span>{{ $punto }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </div>
                </x-ui.card>
            @else
                <x-ui.card>
                    <p class="py-12 text-center text-sm text-ink-3">
                        Scegli una guida dall'indice.
                    </p>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>

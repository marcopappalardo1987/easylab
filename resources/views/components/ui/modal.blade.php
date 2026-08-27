@props([
    'title' => null,
    'close' => null,
])

<div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true"
    @if ($close) x-data x-on:keydown.escape.window="$wire.{{ $close }}()" @endif>
    {{-- Backdrop — 🔗 DS §8.2. Il velo ha un token suo, ed è l'unico che il
         campione non aveva: il grigio a opacità fissa di prima, sul fondo blu
         notte del tema scuro, era quasi invisibile e la modale smetteva di
         staccarsi da ciò che copre. --}}
    <div class="fixed inset-0 bg-overlay"
        @if ($close) wire:click="{{ $close }}" @endif></div>

    {{-- Panel — campione `.el-dialog`: superficie piena, bordo e ombra densa.
         Il bordo non è decorazione: in tema scuro separa il pannello dal velo,
         che è scuro quanto lui. --}}
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-lg rounded-lg border border-border bg-surface p-6 shadow-md">
            <div class="flex items-start justify-between gap-4">
                @if ($title)
                    <h2 class="text-lg font-semibold text-ink">{{ $title }}</h2>
                @endif
                @if ($close)
                    {{-- ⚠️ 44×44 di bersaglio (DS §5.1): è un comando a sola
                         icona, cioè il caso in cui la regola morde davvero. Il
                         margine negativo compensa il riquadro, quindi l'icona
                         resta dov'era e l'intestazione non cresce. --}}
                    <button type="button" wire:click="{{ $close }}"
                        class="-m-3 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-2 hover:bg-surface-sunken hover:text-ink"
                        aria-label="Chiudi">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endif
            </div>

            <div class="mt-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>

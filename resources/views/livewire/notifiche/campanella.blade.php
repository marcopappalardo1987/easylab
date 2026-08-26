<div class="relative" x-data="{ open: false }" @click.outside="open = false; $wire.aperta = false">

    {{-- Il pulsante: icona + pallino dei non letti --}}
    <button type="button"
            @click="open = !open; if (open) $wire.apri()"
            title="Notifiche"
            aria-label="Notifiche{{ $nonLette > 0 ? " ({$nonLette} non lette)" : '' }}"
            class="relative flex h-10 w-10 items-center justify-center rounded-md text-ink-2 hover:bg-surface-sunken">
        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>

        @if ($nonLette > 0)
            {{-- Il badge segue lo stesso `bg-bad-dot text-ink-inverse` del bottone
                 pericolo (DS §8.2): in tema scuro `--ink-inverse` scende a un
                 quasi-nero, perché `--bad-dot` lì è un rosso più chiaro. --}}
            <span class="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-bad-dot px-1 text-xs font-semibold text-ink-inverse">
                {{ $nonLette > 9 ? '9+' : $nonLette }}
            </span>
        @endif
    </button>

    {{-- Il pannello --}}
    <div x-show="open" x-cloak x-transition
         class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-md border border-border bg-surface shadow-md">

        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <p class="text-sm font-medium text-ink">Notifiche</p>
            @if ($nonLette > 0)
                <button type="button" wire:click="segnaTutteLette"
                        class="text-xs font-medium text-brand hover:text-brand-hover">
                    Segna tutte come lette
                </button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            @forelse ($notifiche as $notifica)
                @php
                    $dati = $notifica->data;
                    $scadute = $dati['scadute'] ?? 0;
                    $imminenti = $dati['imminenti'] ?? 0;
                @endphp
                <div class="border-b border-border px-4 py-3 last:border-b-0 {{ $notifica->read_at === null ? 'bg-brand-soft' : '' }}">
                    <p class="text-sm font-medium text-ink">{{ $dati['ente_nome'] ?? 'Easy Lab' }}</p>
                    <p class="mt-0.5 text-sm text-ink-2">
                        @if ($scadute > 0)
                            {{ $scadute }} {{ $scadute === 1 ? 'scadenza superata' : 'scadenze superate' }}@if ($imminenti > 0), @endif
                        @endif
                        @if ($imminenti > 0)
                            {{ $imminenti }} in arrivo
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-ink-3">{{ $notifica->created_at->diffForHumans() }}</p>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-ink-3">Nessuna notifica.</p>
            @endforelse
        </div>

        <a href="{{ route('strumenti.index') }}"
           class="block border-t border-border px-4 py-2.5 text-center text-sm text-ink-2 hover:bg-surface-sunken">
            Vai alle macchine
        </a>
    </div>
</div>

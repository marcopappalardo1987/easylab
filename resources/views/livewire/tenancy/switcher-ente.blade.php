<div>
    @if ($nomeEnte !== null)
        @if ($tendina)
            {{-- Più sedi: nome cliccabile con tendina (pattern del menù utente). --}}
            {{-- ⛔ La visibilità è governata da `$wire.aperto`, cioè da una property
                 del SERVER, e non da un flag locale di Alpine. Con `x-data="{ open }"`
                 il pannello non si apriva mai: il click accendeva `open`, `apri()`
                 faceva un giro sul server, Livewire ridisegnava il frammento e
                 Alpine ripartiva da `open: false` — il pannello si richiudeva
                 nello stesso istante in cui arrivavano i dati per riempirlo.
                 Segnalato da Marco il 28 Ago 2026, con la console pulita: non
                 c'era nessun errore da vedere.

                 ⚠️ Il caricamento pigro resta (l'elenco arriva solo a tendina
                 aperta, e questo componente si monta su OGNI pagina): è proprio
                 quel giro sul server a rendere impossibile tenere lo stato di là.
                 Guidandolo dalla property, lo stato sopravvive al ridisegno. --}}
            <div class="relative" x-data @click.outside="$wire.aperto = false">
                <button type="button"
                        @click="$wire.aperto ? $wire.aperto = false : $wire.apri()"
                        class="flex max-w-56 items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-ink-2 hover:bg-surface-sunken">
                    <svg class="h-4 w-4 shrink-0 text-ink-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    <span class="truncate">{{ $nomeEnte }}</span>
                    <svg class="h-3.5 w-3.5 shrink-0 text-ink-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                </button>

                <div x-show="$wire.aperto" x-cloak x-transition
                     class="absolute left-0 z-30 mt-2 w-64 overflow-hidden rounded-md border border-border bg-surface shadow-md">
                    <p class="border-b border-border px-4 py-2 text-xs font-medium tracking-wide text-ink-3 uppercase">Le tue sedi</p>
                    @foreach ($altreSedi as $sede)
                        <button type="button" wire:click="passa({{ $sede->id }})"
                                class="block w-full truncate px-4 py-2.5 text-left text-sm text-ink-2 hover:bg-surface-sunken">
                            {{ $sede->nome }}
                        </button>
                    @endforeach
                </div>
            </div>
        @else
            {{-- Una sede sola: il contesto senza tendina. (L'impersonazione non
                 la sopprime più — vedi `SwitcherEnte::render()`.) --}}
            <span class="flex max-w-56 items-center gap-1.5 px-2 text-sm font-medium text-ink-2">
                <svg class="h-4 w-4 shrink-0 text-ink-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                <span class="truncate">{{ $nomeEnte }}</span>
            </span>
        @endif
    @endif
</div>

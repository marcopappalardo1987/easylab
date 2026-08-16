<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Ricambi</h1>
    <p class="mt-1 text-sm text-neutral-600">
        Cerca un pezzo e vedi su quali macchine è montato, e in quali laboratori.
    </p>

    <x-ui.card class="mt-6">
        <x-ui.input name="search" wire:model.live.debounce.300ms="search"
            placeholder="Cerca un ricambio… (es. guarnizione O-Ring)" autofocus />
    </x-ui.card>

    @if (! $puoVedereMontaggi)
        {{-- Il catalogo si potrebbe anche sfogliare, ma questa pagina risponde a
             una domanda sui montaggi: senza quel permesso non ha una risposta. --}}
        <x-ui.card class="mt-4">
            <p class="py-6 text-center text-sm text-neutral-400">
                Non hai il permesso di vedere i ricambi montati sulle macchine.
            </p>
        </x-ui.card>
    {{-- `$domandaPosta` e non `blank(trim($search))`: la regola su cosa sia una
         casella vuota vive nel componente (ci rientra l'NBSP, che `trim()` non
         toglie), e riscriverla qui creerebbe una seconda definizione libera di
         divergere proprio sul carattere per cui la prima esiste. --}}
    @elseif (! $domandaPosta)
        <x-ui.card class="mt-4">
            <p class="py-6 text-center text-sm text-neutral-400">
                Scrivi il nome di un pezzo per sapere dove è montato.
            </p>
        </x-ui.card>
    @elseif ($risultati->isEmpty())
        <x-ui.card class="mt-4">
            <p class="py-6 text-center text-sm text-neutral-400">
                Nessun ricambio in catalogo corrisponde a «{{ trim($search) }}».
            </p>
        </x-ui.card>
    @else
        @if ($troncato)
            <div class="mt-4 rounded-md border border-warning-500 bg-warning-50 px-4 py-3 text-sm text-neutral-800">
                La ricerca corrisponde a più di {{ App\Livewire\Ricambi\RicercaRicambi::MAX_RISULTATI }} pezzi:
                qui sotto ci sono i primi. Restringi il nome per vederli tutti.
            </div>
        @endif

        <div class="mt-4 space-y-3">
            @foreach ($risultati as $r)
                <x-ui.card wire:key="ricambio-{{ $r['id'] }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-sm font-semibold text-neutral-900">
                            {{ $r['nome'] }}
                            @if ($r['codice'])
                                <span class="ml-1 font-normal text-neutral-400">{{ $r['codice'] }}</span>
                            @endif
                        </h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge variant="primary">
                                {{ $r['totale_macchine'] }} {{ $r['totale_macchine'] === 1 ? 'macchina' : 'macchine' }}
                            </x-ui.badge>
                            <x-ui.badge>{{ $r['totale_pezzi'] }} pz</x-ui.badge>
                            @if ($r['non_montati'] > 0)
                                {{-- ADR-020: registrato non vuol dire montato, e la
                                     differenza pesa sul semaforo della macchina. --}}
                                <x-ui.badge variant="warning">
                                    {{ $r['non_montati'] }} non ancora {{ $r['non_montati'] === 1 ? 'montato' : 'montati' }}
                                </x-ui.badge>
                            @endif
                        </div>
                    </div>

                    @if ($r['macchine']->isEmpty())
                        <p class="mt-3 text-sm text-neutral-400">
                            In catalogo, ma non risulta montato su nessuna macchina che puoi vedere.
                        </p>
                    @else
                        {{-- Laboratori: la lettura d'insieme, con il conteggio delle macchine. --}}
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($r['laboratori'] as $lab)
                                <a href="{{ route('strumenti.index', ['ubicazioneId' => $lab['id']]) }}" wire:navigate
                                    class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 px-3 py-1 text-xs text-neutral-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700">
                                    {{ $lab['nome'] }}
                                    <span class="font-semibold text-neutral-400">{{ $lab['macchine'] }}</span>
                                </a>
                            @endforeach
                        </div>

                        {{-- Macchine: il dettaglio, con il link alla scheda. --}}
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                                    <tr>
                                        <th class="py-2 pr-4 font-semibold">Macchina</th>
                                        <th class="py-2 pr-4 font-semibold">Matricola</th>
                                        <th class="py-2 pr-4 font-semibold">Laboratorio</th>
                                        <th class="py-2 pr-4 font-semibold">Pezzi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100">
                                    @foreach ($r['macchine'] as $m)
                                        <tr wire:key="ric-{{ $r['id'] }}-str-{{ $m['id'] }}">
                                            <td class="py-2 pr-4 font-medium">
                                                <a href="{{ route('strumenti.show', $m['id']) }}" wire:navigate
                                                    class="text-primary-600 hover:text-primary-700">{{ $m['nome'] }}</a>
                                            </td>
                                            <td class="py-2 pr-4 text-neutral-600">{{ $m['matricola'] ?: '—' }}</td>
                                            <td class="py-2 pr-4 text-neutral-600">{{ $m['laboratorio'] }}</td>
                                            <td class="py-2 pr-4 tabular-nums text-neutral-600">
                                                {{ $m['pezzi'] }}
                                                @if ($m['non_montati'] > 0)
                                                    <span class="ml-1 text-xs text-warning-800">
                                                        ({{ $m['non_montati'] }} da montare)
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>

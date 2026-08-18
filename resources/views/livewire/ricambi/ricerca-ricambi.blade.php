<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Ricambi</h1>
    <p class="mt-1 text-sm text-neutral-600">
        Cerca un pezzo e vedi su quali macchine è montato, e in quali laboratori.
    </p>

    @if ($esitoUnione)
        <div class="mt-4 rounded-md border border-success-500 bg-success-100 px-4 py-3 text-sm text-neutral-800">
            <span aria-hidden="true">✓</span> {{ $esitoUnione }}
        </div>
    @endif

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
                            {{-- ADR-008: unire doppioni è l'antidoto al costo di
                                 ADR-022 — la chiave è il NOME, quindi «O-Ring» e
                                 «OR» diventano due voci per lo stesso pezzo. Sta
                                 QUI e non in una pagina di amministrazione a
                                 parte perché è guardando i risultati affiancati
                                 che si riconosce un doppione. --}}
                            @can('ricambi.merge')
                                <button type="button" wire:click="apriUnione({{ $r['id'] }})"
                                    class="text-xs font-medium text-primary-600 hover:text-primary-700">Unisci…</button>
                            @endcan
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
    {{-- Modale di unione (ADR-008) --}}
    @if ($unendoId)
        @php
            $sorgente = $risultati->firstWhere('id', $unendoId);
            $altre = $risultati->reject(fn ($x) => $x['id'] === $unendoId);
        @endphp
        <x-ui.modal title="Unisci voce di catalogo" close="chiudiUnione">
            <form wire:submit="unisci" class="space-y-5">
                <p class="text-sm text-neutral-600">
                    «<span class="font-medium text-neutral-900">{{ $sorgente['nome'] ?? '' }}</span>» sparirà dal
                    catalogo e i suoi <span class="font-medium">{{ $sorgente['totale_pezzi'] ?? 0 }}</span> pezzi su
                    <span class="font-medium">{{ $sorgente['totale_macchine'] ?? 0 }}</span> macchine passeranno
                    alla voce che scegli.
                </p>

                @if ($altre->isEmpty())
                    {{-- Le destinazioni sono le ALTRE voci TROVATE: si unisce ciò
                         che si sta guardando, non una voce qualunque pescata da un
                         elenco di migliaia — è la ricerca che ha già fatto il
                         lavoro di mettere i due doppioni uno accanto all'altro. --}}
                    <p class="rounded-md border border-warning-500 bg-warning-100 p-3 text-sm text-neutral-800">
                        Questa ricerca ha trovato una voce sola. Cerca un termine che mostri
                        anche il doppione, così puoi scegliere dove unirla.
                    </p>
                @else
                    <div>
                        <label for="destinazioneId" class="block text-sm font-medium text-neutral-800">Unisci in</label>
                        <select id="destinazioneId" wire:model="destinazioneId"
                            class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600">
                            <option value="">— Scegli la voce da tenere —</option>
                            @foreach ($altre as $altra)
                                <option value="{{ $altra['id'] }}">{{ $altra['nome'] }}@if ($altra['codice']) ({{ $altra['codice'] }})@endif — {{ $altra['totale_macchine'] }} macchine</option>
                            @endforeach
                        </select>
                        @error('destinazioneId')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                    </div>

                    <p class="text-xs text-neutral-400">
                        I montaggi non vengono ricreati ma rietichettati: id, date e garanzie
                        restano quelli. La voce unita finisce nel cestino, non cancellata.
                    </p>
                @endif

                <div class="flex justify-end gap-3 max-md:flex-col-reverse">
                    <x-ui.button variant="secondary" wire:click="chiudiUnione" class="max-md:w-full">Annulla</x-ui.button>
                    @if ($altre->isNotEmpty())
                        <x-ui.button type="submit" wire:loading.attr="disabled" class="max-md:w-full">Unisci</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>

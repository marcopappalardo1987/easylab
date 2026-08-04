@php
    $arrow = fn ($col) => $sortBy === $col ? ($sortDir === 'asc' ? '↑' : '↓') : '';

    // Colonna "Prossima scadenza" (wireframe §1): la più vicina fra il prossimo
    // intervento aperto e la prossima garanzia (ADR-004). "Scaduto" viene dai
    // model (isScaduto/isScaduta) — definizione canonica, mai riscritta qui.
    //
    // Nota S4: qui si mostra il DETTAGLIO, non l'aggregato del pallino. Quando
    // esisteranno garanzie ricambio raggiungibili dallo strumento, questa
    // colonna dovrà filtrarle per `garanzie.ricambio.view`.
    $etichettaScadenza = function (string $tipo, $data, bool $scaduta) {
        if ($scaduta) {
            return $tipo.' — scaduta';
        }

        $giorni = (int) today()->diffInDays($data);

        return $giorni === 0 ? $tipo.' — oggi' : "{$tipo} tra {$giorni} gg";
    };

    $scadenzaLabel = function ($prossimo, $garanzia) use ($etichettaScadenza) {
        // A parità di data vince l'intervento: porta con sé il tipo (Taratura e
        // certificazione, Manutenzione…), più informativo del generico "Garanzia".
        $vinceGaranzia = $garanzia !== null
            && ($prossimo === null || $garanzia->data_scadenza_effettiva->lt($prossimo->data_scadenza));

        if ($vinceGaranzia) {
            return $etichettaScadenza('Garanzia', $garanzia->data_scadenza_effettiva, $garanzia->isScaduta());
        }

        if ($prossimo === null) {
            return '—';
        }

        return $etichettaScadenza($prossimo->tipo->label(), $prossimo->data_scadenza, $prossimo->isScaduto());
    };
@endphp

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Strumenti</h1>
        @can('strumenti.create')
            <x-ui.button variant="secondary" :href="route('strumenti.import')" wire:navigate>⬇ Importa CSV</x-ui.button>
        @endcan
    </div>

    @include('livewire.strumenti._tabs')

    {{-- Filtri --}}
    <x-ui.card class="mt-6">
        @php
            // Il select Ente compare solo se l'utente ne vede più di uno
            // (oggi mai, ADR-018; con i Rivenditori V1.1 sì).
            $mostraEnti = $enti->count() > 1;
        @endphp

        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-56 flex-1">
                <x-ui.input name="search" wire:model.live.debounce.300ms="search"
                    placeholder="Cerca per nome, modello, matricola…" />
            </div>

            @if ($mostraEnti)
                <select wire:model.live="enteId"
                    class="block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none sm:w-56">
                    <option value="">Tutti gli Enti</option>
                    @foreach ($enti as $ente)
                        <option value="{{ $ente->id }}">{{ $ente->nome }}</option>
                    @endforeach
                </select>
            @endif

            <select wire:model.live="ubicazioneId"
                class="block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none sm:w-64">
                <option value="">Tutte le ubicazioni</option>
                @foreach ($nodi as $nodo)
                    <option value="{{ $nodo->id }}">{{ $nodo->nome }}</option>
                @endforeach
            </select>

            {{-- Filtro semaforo (ADR-005): stato effettivo, cioè forzato se c'è. --}}
            <select wire:model.live="stato"
                class="block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none sm:w-52">
                <option value="">Tutti gli stati</option>
                <option value="arancione">◐ Azione richiesta</option>
                <option value="verde">● In regola</option>
                <option value="rosso">■ Non idoneo</option>
            </select>

            {{-- Obsolescenza (ADR-014): segnalazione sull'età, indipendente dal semaforo. --}}
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-neutral-600">
                <input type="checkbox" wire:model.live="soloObsoleti"
                    class="rounded border-neutral-300 text-primary-600 focus:ring-primary-600">
                ⏳ Solo obsoleti
            </label>
        </div>
    </x-ui.card>

    {{-- Tabella --}}
    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                    <tr>
                        {{-- Tutte le colonne sono ordinabili: le tre derivate (stato,
                             ubicazione, prossima scadenza) via sottoquery lato DB. --}}
                        @foreach ([
                            'stato' => 'Stato',
                            'nome' => 'Nome',
                            'modello' => 'Modello',
                            'matricola' => 'Matricola',
                            'ubicazione' => 'Ubicazione',
                            'data_installazione' => 'Installazione',
                            'prossima_scadenza' => 'Prossima scadenza',
                        ] as $col => $label)
                            <th class="px-4 py-3 font-semibold">
                                <button type="button" wire:click="sort('{{ $col }}')"
                                    class="inline-flex items-center gap-1 text-left uppercase hover:text-neutral-700">
                                    {{ $label }} <span class="text-primary-600">{{ $arrow($col) }}</span>
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($strumenti as $s)
                        <tr wire:key="str-{{ $s->id }}" class="cursor-pointer hover:bg-neutral-50"
                            onclick="window.location='{{ route('strumenti.show', $s) }}'">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1">
                                    <x-ui.semaforo :stato="$semafori[$s->id]" />
                                    <x-ui.semaforo-forzato :strumento="$s" />
                                </span>
                            </td>
                            <td class="px-4 py-3 font-medium text-neutral-900">
                                <a href="{{ route('strumenti.show', $s) }}" wire:navigate class="hover:text-primary-700">{{ $s->nome }}</a>
                            </td>
                            <td class="px-4 py-3 text-neutral-600">{{ $s->modello ?: '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $s->matricola ?: '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">
                                @php $percorso = $percorsi[$s->unita_organizzativa_id] ?? []; @endphp
                                @if ($percorso)
                                    @foreach ($percorso as $segmento)
                                        @if (! $loop->first)<span class="text-neutral-300">›</span>@endif
                                        <span>{{ $segmento }}</span>
                                    @endforeach
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-neutral-600">
                                {{ $s->data_installazione?->format('d/m/Y') ?: '—' }}
                                <x-ui.obsoleto :strumento="$s" />
                            </td>
                            @php
                                $prossimo = $prossimi[$s->id] ?? null;
                                $garanzia = $garanzieMin[$s->id] ?? null;
                                $inRitardo = $prossimo?->isScaduto() || $garanzia?->isScaduta();
                            @endphp
                            <td class="px-4 py-3 whitespace-nowrap {{ $inRitardo ? 'font-medium text-warning-800' : 'text-neutral-600' }}">
                                {{ $scadenzaLabel($prossimo, $garanzia) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-neutral-400">
                                {{ (filled($search) || $ubicazioneId) ? 'Nessun risultato per i filtri applicati.' : 'Nessuno strumento.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-neutral-600">
            <label for="perPage">Righe per pagina</label>
            <select id="perPage" wire:model.live="perPage"
                class="rounded-md border border-neutral-200 py-1.5 pr-8 pl-2 text-sm text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                @foreach ($this->opzioniPerPage() as $opzione)
                    <option value="{{ $opzione }}">{{ $opzione }}</option>
                @endforeach
            </select>
            <span class="text-neutral-400">
                {{ $strumenti->firstItem() ?? 0 }}–{{ $strumenti->lastItem() ?? 0 }} di {{ $strumenti->total() }}
            </span>
        </div>

        <div class="flex-1">
            {{ $strumenti->links() }}
        </div>
    </div>
</div>

@php
    $arrow = fn ($col) => $sortBy === $col ? ($sortDir === 'asc' ? '↑' : '↓') : '';
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
        </div>
    </x-ui.card>

    {{-- Tabella --}}
    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                    <tr>
                        @foreach (['nome' => 'Nome', 'modello' => 'Modello', 'matricola' => 'Matricola'] as $col => $label)
                            <th class="px-4 py-3 font-semibold">
                                <button type="button" wire:click="sort('{{ $col }}')" class="inline-flex items-center gap-1 uppercase hover:text-neutral-700">
                                    {{ $label }} <span class="text-primary-600">{{ $arrow($col) }}</span>
                                </button>
                            </th>
                        @endforeach
                        <th class="px-4 py-3 font-semibold">Ubicazione</th>
                        <th class="px-4 py-3 font-semibold">
                            <button type="button" wire:click="sort('data_installazione')" class="inline-flex items-center gap-1 uppercase hover:text-neutral-700">
                                Installazione <span class="text-primary-600">{{ $arrow('data_installazione') }}</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($strumenti as $s)
                        <tr wire:key="str-{{ $s->id }}" class="cursor-pointer hover:bg-neutral-50"
                            onclick="window.location='{{ route('strumenti.show', $s) }}'">
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
                            <td class="px-4 py-3 text-neutral-600">{{ $s->data_installazione?->format('d/m/Y') ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-neutral-400">
                                {{ (filled($search) || $ubicazioneId) ? 'Nessun risultato per i filtri applicati.' : 'Nessuno strumento.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4">
        {{ $strumenti->links() }}
    </div>
</div>

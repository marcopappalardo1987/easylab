@php
    $arrow = fn ($col) => $sortBy === $col ? ($sortDir === 'asc' ? '↑' : '↓') : '';
@endphp

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Strumenti</h1>

    {{-- Filtri --}}
    <x-ui.card class="mt-6">
        <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
            <x-ui.input name="search" wire:model.live.debounce.300ms="search"
                placeholder="Cerca per nome, modello, matricola…" />

            <div>
                <select wire:model.live="ubicazioneId"
                    class="block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none sm:w-64">
                    <option value="">Tutte le ubicazioni</option>
                    @foreach ($nodi as $nodo)
                        <option value="{{ $nodo->id }}">{{ $nodo->nome }}</option>
                    @endforeach
                </select>
            </div>
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
                                <button type="button" wire:click="sort('{{ $col }}')" class="inline-flex items-center gap-1 hover:text-neutral-700">
                                    {{ $label }} <span class="text-primary-600">{{ $arrow($col) }}</span>
                                </button>
                            </th>
                        @endforeach
                        <th class="px-4 py-3 font-semibold">Ubicazione</th>
                        <th class="px-4 py-3 font-semibold">
                            <button type="button" wire:click="sort('data_installazione')" class="inline-flex items-center gap-1 hover:text-neutral-700">
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
                            <td class="px-4 py-3 text-neutral-600">{{ $s->unita?->nome ?: '—' }}</td>
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

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Strumenti</h1>
    <p class="mt-1 text-sm text-neutral-600">In quali laboratori è installato un modello, e quante unità.</p>

    @include('livewire.strumenti._tabs')

    {{-- Ricerca --}}
    <x-ui.card class="mt-6">
        <x-ui.input name="search" wire:model.live.debounce.300ms="search" placeholder="Cerca un modello… (es. AC-200)" />
    </x-ui.card>

    {{-- Modelli --}}
    @if ($modelli->isEmpty())
        <x-ui.card class="mt-4">
            <p class="py-6 text-center text-sm text-neutral-400">
                {{ filled($search) ? 'Nessun modello corrisponde alla ricerca.' : 'Nessuno strumento registrato.' }}
            </p>
        </x-ui.card>
    @else
        <div class="mt-4 space-y-3">
            @foreach ($modelli as $m)
                <x-ui.card wire:key="modello-{{ $loop->index }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-neutral-900">{{ $m['modello'] }}</h2>
                        <x-ui.badge variant="primary">{{ $m['totale'] }} unità</x-ui.badge>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($m['laboratori'] as $lab)
                            <a href="{{ route('strumenti.index', ['search' => $m['modello'], 'ubicazioneId' => $lab['id']]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 px-3 py-1 text-xs text-neutral-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700">
                                {{ $lab['nome'] }}
                                <span class="font-semibold text-neutral-400">{{ $lab['conteggio'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>

@php
    $enteRoot = $roots->firstWhere('tipo', \App\Enums\TipoUnitaOrganizzativa::Ente);
@endphp

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Anagrafica</h1>
            @if ($enteRoot)
                <p class="mt-1 text-sm text-neutral-600">Ente: <span class="font-medium text-neutral-800">{{ $enteRoot->nome }}</span></p>
            @endif
        </div>

        @can('unita_organizzativa.create')
            @if ($enteRoot)
                <x-ui.button wire:click="addChild({{ $enteRoot->id }})">
                    + Aggiungi dipartimento
                </x-ui.button>
            @endif
        @endcan
    </div>

    @if ($notice)
        <div class="mt-4 rounded-md border border-warning-500/30 bg-warning-100 px-3 py-2 text-sm text-warning-800">
            {{ $notice }}
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">

        {{-- Albero --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-neutral-600">Alberatura</h2>
            <div class="mt-3 space-y-1">
                @forelse ($roots as $node)
                    @include('livewire.anagrafica._node', ['node' => $node, 'childrenByParent' => $childrenByParent, 'depth' => 0])
                @empty
                    <p class="text-sm text-neutral-400">Nessun nodo visibile.</p>
                @endforelse
            </div>
        </x-ui.card>

        {{-- Pannello destro: dettaglio nodo + placeholder strumenti --}}
        <x-ui.card>
            @if ($selectedNode)
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-neutral-900">{{ $selectedNode->nome }}</h2>
                        <x-ui.badge variant="primary" class="mt-1">{{ ucfirst($selectedNode->tipo->value) }}</x-ui.badge>
                    </div>
                    @can('unita_organizzativa.update')
                        <x-ui.button variant="secondary" wire:click="edit({{ $selectedNode->id }})">Modifica</x-ui.button>
                    @endcan
                </div>
                @if ($selectedNode->note)
                    <p class="mt-4 text-sm text-neutral-600">{{ $selectedNode->note }}</p>
                @endif

                <hr class="my-6 border-neutral-200">

                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-neutral-600">Strumenti</p>
                    @can('strumenti.create')
                        @if ($selectedNode->tipo !== \App\Enums\TipoUnitaOrganizzativa::Ente)
                            <x-ui.button variant="secondary" wire:click="addStrumento">+ Aggiungi strumento</x-ui.button>
                        @endif
                    @endcan
                </div>

                @if ($selectedNode->tipo === \App\Enums\TipoUnitaOrganizzativa::Ente)
                    <p class="mt-2 text-sm text-neutral-400">Gli strumenti si collocano nei dipartimenti o sotto-laboratori.</p>
                @elseif ($strumenti->isEmpty())
                    <p class="mt-2 text-sm text-neutral-400">Nessuno strumento in questo nodo.</p>
                @else
                    <ul class="mt-3 divide-y divide-neutral-100">
                        @foreach ($strumenti as $s)
                            <li>
                                <a href="{{ route('strumenti.show', $s) }}" wire:navigate
                                    class="flex items-center justify-between gap-3 py-2.5 hover:text-primary-700">
                                    <span class="truncate text-sm font-medium text-neutral-800">{{ $s->nome }}</span>
                                    <span class="shrink-0 text-xs text-neutral-400">{{ $s->modello }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <div class="flex h-full min-h-40 items-center justify-center text-center">
                    <p class="text-sm text-neutral-400">Seleziona un nodo per vederne i dettagli.</p>
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Modale create/edit --}}
    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Modifica nodo' : 'Nuovo nodo'" close="closeForm">
            <form wire:submit="save" class="space-y-5">
                <p class="text-sm text-neutral-600">
                    Tipo: <span class="font-medium text-neutral-800">{{ ucfirst($tipo) }}</span>
                </p>

                <x-ui.input name="nome" label="Nome" wire:model="nome" placeholder="Es. Dipartimento Diagnostica" autofocus />
                <x-ui.textarea name="note" label="Note (opzionale)" wire:model="note" />

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeForm">Annulla</x-ui.button>
                    <x-ui.button type="submit">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Modale conferma eliminazione --}}
    @if ($deletingId)
        <x-ui.modal title="Conferma eliminazione">
            <p class="text-sm text-neutral-600">Eliminare questo nodo? L'operazione è reversibile (soft delete).</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete">Elimina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif

    {{-- Modale nuovo strumento --}}
    @if ($showStrumentoForm)
        <x-ui.modal title="Nuovo strumento" close="closeStrumentoForm">
            <form wire:submit="saveStrumento" class="space-y-5">
                @include('livewire.strumenti._form-fields')

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeStrumentoForm">Annulla</x-ui.button>
                    <x-ui.button type="submit">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>

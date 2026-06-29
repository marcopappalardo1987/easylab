@php
    $children = $childrenByParent[$node->id] ?? collect();
    $hasChildren = $children->isNotEmpty();
    $isEnte = $node->tipo === \App\Enums\TipoUnitaOrganizzativa::Ente;
    $icon = match ($node->tipo->value) {
        'ente' => '🏢',
        'dipartimento' => '📁',
        default => '🧪',
    };
@endphp

<div x-data="{ open: true }" wire:key="node-{{ $node->id }}">
    <div @class([
        'group flex items-center gap-1 rounded-md pr-1',
        'bg-primary-50' => $selectedId === $node->id,
        'hover:bg-neutral-100' => $selectedId !== $node->id,
    ]) style="padding-left: {{ $depth * 1 }}rem">

        {{-- Toggle espandi/collassa --}}
        @if ($hasChildren)
            <button type="button" x-on:click="open = !open"
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-neutral-400 hover:text-neutral-700"
                :aria-expanded="open">
                <span x-show="open">▾</span>
                <span x-show="!open" x-cloak>▸</span>
            </button>
        @else
            <span class="h-6 w-6 shrink-0"></span>
        @endif

        {{-- Etichetta nodo (seleziona) --}}
        <button type="button" wire:click="select({{ $node->id }})"
            @class([
                'flex flex-1 items-center gap-2 truncate py-2 text-left text-sm',
                'font-medium text-primary-700' => $selectedId === $node->id,
                'text-neutral-800' => $selectedId !== $node->id,
            ])>
            <span>{{ $icon }}</span>
            <span class="truncate">{{ $node->nome }}</span>
        </button>

        {{-- Azioni --}}
        <div class="flex shrink-0 items-center gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
            @can('unita_organizzativa.create')
                <button type="button" wire:click="addChild({{ $node->id }})" title="Aggiungi figlio"
                    class="flex h-8 w-8 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200">+</button>
            @endcan
            @can('unita_organizzativa.update')
                <button type="button" wire:click="edit({{ $node->id }})" title="Modifica"
                    class="flex h-8 w-8 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200">✎</button>
            @endcan
            @can('unita_organizzativa.delete')
                @unless ($isEnte)
                    <button type="button" wire:click="confirmDelete({{ $node->id }})" title="Elimina"
                        class="flex h-8 w-8 items-center justify-center rounded text-danger-500 hover:bg-danger-100">🗑</button>
                @endunless
            @endcan
        </div>
    </div>

    {{-- Figli (ricorsione) --}}
    @if ($hasChildren)
        <div x-show="open" class="space-y-1">
            @foreach ($children as $child)
                @include('livewire.anagrafica._node', ['node' => $child, 'childrenByParent' => $childrenByParent, 'depth' => $depth + 1])
            @endforeach
        </div>
    @endif
</div>

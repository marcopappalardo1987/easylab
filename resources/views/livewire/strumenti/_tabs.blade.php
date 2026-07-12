@php
    $tabs = [
        ['label' => 'Elenco', 'route' => 'strumenti.index'],
        ['label' => 'Per modello', 'route' => 'strumenti.modelli'],
    ];
@endphp

<div class="mt-4 border-b border-neutral-200">
    <nav class="-mb-px flex gap-1 text-sm">
        @foreach ($tabs as $tab)
            @php $attiva = request()->routeIs($tab['route']); @endphp
            <a href="{{ route($tab['route']) }}" wire:navigate
                @class([
                    'border-b-2 px-3 py-2 font-medium transition',
                    'border-primary-600 text-primary-700' => $attiva,
                    'border-transparent text-neutral-500 hover:text-neutral-800' => ! $attiva,
                ])>{{ $tab['label'] }}</a>
        @endforeach
    </nav>
</div>

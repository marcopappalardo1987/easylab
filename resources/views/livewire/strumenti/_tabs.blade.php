@php
    $tabs = [
        ['label' => 'Elenco', 'route' => 'strumenti.index'],
        ['label' => 'Per modello', 'route' => 'strumenti.modelli'],
    ];
@endphp

{{-- Tab «Elenco / Per modello» — token semantici (DS §8.2). Tab attivo:
     `text-brand`/`border-brand` (ruolo «link, tab attivo» della mappatura
     §1.2); inattivo: `text-ink-3` che sull'hover sale a `text-ink`, mai un
     `dark:`. --}}
<div class="mt-4 border-b border-border">
    <nav class="-mb-px flex gap-1 text-sm">
        @foreach ($tabs as $tab)
            @php $attiva = request()->routeIs($tab['route']); @endphp
            <a href="{{ route($tab['route']) }}" wire:navigate
                @class([
                    'border-b-2 px-3 py-2 font-medium transition',
                    'border-brand text-brand' => $attiva,
                    'border-transparent text-ink-3 hover:text-ink' => ! $attiva,
                ])>{{ $tab['label'] }}</a>
        @endforeach
    </nav>
</div>

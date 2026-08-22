{{--
    Le due pagine di piattaforma, condivise da entrambe.

    Vive in un componente e non duplicata nei due Blade perché il giorno in cui
    ne arriva una terza si aggiunge in un posto solo — ed è la stessa ragione per
    cui la voce di sidebar resta **una**: `piattaforma.*` le accende entrambe.
--}}
@php
    $voci = [
        ['rotta' => 'piattaforma.index', 'etichetta' => 'Clienti'],
        ['rotta' => 'piattaforma.audit', 'etichetta' => 'Registro di audit'],
    ];
@endphp

<nav class="flex gap-1 border-b border-neutral-200" aria-label="Sezioni della piattaforma">
    @foreach ($voci as $voce)
        @php $attiva = request()->routeIs($voce['rotta']); @endphp
        <a href="{{ route($voce['rotta']) }}"
           @if ($attiva) aria-current="page" @endif
           class="-mb-px border-b-2 px-3 py-2 text-sm font-medium {{ $attiva
               ? 'border-primary-600 text-primary-700'
               : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-800' }}">
            {{ $voce['etichetta'] }}
        </a>
    @endforeach
</nav>

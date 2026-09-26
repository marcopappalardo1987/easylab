{{-- Le tre schede del Parco clienti.

     🔴 Tre ROTTE e tre componenti, non tre schede dentro uno solo: così i tre
     blocchi si costruiscono su file disgiunti e nessun agente riscrive il
     lavoro di un altro. È la lezione delle tre ondate precedenti, dove
     `routes/web.php` era conteso da sei lavorazioni su nove.

     Tutte e tre sulla stessa porta (`tenants.view_all`): non c'è nulla qui che
     un ruolo veda e un altro no — o si vede oltre il proprio Ente, o non si
     entra affatto. --}}
@php
    $schede = [
        ['rotta' => 'piattaforma.parco', 'etichetta' => 'Strumenti'],
        ['rotta' => 'piattaforma.parco.scadenzario', 'etichetta' => 'Scadenzario'],
        ['rotta' => 'piattaforma.parco.ricambi', 'etichetta' => 'Ricambi'],
    ];
@endphp

<nav class="mt-4 flex gap-1 overflow-x-auto border-b border-border" aria-label="Schede del parco clienti">
    @foreach ($schede as $scheda)
        <a href="{{ route($scheda['rotta']) }}"
           class="-mb-px flex min-h-11 items-center whitespace-nowrap border-b-2 px-3 text-sm {{ request()->routeIs($scheda['rotta']) ? 'border-brand font-semibold text-brand' : 'border-transparent font-medium text-ink-2 hover:border-border-strong hover:text-ink' }}">
            {{ $scheda['etichetta'] }}
        </a>
    @endforeach
</nav>

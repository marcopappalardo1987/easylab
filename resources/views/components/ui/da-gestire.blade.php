@props([
    'quante' => 0,
])

{{--
    Il numerino accanto al nome di un tab: quante scadenze da gestire ci sono lì
    dentro (🔗 ADR-055).

    - **Non si vede se non c'è niente da fare**: uno «0» su ogni tab sarebbe
      rumore, e toglierebbe peso al numero quando compare.
    - **I colori sono quelli dello stato che spiega** (`warn-soft`): una
      scadenza passata o vicina è ciò che porta la macchina su «Interventi
      necessari». Scaduto e in scadenza hanno lo stesso colore, come nel
      semaforo — a distinguerli sono le righe del tab, con simbolo e data.
    - **Il numero si legge anche senza vederlo**: per chi usa uno screen reader
      la cifra da sola, dopo «Interventi», non direbbe di che cosa è il conto.
--}}
@if ($quante > 0)
    <span {{ $attributes->merge(['class' => 'ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-warn-soft px-1.5 text-xs font-semibold tabular-nums text-warn-soft-ink']) }}
          data-da-gestire="{{ $quante }}"
          title="{{ $quante === 1 ? 'Una scadenza da gestire' : $quante.' scadenze da gestire' }}">
        <span aria-hidden="true">{{ $quante > 99 ? '99+' : $quante }}</span>
        <span class="sr-only">{{ $quante === 1 ? '(una scadenza da gestire)' : '('.$quante.' scadenze da gestire)' }}</span>
    </span>
@endif

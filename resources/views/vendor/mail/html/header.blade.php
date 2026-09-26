@props(['url', 'nome', 'logo' => null, 'colore' => null])
{{--
    La testata brandizzabile (🔗 ADR-011).

    ⚠️ **Il nome dell'Ente è TESTO, non parte del logo.** Molti client bloccano
    anche le immagini inline: se il marchio vivesse solo nel PNG, la testata
    sarebbe vuota proprio per chi ha le immagini spente. Il logo è un di più.

    ⚠️ **Il colore del cliente entra da un filetto, non da una campitura.** Il
    colore lo sceglie lui e può essere qualunque cosa: su una fascia larga
    servirebbe garantire il contrasto del testo sopra, che è una relazione fra
    due colori e non una proprietà di uno (ADR-034). Su un bordo di 4px non c'è
    testo, quindi non c'è contrasto da garantire.
--}}
<tr>
<td class="header" style="border-top: 4px solid {{ $colore ?? '#06589c' }};">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
@if ($logo)
<img src="{{ $logo }}" alt="{{ $nome }}" width="180" class="logo" />
@endif
<span class="header-nome">{{ $nome }}</span>
</a>
</td>
</tr>

@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
    'colore' => null,
    'coloreTesto' => null,
])
{{--
    ⚠️ **Da Blade l'attributo si scrive `:colore-testo="..."`** per arrivare a
    `$coloreTesto`: il kebab-case è la convenzione dei componenti, e un
    `:coloreTesto` non arriverebbe mai.

    ⚠️ **Lo `style` inline vince sul CSS del tema**, che `CssToInlineStyles`
    scrive anch'esso inline: l'ultimo `style` sull'elemento è quello che conta,
    e le classi `button-primary` del tema restano il default per chi non passa
    un colore. Il testo NON è fissato a bianco — lo calcola
    `MarchioEmail::inchiostroSu()`, perché il colore lo sceglie il cliente e può
    essere giallo canarino.

    I `border-*` ripetono il fondo perché è così che si ottiene il padding di un
    pulsante nei client che ignorano `padding` su un `<a>` (Outlook in testa).
--}}
@php
    $stile = $colore !== null
        ? 'background-color: '.$colore.'; color: '.($coloreTesto ?? '#ffffff').'; border-color: '.$colore.';'
        : null;
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" @if ($stile) style="{{ $stile }}" @endif>{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>

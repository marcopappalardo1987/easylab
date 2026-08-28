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

    ⚠️ **Lo `style` inline vince sul CSS del tema**, e non «perché è l'ultimo»:
    `CssToInlineStyles::inlineCssOnElement()` scarta del tutto le proprietà del
    foglio che sono **già** presenti nell'attributo `style`. Ciò che sta qui
    dentro, quindi, non è una preferenza — è una cancellazione.

    ⛔ **Per questo il marchio tinge SOLO il pulsante di default (`primary`).**
    Il colore lo sceglie il cliente, ma `error` e `success` non sono colori: sono
    il **livello** del messaggio, e vengono da `->level()` della notifica. Finché
    lo stile inline si scriveva a ogni chiamata, `.button-error` e
    `.button-success` del tema erano **classi morte** — un `->level('error')`
    produceva la classe giusta sull'`<a>` e un pulsante blu, cioè due regole per
    lo stesso colore che potevano divergere senza che nulla lo dicesse.
    `vendor/notifications/email.blade.php` calcola quel livello: qui si rispetta.

    Il testo NON è fissato a bianco — lo calcola `MarchioEmail::inchiostroSu()`,
    perché il colore del cliente può essere giallo canarino. Sui livelli
    semantici l'inchiostro è del tema, che li sceglie insieme al fondo.

    I `border-*` ripetono il fondo perché è così che si ottiene il padding di un
    pulsante nei client che ignorano `padding` su un `<a>` (Outlook in testa).
--}}
@php
    $stile = ($colore !== null && $color === 'primary')
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

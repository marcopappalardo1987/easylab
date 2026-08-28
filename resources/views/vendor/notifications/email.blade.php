@php
    /**
     * Il template delle notifiche costruite con `->greeting()/->line()/->action()`
     * — cioè quelle che NON passano da `resources/views/mail/` (🔗 ADR-011).
     *
     * Oggi ci passa `App\Notifications\NuovoErrore`, l'alert dell'error tracker.
     * Finché questo file non era pubblicato, quell'email restava l'unica in
     * inglese: «Hello!», «Regards,», «If you're having trouble clicking…» —
     * tre stringhe che nessuno aveva notato perché questo percorso non era stato
     * letto.
     *
     * ⛔ **Il marchio è FISSO su piattaforma, e non è una convenzione.** Niente
     * `$marchio ?? …`: l'alert nasce da un errore che può essere capitato dentro
     * QUALSIASI tenant e va a una casella tecnica. Deve essere impossibile *per
     * costruzione* che porti il logo, il nome o il colore di un cliente — non
     * «improbabile perché nessuno glielo passa».
     */
    $marchio = \App\Support\Mail\MarchioEmail::piattaforma();
@endphp
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
{{-- Saluto --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# Attenzione
@else
# Ciao
@endif
@endif

{{-- Righe di apertura --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Pulsante --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Righe di chiusura --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Firma --}}
@if (! empty($salutation))
{{ $salutation }}
@else
A presto,<br>
Easy Lab
@endif

{{-- Sottotesto --}}
@isset($actionText)
<x-slot:subcopy>
Se il pulsante «{{ $actionText }}» non funziona, copia e incolla questo indirizzo nel browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>

{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Comodato d'uso in scadenza

Il piano **{{ TestoMarkdown::sicuro($piano) }}** di **{{ TestoMarkdown::sicuro($ragioneSociale) }}** su Easy Lab scade il **{{ $scadeIl }}**{{ $giorni === 0 ? ': oggi' : ($giorni === 1 ? ': domani' : ', fra '.$giorni.' giorni') }}.

@if ($perPiattaforma)
Ricevi questa email perché governi la piattaforma. Il cliente ha ricevuto lo stesso avviso. La scadenza si sposta dalla cabina, con «Comodato» nella riga del cliente.
@else
Per rinnovarlo, o per passare a un altro piano, scrivi a chi ti segue in Easy Lab prima di quella data.
@endif

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
{{ $perPiattaforma ? 'Apri la cabina' : 'Vai all\'abbonamento' }}
</x-mail::button>
</x-mail::message>

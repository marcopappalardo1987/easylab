{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
{{-- Il `cid:` si calcola qui e non nella componente: vedi la nota estesa in
     `digest-scadenze.blade.php`. --}}
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Benvenuto su Easy Lab

Ciao {{ TestoMarkdown::sicuro($destinatario->name) }}, il tuo account per gestire **{{ TestoMarkdown::sicuro($ente) }}** su Easy Lab è pronto: manca solo la password.

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Imposta la password
</x-mail::button>

Il link vale **{{ $giorni }} giorni**. Se scade, chi ti ha invitato può inviartene uno nuovo.

Se non aspettavi questo invito, ignora questa email: senza impostare la password l'account non è utilizzabile.
</x-mail::message>

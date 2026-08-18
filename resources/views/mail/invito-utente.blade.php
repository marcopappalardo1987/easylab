<x-mail::message>
# Benvenuto su Easy Lab

Ciao {{ $destinatario->name }}, il tuo account per gestire **{{ $ente }}** su Easy Lab è pronto: manca solo la password.

<x-mail::button :url="$url">
Imposta la password
</x-mail::button>

Il link vale **{{ $giorni }} giorni**. Se scade, chi ti ha invitato può inviartene uno nuovo.

Se non aspettavi questo invito, ignora questa email: senza impostare la password l'account non è utilizzabile.
</x-mail::message>

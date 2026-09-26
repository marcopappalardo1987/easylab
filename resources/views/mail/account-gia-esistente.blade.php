<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Hai già un account Easy Lab

Qualcuno — probabilmente tu — ha appena provato a registrarsi su Easy Lab con questo indirizzo, che però ha già un account.

Non abbiamo creato nulla di nuovo e non è stato addebitato niente: per entrare usa la password che avevi scelto.

<x-mail::button :url="$login" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Vai all'accesso
</x-mail::button>

Se non ricordi la password, puoi [reimpostarla]({{ $recupero }}).

{{-- ⚠️ Nessun dettaglio dell'account: né quale Ente, né quale piano, né da
     quando. Chi ha scritto l'indirizzo nel modulo potrebbe non essere il
     proprietario della casella, e questa email è l'unica cosa che gli
     arriverebbe indietro — se pure la ricevesse. --}}
Se non sei stato tu, puoi ignorare questa email: nessuno ha ottenuto accesso al tuo account.
</x-mail::message>

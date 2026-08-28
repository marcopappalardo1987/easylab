{{-- Il `cid:` si calcola qui e non nella componente: vedi la nota estesa in
     `digest-scadenze.blade.php`. --}}
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Conferma il tuo indirizzo

Ciao {{ $nome }}, ci siamo quasi: conferma questo indirizzo e potrai completare l'attivazione di **{{ $ente }}** su Easy Lab.

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Conferma l'indirizzo
</x-mail::button>

Il link vale **{{ $ore == 1 ? "un'ora" : "{$ore} ore" }}**. Se scade, ricompila il modulo: te ne inviamo uno nuovo.

{{-- ⚠️ La riga che rende innocuo un modulo compilato da un terzo con la casella
     di qualcun altro: finché il link non viene aperto, a database c'è solo una
     riga in attesa che si cancella da sola. --}}
Se non ti sei registrato tu, ignora questa email: senza questa conferma non viene creato nessun account e non ti verrà addebitato nulla.
</x-mail::message>

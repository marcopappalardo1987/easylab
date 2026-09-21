{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# {{ TestoMarkdown::sicuro($ente) }} è attivo

Il pagamento è andato a buon fine e il tuo account Easy Lab è pronto. Accedi con l'indirizzo **{{ TestoMarkdown::sicuro($email) }}** e la password che hai scelto durante la registrazione.

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Accedi a Easy Lab
</x-mail::button>

{{-- ⚠️ Detto qui perché al primo accesso succede davvero, e senza questa riga
     somiglia a un guasto: il ruolo Admin richiede la verifica in due passaggi,
     quindi si atterra sulla pagina della sicurezza e non sulla dashboard. --}}
Al primo accesso ti chiederemo di attivare la **verifica in due passaggi**: è obbligatoria per chi amministra un account, e sono due minuti.

Da lì potrai invitare i tuoi collaboratori, caricare il parco strumenti e — quando vuoi — mettere il tuo logo sulle email che Easy Lab manda per tuo conto.
</x-mail::message>

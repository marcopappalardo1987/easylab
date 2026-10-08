{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Piano cambiato

Il piano di **{{ TestoMarkdown::sicuro($ragioneSociale) }}** su Easy Lab è passato da **{{ TestoMarkdown::sicuro($da) }}** a **{{ TestoMarkdown::sicuro($a) }}**.

Il cambio vale da subito. Ricevute e fatture arrivano da Stripe, e si ritrovano dalla pagina **Abbonamento**.

Se non sei stato tu a chiederlo, o non ne sapevi nulla, scrivici.

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Vai all'abbonamento
</x-mail::button>
</x-mail::message>

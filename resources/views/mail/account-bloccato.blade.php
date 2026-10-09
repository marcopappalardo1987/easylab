{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Accesso sospeso

L'accesso a Easy Lab per **{{ TestoMarkdown::sicuro($ragioneSociale) }}** è sospeso.

I dati restano al loro posto: macchine, interventi e documenti non vengono toccati, e tornano disponibili appena l'accesso viene riaperto.

Se dipende da un pagamento non andato a buon fine, puoi aggiornare il metodo di pagamento dalla pagina **Abbonamento**. In ogni altro caso scrivici: ti diciamo come procedere.

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Vai all'abbonamento
</x-mail::button>
</x-mail::message>

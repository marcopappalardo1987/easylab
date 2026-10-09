{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Intervento programmato

Su **{{ TestoMarkdown::sicuro($strumento) }}** è stato programmato un intervento.

- **Cosa:** {{ TestoMarkdown::sicuro($tipo) }} — {{ TestoMarkdown::sicuro($descrizione) }}
- **Entro il:** {{ $scadenza }}
- **Dove:** {{ TestoMarkdown::sicuro($ubicazione) }}
@if ($assegnatario)
- **Assegnato a:** {{ TestoMarkdown::sicuro($assegnatario) }}
@endif
@if ($autore)
- **Programmato da:** {{ TestoMarkdown::sicuro($autore) }}
@endif

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Apri la scheda della macchina
</x-mail::button>

Ricevi questa email perché segui delle macchine su Easy Lab. Puoi disattivarla dalle [preferenze notifiche]({{ route('settings.notifiche') }}).
</x-mail::message>

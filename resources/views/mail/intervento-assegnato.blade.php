{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Un intervento per te

Ti è stato assegnato un intervento su **{{ TestoMarkdown::sicuro($strumento) }}**, presso {{ TestoMarkdown::sicuro($ente) }}.

- **Cosa:** {{ TestoMarkdown::sicuro($tipo) }} — {{ TestoMarkdown::sicuro($descrizione) }}
- **Entro il:** {{ $scadenza }}
- **Dove:** {{ TestoMarkdown::sicuro($ubicazione) }}
@if ($assegnatoDa)
- **Assegnato da:** {{ TestoMarkdown::sicuro($assegnatoDa) }}
@endif

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Apri la scheda della macchina
</x-mail::button>

Ricevi questa email perché lavori su queste macchine con Easy Lab. Puoi disattivarla dalle [preferenze notifiche]({{ route('settings.notifiche') }}): il lavoro resta comunque in «Campo».
</x-mail::message>

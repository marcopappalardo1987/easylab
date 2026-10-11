{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Intervento eseguito

Su **{{ TestoMarkdown::sicuro($strumento) }}** è stato eseguito un intervento.

- **Cosa:** {{ TestoMarkdown::sicuro($tipo) }} — {{ TestoMarkdown::sicuro($descrizione) }}
- **Eseguito il:** {{ $eseguitoIl }}
- **Dove:** {{ TestoMarkdown::sicuro($ubicazione) }}
@if ($autore)
- **Chiuso da:** {{ TestoMarkdown::sicuro($autore) }}
@endif
@if ($prossima)
- **Prossima taratura:** programmata per il {{ $prossima }}
@endif

@if ($conReport)
Il report di fine lavoro si legge nella scheda della macchina.
@endif

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Apri la scheda della macchina
</x-mail::button>

{{-- 🔗 ADR-054: il referente non ha un account, quindi nemmeno delle
     preferenze da cui disattivarla. Gli si dice perché la riceve, e come farla
     smettere. --}}
@if ($alReferente ?? false)
Ricevi questa email perché sei indicato come referente di questa macchina per {{ TestoMarkdown::sicuro($ente) }}. Per non riceverla più, chiedi a chi gestisce la macchina di togliere il tuo indirizzo dalla sua scheda.
@else
Ricevi questa email perché segui delle macchine su Easy Lab. Puoi disattivarla dalle [preferenze notifiche]({{ route('settings.notifiche') }}).
@endif
</x-mail::message>

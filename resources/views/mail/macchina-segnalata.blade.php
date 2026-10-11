{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
{{-- 🔗 ADR-052: il titolo è la dicitura dello stato, quella che la persona
     ritrova sulla scheda. La dà `StatoSemaforo::etichetta()`, da chi notifica. --}}
# {{ TestoMarkdown::sicuro($stato) }}

**{{ TestoMarkdown::sicuro($strumento) }}** è stata segnalata: lo stato è ora **{{ TestoMarkdown::sicuro($stato) }}**.

@if ($motivo)
- **Motivo:** {{ TestoMarkdown::sicuro($motivo) }}
@endif
- **Dove:** {{ TestoMarkdown::sicuro($ubicazione) }}
@if ($autore)
- **Segnalata da:** {{ TestoMarkdown::sicuro($autore) }}
@endif

@if ($nonIdonea)
Finché resta in questo stato la macchina non va usata.
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

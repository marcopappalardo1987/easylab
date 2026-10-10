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

Ricevi questa email perché segui delle macchine su Easy Lab. Puoi disattivarla dalle [preferenze notifiche]({{ route('settings.notifiche') }}).
</x-mail::message>

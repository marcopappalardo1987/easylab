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

Ricevi questa email perché segui delle macchine su Easy Lab. Puoi disattivarla dalle [preferenze notifiche]({{ route('settings.notifiche') }}).
</x-mail::message>

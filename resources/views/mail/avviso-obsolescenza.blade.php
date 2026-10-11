{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
{{-- Il `cid:` si calcola qui e non nella componente: vedi la nota estesa in
     `digest-scadenze.blade.php`. --}}
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Macchine oltre la soglia di età — {{ TestoMarkdown::sicuro($ente) }}

{{-- 🔗 ADR-054: il referente è una casella e non ha un `name`, né un Ente
     «suo» in Easy Lab: lo si saluta col nome scritto sulla scheda, se c'è. --}}
@if ($alReferente ?? false)
{{ filled($nomeReferente ?? null) ? 'Ciao '.TestoMarkdown::sicuro($nomeReferente).', la' : 'La' }} soglia di obsolescenza di {{ TestoMarkdown::sicuro($ente) }} è di
{{ $soglia }} {{ $soglia === 1 ? 'anno' : 'anni' }}: queste macchine, di cui sei
referente, l'hanno appena superata.
@else
Ciao {{ TestoMarkdown::sicuro($destinatario->name) }}, la soglia di obsolescenza del tuo Ente è di
{{ $soglia }} {{ $soglia === 1 ? 'anno' : 'anni' }}: queste macchine l'hanno
appena superata.
@endif

{{-- Il paragrafo che segue è ADR-014 alla lettera, e non è un ammorbidimento
     di cortesia: l'obsolescenza è una SEGNALAZIONE sull'età, non uno stato di
     guasto. Non tocca il semaforo e non blocca nulla — senza questa riga
     un'email intitolata «oltre la soglia» si legge come un fermo macchina. --}}
È una segnalazione sull'età del parco, utile a pianificare i rinnovi: non blocca
la manutenzione e non cambia lo stato delle macchine in Easy Lab.

<x-mail::table>
| Macchina | Installata il | Età |
|:---------|:--------------|:----|
@foreach ($righe as $riga)
| [{{ TestoMarkdown::sicuro($riga->strumentoNome) }}]({{ route('strumenti.show', $riga->strumentoId) }}) | {{ $riga->dataInstallazione->format('d/m/Y') }} | {{ $riga->eta() }} {{ $riga->eta() === 1 ? 'anno' : 'anni' }} |
@endforeach
</x-mail::table>

{{-- Il bottone punta all'elenco e NON a un filtro precompilato:
     `ElencoStrumenti` espone `$soloObsoleti` come proprietà Livewire e non come
     query string, quindi un link `?obsoleti=1` non filtrerebbe niente — cioè
     sarebbe una promessa non mantenuta dentro un'email. --}}
<x-mail::button :url="route('strumenti.index')" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Apri Easy Lab
</x-mail::button>

@if ($alReferente ?? false)
Ricevi questo avviso perché sei indicato come referente di queste macchine per {{ TestoMarkdown::sicuro($ente) }}. Per non riceverlo più, chiedi a chi le gestisce di togliere il tuo indirizzo dalla loro scheda.
@else
Ricevi questo avviso perché segui delle macchine su Easy Lab. Puoi disattivare
le email dalle [preferenze notifiche]({{ route('settings.notifiche') }}); gli
avvisi resteranno comunque visibili in applicazione.
@endif
</x-mail::message>

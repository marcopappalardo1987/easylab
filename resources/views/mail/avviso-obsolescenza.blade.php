<x-mail::message>
# Macchine oltre la soglia di età — {{ $ente }}

Ciao {{ $destinatario->name }}, la soglia di obsolescenza del tuo Ente è di
{{ $soglia }} {{ $soglia === 1 ? 'anno' : 'anni' }}: queste macchine l'hanno
appena superata.

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
| [{{ $riga->strumentoNome }}]({{ route('strumenti.show', $riga->strumentoId) }}) | {{ $riga->dataInstallazione->format('d/m/Y') }} | {{ $riga->eta() }} {{ $riga->eta() === 1 ? 'anno' : 'anni' }} |
@endforeach
</x-mail::table>

{{-- Il bottone punta all'elenco e NON a un filtro precompilato:
     `ElencoStrumenti` espone `$soloObsoleti` come proprietà Livewire e non come
     query string, quindi un link `?obsoleti=1` non filtrerebbe niente — cioè
     sarebbe una promessa non mantenuta dentro un'email. --}}
<x-mail::button :url="route('strumenti.index')">
Apri Easy Lab
</x-mail::button>

Ricevi questo avviso perché segui delle macchine su Easy Lab. Puoi disattivare
le email dalle [preferenze notifiche]({{ route('settings.notifiche') }}); gli
avvisi resteranno comunque visibili in applicazione.
</x-mail::message>

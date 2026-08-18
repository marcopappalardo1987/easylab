@php
    use App\Enums\TipoMotivoSemaforo;
    use App\Support\Notifiche\RigaAvviso;

    // Come si chiama una scadenza nell'email.
    //
    // ⚠️ La garanzia ricambio è resa in forma NEUTRA per chiunque, e qui non
    // c'è nessun permesso da controllare — a differenza della Panoramica
    // (_panoramica.blade.php), dove il testo dipende dall'ability di ADR-029.
    // Il motivo è che quella schermata sta dietro un login e conosce chi
    // guarda; un'email no: esce dall'applicazione, resta in una casella e viene
    // inoltrata a chi decide il destinatario. Dire «garanzia ricambio»
    // rivelerebbe che sulla macchina c'è un pezzo sostituito (ADR-004) a un
    // lettore che nessuno ha autorizzato. Il nome del pezzo, del resto, non
    // arriva neppure fin qui: la query del comando non lo legge.
    $etichetta = fn (RigaAvviso $riga) => match ($riga->tipo) {
        TipoMotivoSemaforo::Intervento => 'Intervento',
        TipoMotivoSemaforo::GaranziaMacchina => 'Garanzia macchina',
        TipoMotivoSemaforo::GaranziaRicambio => 'Garanzia di un componente',
    };
@endphp

<x-mail::message>
# Scadenze di {{ $ente }}

Ciao {{ $destinatario->name }}, ecco cosa è cambiato oggi sulle macchine che segui.

@if (count($scadute) > 0)
## Scadenze superate

<x-mail::table>
| Macchina | Cosa | Scaduta il |
|:---------|:-----|:-----------|
@foreach ($scadute as $riga)
| [{{ $riga->strumentoNome }}]({{ route('strumenti.show', $riga->strumentoId) }}) | {{ $etichetta($riga) }}{{ $riga->dettaglio ? ' — '.$riga->dettaglio : '' }} | {{ $riga->scadenza->format('d/m/Y') }} |
@endforeach
</x-mail::table>
@endif

@if (count($imminenti) > 0)
## In scadenza nei prossimi 30 giorni

<x-mail::table>
| Macchina | Cosa | Scade il |
|:---------|:-----|:---------|
@foreach ($imminenti as $riga)
| [{{ $riga->strumentoNome }}]({{ route('strumenti.show', $riga->strumentoId) }}) | {{ $etichetta($riga) }}{{ $riga->dettaglio ? ' — '.$riga->dettaglio : '' }} | {{ $riga->scadenza->format('d/m/Y') }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="route('strumenti.index')">
Apri Easy Lab
</x-mail::button>

Ricevi questo riepilogo perché segui delle macchine su Easy Lab. Puoi disattivare
le email dalle [preferenze notifiche]({{ route('settings.notifiche') }}); gli
avvisi resteranno comunque visibili in applicazione.
</x-mail::message>

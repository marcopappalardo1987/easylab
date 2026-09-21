{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1). --}}
@use('App\Support\Mail\TestoMarkdown')
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

{{-- ⚠️ Il `cid:` del logo si calcola QUI e non dentro `x-mail::message`: un
     componente Blade anonimo non eredita i dati della vista padre, e `$message`
     — che è l'oggetto su cui si incorpora un allegato — viene iniettato da
     `Mailer` nei dati della VISTA. Il `?? null` copre `MailMessage::render()`,
     che rende il markdown senza passare dal mailer: lì `$message` non esiste e
     la testata resta senza immagine, che è esattamente ciò che si vuole. --}}
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Scadenze di {{ TestoMarkdown::sicuro($ente) }}

Ciao {{ TestoMarkdown::sicuro($destinatario->name) }}, ecco cosa è cambiato oggi sulle macchine che segui.

@if (count($scadute) > 0)
## Scadenze superate

<x-mail::table>
| Macchina | Cosa | Scaduta il |
|:---------|:-----|:-----------|
@foreach ($scadute as $riga)
| [{{ TestoMarkdown::sicuro($riga->strumentoNome) }}]({{ route('strumenti.show', $riga->strumentoId) }}) | {{ $etichetta($riga) }}{{ $riga->dettaglio ? ' — '.TestoMarkdown::sicuro($riga->dettaglio) : '' }} | {{ $riga->scadenza->format('d/m/Y') }} |
@endforeach
</x-mail::table>
@endif

@if (count($imminenti) > 0)
## In scadenza nei prossimi 30 giorni

<x-mail::table>
| Macchina | Cosa | Scade il |
|:---------|:-----|:---------|
@foreach ($imminenti as $riga)
| [{{ TestoMarkdown::sicuro($riga->strumentoNome) }}]({{ route('strumenti.show', $riga->strumentoId) }}) | {{ $etichetta($riga) }}{{ $riga->dettaglio ? ' — '.TestoMarkdown::sicuro($riga->dettaglio) : '' }} | {{ $riga->scadenza->format('d/m/Y') }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="route('strumenti.index')" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Apri Easy Lab
</x-mail::button>

Ricevi questo riepilogo perché segui delle macchine su Easy Lab. Puoi disattivare
le email dalle [preferenze notifiche]({{ route('settings.notifiche') }}); gli
avvisi resteranno comunque visibili in applicazione.
</x-mail::message>

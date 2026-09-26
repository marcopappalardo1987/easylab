{{--
    Indice dell'archivio documentale d'Ente, in PDF (🔗 ADR-026/031).

    HTML e CSS SEMPLICI di proposito: dompdf interpreta un sottoinsieme di CSS e
    non conosce flexbox, grid né i token di Tailwind v4. Niente `@vite`, niente
    classi utility: solo `<style>` inline. Un foglio A4 non è responsive, e non
    ha un tema.

    ⛔ Resta SEMPRE chiaro e SENZA `primary` (DS §8.4, ADR-031/033). Gli hex qui
    sotto sono gli **stessi** già elencati nel docblock di
    `pdf/storico-strumento.blade.php`, ripresi a mano dalla scala di
    `resources/css/app.css` — e NON vanno letti come token semantici, che qui non
    esistono:
      · #1e293b = --color-neutral-800  (testo del corpo)
      · #475569 = --color-neutral-600  (sottotitolo, riga dei filtri)
      · #94a3b8 = --color-neutral-400  (intestazioni th, piede, riga vuota)
      · #e2e8f0 = --color-neutral-200  (bordo sotto th, bordo sopra il piede)
      · #f1f5f9 = --color-neutral-100  (bordo sotto td)
    Se una futura revisione della scala li spostasse, questo elenco e quello
    dello storico macchina sono i due posti da cui ripartire — non l'occasione
    per introdurre `primary` o un tema.

    ⚠️ Ciò che è **fuori** dallo scope di chi esporta non arriva mai qui: la
    query del controller è la stessa dello schermo, scope compresi. Questa vista
    non filtra niente per permesso, e non deve imparare a farlo — un PDF non sa
    degradare una volta uscito dall'applicazione.
--}}
{{-- ⚠️ Il titolo si compone in PHP e non con un `@if` attaccato al testo:
     Blade non riconosce una direttiva preceduta da un carattere di parola
     (`documentale@if` resta letterale mentre `@endif` viene compilato), e il
     risultato è un `endif` orfano che fa esplodere la view. Trovato così. --}}
@php $titolo = 'Archivio documentale'.($ente ? ' — '.$ente : ''); @endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $titolo }}</title>
    <style>
        @page { margin: 20mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1e293b; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        .sotto { color: #475569; font-size: 9pt; margin: 0 0 6mm; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #94a3b8;
             border-bottom: 1px solid #e2e8f0; padding: 2mm 2mm 1.5mm 0; }
        td { padding: 1.8mm 2mm 1.8mm 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .piede { margin-top: 8mm; font-size: 8pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 2mm; }
        .tenue { color: #94a3b8; }
    </style>
</head>
<body>

<h1>{{ $titolo }}</h1>

{{-- La riga dei filtri non è un ornamento: un indice di venti righe senza di
     essa si legge come «l'archivio ha venti documenti», che è una conclusione
     sbagliata a partire da un dato giusto. --}}
<p class="sotto">{{ $filtri }}</p>

<table>
    <thead>
        <tr>
            <th style="width: 20mm;">Data</th>
            <th>Nome</th>
            <th style="width: 32mm;">Tipo</th>
            <th style="width: 32mm;">Macchina</th>
            <th style="width: 32mm;">Allegato a</th>
            <th style="width: 28mm;">Caricato da</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($righe as $documento)
            <tr>
                <td>{{ $documento->created_at?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $documento->nome }}</td>
                <td>{{ $documento->tipo->label() }}</td>
                <td>
                    {{-- Stessa insidia dello schermo: una macchina cestinata lascia
                         i suoi documenti in elenco con la relazione a `null`. --}}
                    @if ($documento->strumento === null)
                        <span class="tenue">Macchina cestinata</span>
                    @else
                        {{ $documento->strumento->nome }}
                        @if ($documento->strumento->matricola)
                            <div class="tenue">{{ $documento->strumento->matricola }}</div>
                        @endif
                    @endif
                </td>
                <td>
                    {{-- Stesso idioma null-safe dello schermo: un intervento
                         cestinato lascia il suo documento in piedi con la
                         relazione che risolve a `null`. --}}
                    @if ($documento->documentabile instanceof App\Models\Intervento)
                        {{ $documento->documentabile->descrizione }}
                    @else
                        <span class="tenue">La macchina</span>
                    @endif
                </td>
                <td>{{ $documento->caricatoBy?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="padding: 6mm 0; color: #94a3b8;">Nessun documento.</td></tr>
        @endforelse
    </tbody>
</table>

<p class="piede">
    @if ($troncato)
        {{-- Un limite dichiarato vale più di un limite scoperto: un foglio che
             finisce a metà senza dirlo è un documento che mente. --}}
        Elenco troncato alle prime {{ $massimo }} righe — restringi i filtri per vedere il resto.<br>
    @endif
    Easy Lab — indice generato il {{ $generatoIl->format('d/m/Y \a\l\l\e H:i') }}@if ($generatoDa) da {{ $generatoDa }}@endif.
</p>

</body>
</html>

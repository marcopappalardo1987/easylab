{{--
    Il foglio dei clienti della piattaforma (ADR-031, S6 — cabina di regia).

    HTML e CSS SEMPLICI di proposito: dompdf interpreta un sottoinsieme di CSS e
    non conosce flexbox, grid né i token di Tailwind v4. Niente `@vite`, niente
    classi utility, niente `@font-face`: solo `<style>` inline e un font di
    sistema. `DejaVu Sans` non è una preferenza estetica — senza, il segno `€`,
    gli accenti e le virgolette basse escono corrotti.

    ⛔ Il foglio è SEMPRE chiaro e SENZA token di tema (DS §8.4, ADR-031/033):
    dompdf non vede il tema, e un foglio A4 non ne ha uno. Gli hex qui sotto sono
    gli stessi presi a mano in `pdf/storico-strumento.blade.php`, che è la
    tavola da cui si copia — non si reinventa e non si introduce `primary`:
      · #1e293b = --color-neutral-800  (testo del corpo)
      · #475569 = --color-neutral-600  (sottotitolo, note)
      · #94a3b8 = --color-neutral-400  (intestazioni th, piede, etichette)
      · #e2e8f0 = --color-neutral-200  (bordo sotto th, bordo sopra il piede)
      · #f1f5f9 = --color-neutral-100  (bordo sotto td)

    ⚠️ A4 ORIZZONTALE e 8pt: le colonne sono tredici, e in verticale finirebbero
    l'una sull'altra. Il formato è imposto dall'azione (`setPaper('a4',
    'landscape')`), non da qui.

    🔴 LA DIDASCALIA SOTTO I QUATTRO NUMERI NON È DECORATIVA. I KPI sono totali
    di piattaforma e NON seguono i filtri — è la stessa scelta dichiarata in
    `cabina.blade.php`. Un foglio che li stampasse sopra una tabella filtrata
    senza ripetere quella frase mentirebbe per accostamento: chi lo legge
    sommerebbe mentalmente le due cose. Accanto c'è invece il totale a listino
    delle sole righe in elenco, che i filtri li segue, etichettato come tale.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Clienti EasyLab</title>
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #1e293b; }
        h1 { font-size: 14pt; margin: 0 0 1.5mm; }
        .sotto { color: #475569; font-size: 8pt; margin: 0 0 5mm; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 7pt; text-transform: uppercase; color: #94a3b8;
             border-bottom: 1px solid #e2e8f0; padding: 2mm 1.5mm 1.5mm 0; }
        td { padding: 1.5mm 1.5mm 1.5mm 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .sintesi td { border: 0; padding: 0.8mm 6mm 0.8mm 0; }
        .sintesi .etichetta { color: #94a3b8; font-size: 7.5pt; text-transform: uppercase; }
        .nota { color: #94a3b8; font-size: 7.5pt; margin: 1mm 0 0; }
        .contesto { color: #475569; font-size: 8pt; margin: 4mm 0 0; }
        .piede { margin-top: 6mm; font-size: 7.5pt; color: #94a3b8;
                 border-top: 1px solid #e2e8f0; padding-top: 2mm; }
    </style>
</head>
<body>

<h1>Clienti EasyLab</h1>
<p class="sotto">
    Generato il {{ $generatoIl->format('d/m/Y \a\l\l\e H:i') }}@if ($generatoDa) da {{ $generatoDa }}@endif.
</p>

{{-- Blocco KPI in TABELLA e non in flexbox: dompdf non conosce flex, e una
     riga di `div` affiancati con `float` si rompe al primo valore lungo. --}}
<table class="sintesi">
    <tr>
        <td class="etichetta">Ricavo mensile</td>
        <td>{{ number_format($riepilogo->mrrEuro(), 0, ',', '.') }} &euro; a listino</td>
        <td class="etichetta">Clienti</td>
        <td>{{ number_format($riepilogo->clienti, 0, ',', '.') }}</td>
        <td class="etichetta">Sedi</td>
        <td>{{ number_format($riepilogo->sedi, 0, ',', '.') }}</td>
        <td class="etichetta">Strumenti</td>
        <td>{{ number_format($riepilogo->strumenti, 0, ',', '.') }}</td>
    </tr>
</table>

{{-- 🔴 Obbligatoria: vedi il docblock in testa al file. --}}
<p class="nota">
    I quattro numeri sono totali di piattaforma: non seguono i filtri qui sotto.
</p>

<p class="contesto">
    @if (count($filtri) === 0)
        Nessun filtro: tutti i clienti.
    @else
        {{-- I filtri si leggono da `$filtri`, che il componente costruisce dai
             valori NORMALIZZATI. Leggere qui le property grezze farebbe uscire
             su carta un elenco di filtri che la query non ha applicato. --}}
        Filtri attivi:
        @foreach ($filtri as $etichetta => $valore){{ $etichetta }} {{ $valore }}@if (! $loop->last) · @endif @endforeach
    @endif
    <br>
    {{ number_format(count($righe), 0, ',', '.') }} clienti su {{ number_format($riepilogo->clienti, 0, ',', '.') }}.
    Totale a listino delle righe in elenco: {{ number_format($totaleListinoEuro, 0, ',', '.') }} &euro;.
</p>

<table style="margin-top: 4mm;">
    <thead>
        <tr>
            {{-- ⛔ `$intestazioni` qui sono quelle IN PROSA
                 (`EsportazioneClienti::intestazioniLeggibili()`), non i nomi
                 macchina del CSV. Lo `snake_case` esiste perche' un foglio di
                 calcolo si ri-importa; un PDF no, e un documento da riunione
                 non intesta una colonna «bloccato_per_insoluto». Le RIGHE
                 restano quelle del CSV: la matrice e' una sola. --}}
            @foreach ($intestazioni as $intestazione)
                <th scope="col">{{ $intestazione }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($righe as $riga)
            <tr>
                @foreach ($riga as $cella)
                    <td>{{ $cella }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($intestazioni) }}" style="padding: 6mm 0; color: #94a3b8;">
                    Nessun cliente corrisponde a questi filtri.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<p class="piede">
    {{-- ⚠️ Il ricavo è a LISTINO e non incassato: lo stesso prezzo vive su
         Stripe e i due possono divergere (ADR-013, `RiepilogoPiattaforma`). Un
         foglio che uscisse dall'applicazione senza dirlo verrebbe letto come un
         rendiconto. --}}
    Easy Lab &mdash; i valori di ricavo sono a <strong>listino</strong>, non incassati: l'incassato vive su Stripe
    e i due possono divergere. Il foglio dichiara la propria data perch&eacute; &egrave; una fotografia.
</p>

</body>
</html>

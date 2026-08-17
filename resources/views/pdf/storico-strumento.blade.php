{{--
    Storico macchina in PDF (ADR-031).

    HTML e CSS SEMPLICI di proposito: dompdf interpreta un sottoinsieme di CSS e
    non conosce flexbox, grid né i token di Tailwind v4. Non è una limitazione da
    aggirare — un foglio A4 non è responsive, e usare qui il markup dell'app
    significherebbe avere due consumatori con esigenze opposte sullo stesso
    codice. Niente `@vite`, niente classi utility: solo `<style>` inline.

    Font di sistema: caricarne uno via `@font-face` vorrebbe dire scaricarlo a
    ogni render o versionarlo nel repository. La coerenza col Design System si
    esprime in struttura e gerarchia, non nel disegno delle lettere.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Storico — {{ $strumento->nome }}</title>
    <style>
        @page { margin: 20mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1e293b; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        .sotto { color: #475569; font-size: 9pt; margin: 0 0 6mm; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #94a3b8;
             border-bottom: 1px solid #e2e8f0; padding: 2mm 2mm 1.5mm 0; }
        td { padding: 2mm 2mm 2mm 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .scaduto { color: #b91c1c; }
        .fatto { color: #15803d; }
        .report { color: #475569; font-size: 8.5pt; margin-top: 1mm; }
        .piede { margin-top: 8mm; font-size: 8pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 2mm; }
        .sintesi td { border: 0; padding: 0.8mm 4mm 0.8mm 0; }
        .sintesi .etichetta { color: #94a3b8; font-size: 8pt; text-transform: uppercase; }
    </style>
</head>
<body>

<h1>{{ $strumento->nome }}</h1>
<p class="sotto">
    @if ($strumento->modello){{ $strumento->modello }} · @endif
    @if ($strumento->matricola)Matricola {{ $strumento->matricola }} · @endif
    {{ $percorso }}
</p>

<table class="sintesi">
    <tr>
        <td class="etichetta">Installato</td>
        <td>{{ $strumento->data_installazione?->format('m/Y') ?: '—' }}</td>
        <td class="etichetta">Interventi</td>
        <td>{{ $interventi->count() }}</td>
        @if ($garanzia)
            <td class="etichetta">Garanzia macchina</td>
            <td>scade il {{ $garanzia->data_scadenza_effettiva->format('d/m/Y') }}</td>
        @endif
    </tr>
</table>

<h2 style="font-size: 11pt; margin: 8mm 0 2mm;">Storico attività</h2>

<table>
    <thead>
        <tr>
            <th style="width: 22mm;">Scadenza</th>
            <th style="width: 22mm;">Eseguito</th>
            <th style="width: 34mm;">Tipo</th>
            <th>Attività e report di fine lavoro</th>
            <th style="width: 32mm;">Tecnico</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($interventi as $i)
            @php $fatto = $i->stato === App\Enums\StatoIntervento::Fatto; @endphp
            <tr>
                <td class="{{ ! $fatto && $i->isScaduto() ? 'scaduto' : '' }}">
                    {{ $i->data_scadenza->format('d/m/Y') }}
                </td>
                <td class="{{ $fatto ? 'fatto' : '' }}">
                    {{-- «Fatto» e «da fare» si distinguono per PAROLA e non per
                         colore: su un foglio stampato in bianco e nero il colore
                         non esiste, ed è lo stesso principio del Design System §4. --}}
                    {{ $fatto ? ($i->data_esecuzione?->format('d/m/Y') ?? 'fatto') : 'da fare' }}
                </td>
                <td>{{ $i->tipo->label() }}</td>
                <td>
                    {{ $i->descrizione }}
                    @if ($i->report_fine_lavoro)
                        <div class="report">{{ $i->report_fine_lavoro }}</div>
                    @endif
                </td>
                <td>{{ $i->tecnicoLabel() }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="padding: 6mm 0; color: #94a3b8;">Nessuna attività registrata.</td></tr>
        @endforelse
    </tbody>
</table>

<p class="piede">
    Easy Lab — storico generato il {{ $generatoIl->format('d/m/Y \a\l\l\e H:i') }}@if ($generatoDa) da {{ $generatoDa }}@endif.
    {{-- Il foglio dichiara la propria data perché è una fotografia: lo storico
         cambia, e un PDF ritrovato fra un anno deve dire di quando parla. --}}
</p>

</body>
</html>

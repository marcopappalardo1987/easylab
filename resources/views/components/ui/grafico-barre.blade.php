@props([
    'serie',
    'titolo',
    'sottotitolo' => null,
    'unita' => '',
    'classeBarra' => 'fill-chart-brand',
])

{{-- Il grafico a barre — 🔗 DS §2.5/§8.2, campione §10 `.el-chart`, ADR-034.

     ⚠️ **È INFORMATIVO, quindi la forma accessibile è doppia e non ambigua**:
     `role="img"` con un `aria-label` di sintesi (chi legge con uno screen reader
     deve sapere *cosa dice* il grafico prima di decidere se aprirlo), un
     `<title>` per barra per chi ci passa sopra col mouse, e sotto un
     `<details>Vedi i dati</details>` con la **tabella dei dodici valori**,
     visibile a tutti — serve anche a chi vuole copiarli.

     ⛔ **I colori sono CLASSI, mai attributi di presentazione e mai
     esadecimali** — la ragione per esteso è nel commento di `x-ui.sparkline`.
     Qui: `stroke-chart-grid`, `fill-chart-brand`, `fill-ink-2`, `fill-ink-3`.
     Nessuna variante di tema scritta a mano: `app.css` ha già le tre terne dei
     token dei grafici e il blocco di stampa che li riporta al chiaro (senza,
     stampando dal tema scuro la griglia usciva blu notte).

     ⚠️ **Il viewBox resta 440×170**, che è la misura del campione: un SVG con
     `width:100%` scala anche il testo, e un viewBox più stretto renderebbe le
     etichette da 10px gigantesche su desktop.

     ⚠️ **Il rettangolo si disegna solo se il valore è > 0**: una barra alta zero
     è un artefatto di un pixel che si legge come un dato minuscolo invece che
     come un mese senza clienti. Lo zero lo dice il numero sopra l'asse. --}}

@php
    use App\Support\Piattaforma\GeometriaGrafico;

    $barre = GeometriaGrafico::barre($serie->valori);

    $sintesi = $serie->mesi === []
        ? $titolo.': nessun dato'
        : $titolo.': da '.$serie->etichette[0].' a '.$serie->etichette[count($serie->etichette) - 1]
            .', minimo '.$serie->minimo().', massimo '.$serie->massimo()
            .', totale '.$serie->totale().' '.$unita;
@endphp

<figure {{ $attributes->merge(['class' => 'm-0']) }}>
    <figcaption class="text-sm font-semibold text-ink">{{ $titolo }}</figcaption>

    @if ($sottotitolo)
        <p class="mt-0.5 text-xs text-ink-3">{{ $sottotitolo }}</p>
    @endif

    <svg viewBox="0 0 440 170" role="img" aria-label="{{ $sintesi }}" class="mt-3 block w-full overflow-visible">
        <line class="stroke-chart-grid" stroke-width="1" x1="4" y1="144" x2="436" y2="144" />

        @foreach ($barre as $i => $barra)
            <g>
                <title>{{ $serie->etichette[$i] }} {{ \Illuminate\Support\Str::substr($serie->mesi[$i], 0, 4) }}: {{ $barra['valore'] }} {{ $unita }}</title>

                @if ($barra['valore'] > 0)
                    <rect class="{{ $classeBarra }}" rx="3"
                          x="{{ $barra['x'] }}" y="{{ $barra['y'] }}"
                          width="{{ $barra['larghezza'] }}" height="{{ $barra['altezza'] }}" />
                @endif

                <text class="fill-ink-2 text-[10px] font-semibold" text-anchor="middle"
                      x="{{ $barra['centroX'] }}" y="{{ $barra['y'] - 6 }}">{{ $barra['valore'] }}</text>

                <text class="fill-ink-3 text-[10px]" text-anchor="middle"
                      x="{{ $barra['centroX'] }}" y="159">{{ $serie->etichette[$i] }}</text>
            </g>
        @endforeach
    </svg>

    {{-- La stessa serie, in forma copiabile. Non è un ripiego per gli screen
         reader: è il posto da cui si prendono i numeri per metterli altrove. --}}
    <details class="mt-3">
        <summary class="cursor-pointer text-xs text-ink-2">Vedi i dati</summary>

        <table class="mt-2 w-full text-sm">
            <caption class="sr-only">{{ $sintesi }}</caption>
            <thead>
                <tr>
                    <th scope="col" class="py-1 text-left font-medium text-ink-2">Mese</th>
                    <th scope="col" class="py-1 text-right font-medium text-ink-2">{{ $unita !== '' ? \Illuminate\Support\Str::ucfirst($unita) : 'Valore' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($serie->righe() as $riga)
                    <tr class="border-t border-border">
                        <th scope="row" class="py-1 text-left font-normal text-ink">{{ $riga['etichetta'] }} {{ \Illuminate\Support\Str::substr($riga['mese'], 0, 4) }}</th>
                        <td class="py-1 text-right tabular-nums text-ink">{{ $riga['valore'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>

@props([
    'voci' => [],
    'titolo',
    'sottotitolo' => null,
    'unita' => '',
])

{{-- La barra di composizione — 🔗 DS §2.5/§8.2, campione §10 `.el-meter`, ADR-034.

     🔴 **La legenda È il contenuto accessibile, e non è una formalità.** In tema
     scuro la coppia peggiore dei token dei grafici — verde ↔ arancione — scende a
     ΔE 6,9 (DS §2.5), sotto la soglia di sicurezza: una legenda a soli quadratini
     colorati sarebbe un **bug**, non uno stile. Ogni voce porta quindi la
     tripletta di DS §4 — colore **+ glifo + etichetta** — esattamente come
     `x-ui.semaforo`, e il quadratino è `aria-hidden` perché è ridondanza visiva,
     non informazione.

     La **barra** è `aria-hidden`: i suoi numeri sono già nella legenda, e farli
     leggere due volte è peggio che non leggerli.

     ⛔ **La sola cosa che va in `style` è la larghezza percentuale**, perché
     Tailwind non genera larghezze arbitrarie da un valore calcolato a runtime; il
     **colore resta una classe**, per la ragione scritta per esteso nel commento
     di `x-ui.sparkline`. Le classi arrivano come stringhe letterali complete da
     `AndamentoPiattaforma::COLORI_PIANO`: `'bg-chart-'.$codice` non genererebbe
     nulla — il compilatore scansiona il sorgente, non valuta PHP — e il sintomo
     sarebbe un segmento invisibile su una pagina che risponde 200.

     ⚠️ Il distacco fra segmenti è un bordo del colore della **superficie**, non
     bianco: così resta un distacco anche in tema scuro (è la nota del campione).

     ⚠️ **`unita` si RENDE, e in forma leggibile solo da chi ascolta.** La
     legenda è tutto ciò che uno screen reader riceve da questo componente
     (la barra è `aria-hidden`), e «SaaS 12 50,0%» è un numero senza unità di
     misura. Il grafico a barre accanto annuncia «…, totale 13 clienti»: le due
     forme devono raccontare la stessa cosa. Un prop dichiarato in `@props` e mai
     usato nel corpo è peggio di un prop assente — Blade lo toglie da
     `$attributes`, quindi chi domani passasse `unita="account"` non vedrebbe né
     effetto né errore, che è la forma di guasto peggiore di questo progetto.

     Ogni voce è `['etichetta' => …, 'valore' => int, 'classe' => 'bg-chart-…',
     'glifo' => '●']`. --}}

@php
    use App\Support\Piattaforma\GeometriaGrafico;

    $valori = array_map(fn (array $v) => (int) $v['valore'], $voci);
    $percentuali = GeometriaGrafico::segmenti($valori);
    $totale = array_sum($valori);
@endphp

<figure {{ $attributes->merge(['class' => 'm-0']) }}>
    <figcaption class="text-sm font-semibold text-ink">{{ $titolo }}</figcaption>

    @if ($sottotitolo)
        <p class="mt-0.5 text-xs text-ink-3">{{ $sottotitolo }}</p>
    @endif

    <div aria-hidden="true" class="mt-3 flex h-2 overflow-hidden rounded-full bg-surface-sunken ring-1 ring-inset ring-border">
        @foreach ($voci as $i => $voce)
            @if ($percentuali[$i] > 0)
                <span class="block h-full border-l-2 border-surface first:border-l-0 {{ $voce['classe'] }}"
                      style="width: {{ $percentuali[$i] }}%"></span>
            @endif
        @endforeach
    </div>

    <ul class="mt-3 space-y-1">
        @foreach ($voci as $i => $voce)
            <li class="flex items-baseline gap-2 text-sm text-ink-2">
                {{-- Quadratino: ridondanza visiva. Il glifo accanto è ciò che
                     regge quando i due colori si somigliano. --}}
                <span aria-hidden="true" class="mt-0.5 inline-block size-2.5 shrink-0 rounded-xs {{ $voce['classe'] }}"></span>
                <span aria-hidden="true" class="text-ink-3">{{ $voce['glifo'] }}</span>
                <span class="text-ink">{{ $voce['etichetta'] }}</span>
                <span class="ml-auto tabular-nums text-ink">{{ $voce['valore'] }}@if ($unita !== '')<span class="sr-only"> {{ $unita }}</span>@endif</span>
                <span class="w-14 text-right tabular-nums text-ink-3">{{ number_format($percentuali[$i], 1, ',', '.') }}%</span>
            </li>
        @endforeach
    </ul>

    @if ($totale === 0)
        <p class="mt-2 text-xs text-ink-3">Nessun dato da ripartire.</p>
    @endif
</figure>

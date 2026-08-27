@props(['valori' => []])

{{-- La microlinea di andamento — 🔗 DS §2.5/§8.2, campione §10 `.el-spark`.

     ⚠️ **È DECORATIVA, e la decisione è meccanica.** `aria-hidden="true"` e
     `focusable="false"`, nessun `role`, nessun `aria-label`: chi la usa deve
     mettere accanto il numero **a parole** («+7 in 12 mesi»), o l'informazione
     esiste solo per chi la vede. È la stessa disciplina di `x-ui.semaforo`, che
     al glifo affianca sempre l'etichetta.

     ⛔ **I colori sono CLASSI, mai attributi di presentazione e mai esadecimali.**
     Il campione del Design System scrive le tinte dei grafici come variabili CSS
     dentro gli attributi perché è un file standalone senza Tailwind: qui sarebbe
     sbagliato due volte — `PaletteGuardrailTest` cerca **classi** (i suoi
     prefissi includono già `fill` e `stroke`), quindi una tinta scritta in un
     attributo lo rende cieco, e un esadecimale crudo non lo vede *nessuna* rete
     del progetto. Si usano `fill-chart-band`, `stroke-chart-brand` e
     `stroke-surface`: tutti in `@theme`, tutti già riscritti dal tema scuro e dal
     blocco di stampa. Nessuna variante di tema scritta a mano qui dentro:
     romperebbe proprio ciò che in `app.css` funziona già.

     ⚠️ `preserveAspectRatio="none"` deforma le unità in orizzontale, quindi la
     linea e il pallino portano `vector-effect="non-scaling-stroke"`: senza, lo
     spessore si allunga con la card. --}}

@php
    use App\Support\Piattaforma\GeometriaGrafico;

    // `null` su serie vuota: il componente non disegna nulla, che è diverso dal
    // disegnare una linea piatta a zero — quella si legge come un dato.
    $p = GeometriaGrafico::percorsoSparkline($valori);
@endphp

@if ($p !== null)
    <svg viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true" focusable="false"
         {{ $attributes->merge(['class' => 'mt-2 block h-8 w-full overflow-visible']) }}>
        <path class="fill-chart-band" d="{{ $p['area'] }}" />
        <path class="fill-none stroke-chart-brand" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              vector-effect="non-scaling-stroke" d="{{ $p['linea'] }}" />
        <circle class="fill-chart-brand stroke-surface" stroke-width="2" vector-effect="non-scaling-stroke"
                r="2.6" cx="{{ $p['ultimoX'] }}" cy="{{ $p['ultimoY'] }}" />
    </svg>
@endif

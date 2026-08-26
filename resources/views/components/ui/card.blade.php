{{-- La superficie piena della pagina — 🔗 DS §5.2, §8.2, campione `.el-card`.
     Superficie, bordo e ombra vengono dai token semantici: il tema si scambia
     sotto, e nessuna variante di tema è scritta a mano qui dentro. --}}
<div {{ $attributes->merge(['class' => 'rounded-lg border border-border bg-surface p-4 shadow-sm md:p-6']) }}>
    {{ $slot }}
</div>

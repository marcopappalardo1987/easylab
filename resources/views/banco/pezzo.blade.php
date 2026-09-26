{{--
    La cornice di UN pezzo del banco: titolo, i **token che il pezzo usa**, il
    pezzo montato dal vero, e una nota facoltativa.

    ⚠️ **I token scritti accanto sono metà del valore del banco.** È il modo in
    cui `docs/Design/design-system.html` è costruito (`.el-spec` sotto ogni
    blocco) ed è la ragione per cui funziona: guardare un bottone dice se è
    bello, leggere `bg-brand · text-brand-ink` dice **da dove viene** quel
    colore, cioè cosa cambiare e dove. Un banco senza la didascalia è una
    galleria; con la didascalia è una mappa.

    ⚠️ **Si monta con `@component` e non con `<x-…>`**: un componente anonimo
    dovrebbe vivere in `resources/views/components/`, e quella cartella è la
    libreria **dell'applicazione** — il ponteggio del banco non ci entra, o
    domani qualcuno lo userebbe in una pagina vera. `@component` prende la vista
    per nome, quindi la cornice resta dentro `resources/views/banco/`.

    ⚠️ **Nella didascalia una classe di scala non si nomina per esteso**, e non
    è pedanteria: `SuperficiTokenizzateGuardrailTest` legge il **sorgente**, e
    toglie i commenti Blade ma non le stringhe PHP — quindi un `text-danger-600`
    scritto qui dentro per dire «questo NON si usa» è, per la rete, un uso. È
    diventato rosso davvero, due volte, scrivendo questo file. Si nomina il
    gradino senza il prefisso dell'utility: «danger-600», non «text-danger-600».

    Variabili: `$titolo`, `$token`, e facoltative `$nota` e `$fondo` (la classe
    di superficie su cui posare il pezzo — serve a `x-app.nav-link`, che va
    guardato su `bg-surface-sunken` perché è lì che vive).
--}}
<section class="overflow-hidden rounded-lg border border-border bg-surface shadow-sm">
    <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-border px-4 py-3">
        <h3 class="text-sm font-semibold text-ink">{{ $titolo }}</h3>
        <p class="font-mono text-xs break-all text-ink-3">{{ $token }}</p>
    </header>

    <div class="flex flex-wrap items-center gap-4 p-4 {{ $fondo ?? '' }}">
        {{ $slot }}
    </div>

    @isset($nota)
        <p class="border-t border-border bg-surface-sunken px-4 py-2 text-xs text-ink-2">{{ $nota }}</p>
    @endisset
</section>

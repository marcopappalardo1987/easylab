@props(['strumento'])

{{--
    Badge obsolescenza (ADR-014, Design System §4): elemento SEPARATO dal
    semaforo, con cui convive — l'obsolescenza è una segnalazione sull'età della
    macchina, non uno stato manutentivo, e non blocca nulla.

    Componente dedicato e non variante di x-ui.badge: così la condizione, il
    glifo, l'etichetta e il tooltip vivono in un posto solo (scheda, elenco e
    domani la dashboard S6). Stesso schema di x-ui.semaforo-forzato.
--}}
@if ($strumento->isObsoleto())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-obsolete-500/10 px-2 py-0.5 text-xs font-medium text-obsolete-500']) }}
        title="Installato il {{ $strumento->data_installazione->format('d/m/Y') }} — oltre la soglia di {{ $strumento->sogliaObsolescenza() }} anni">
        <span aria-hidden="true">⏳</span> Obsoleto
    </span>
@endif

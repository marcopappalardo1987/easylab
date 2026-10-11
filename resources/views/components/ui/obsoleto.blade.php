@props(['strumento'])

{{--
    Badge obsolescenza (ADR-014, Design System §4): elemento SEPARATO dal
    semaforo, con cui convive — l'obsolescenza è una segnalazione sull'età della
    macchina, non uno stato manutentivo, e non blocca nulla.

    Componente dedicato e non variante di x-ui.badge: così la condizione, il
    glifo, l'etichetta e il tooltip vivono in un posto solo (scheda, elenco e
    domani la dashboard S6). Stesso schema di x-ui.semaforo-forzato.

    ⚠️ **Il badge era una velatura del gradino pieno** (`obsolete-500/10` di
    fondo, `obsolete-500` di testo) e ora monta la coppia `obs-soft` /
    `obs-soft-ink` di DS §8.2, che è ciò che il campione monta per i badge
    (`.el-badge.b-obsolete`). Non è solo una riscrittura di nomi: un'opacità è
    calcolata **sul fondo che ha sotto**, e sulla superficie scura `#111C2E` un
    viola al 10% è quasi il fondo stesso — il badge smetterebbe di essere un
    badge proprio nel tema in cui serve di più. Il glifo ⏳ e la parola
    «Obsoleto» restano dove sono: la tripletta di DS §4 vale anche qui.
--}}
@if ($strumento->isObsoleto())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full bg-obs-soft px-2 py-0.5 text-xs font-medium text-obs-soft-ink']) }}
        title="Installato il {{ $strumento->data_installazione->format('d/m/Y') }} — oltre la soglia di {{ $strumento->sogliaObsolescenza() }} anni">
        <span aria-hidden="true">⏳</span> {{ \App\Models\Strumento::ETICHETTA_OBSOLETO }}
    </span>
@endif

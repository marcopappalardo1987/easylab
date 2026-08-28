{{--
    Il layout del corpo TESTUALE.

    ⛔ **`Markdown::renderText()` non fa parsing markdown**: rende questo Blade e
    applica `strip_tags` (qui sotto) più `html_entity_decode`. Tutto ciò che è
    sintassi markdown — `#`, `**`, `[etichetta](url)` — arriverebbe quindi
    grezzo a chi legge il `text/plain`, ed è il difetto che ADR-011 elenca.

    ⚠️ **La ripulitura sta QUI e non nelle singole viste**, perché questo è
    l'unico punto da cui passa tutto: testata, corpo, sottotesto e piè di pagina.
    `text/table.blade.php` normalizza in più le proprie righe (pipe e riga di
    separazione), che sono un problema della tabella e non del markdown in
    generale; `TestoEmail::senzaMarkdown()` è idempotente apposta, così il
    secondo passaggio su quelle righe non le tocca.
--}}
@php
    use App\Support\Mail\TestoEmail;
@endphp
{!! TestoEmail::senzaMarkdown(strip_tags($header ?? '')) !!}

{!! TestoEmail::senzaMarkdown(strip_tags($slot)) !!}
@isset($subcopy)

{!! TestoEmail::senzaMarkdown(strip_tags($subcopy)) !!}
@endisset

{!! TestoEmail::senzaMarkdown(strip_tags($footer ?? '')) !!}

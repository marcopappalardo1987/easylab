{{--
    Paginazione Livewire — versione Easy Lab.

    Sovrascrive la vista di default `livewire::tailwind`, quindi vale per ogni
    lista paginata dell'app senza doverla indicare a mano.

    Differenze dall'originale: testi in italiano, palette del design system
    (neutral/primary invece di gray/blue, niente varianti dark) e NESSUN
    riepilogo "Showing X to Y of Z" — il conteggio è già accanto al selettore
    "Righe per pagina", e ripeterlo confonde.
--}}
@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    $bottone = 'inline-flex items-center justify-center rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-primary-600';
    $inattivo = 'inline-flex items-center justify-center rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm font-medium text-neutral-300';
    $attivo = 'inline-flex items-center justify-center rounded-md border border-primary-600 bg-primary-600 px-3 py-2 text-sm font-medium text-white';
    $ellissi = 'inline-flex items-center justify-center px-1.5 py-2 text-sm text-neutral-400';
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginazione" class="flex items-center justify-end gap-1">
        {{-- Mobile: solo precedente/successiva --}}
        <div class="flex flex-1 items-center justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="{{ $inattivo }}" aria-disabled="true">Precedente</span>
            @else
                <button type="button" class="{{ $bottone }}" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                    x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">Precedente</button>
            @endif

            <span class="text-sm text-neutral-500">Pagina {{ $paginator->currentPage() }} di {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <button type="button" class="{{ $bottone }}" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                    x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">Successiva</button>
            @else
                <span class="{{ $inattivo }}" aria-disabled="true">Successiva</span>
            @endif
        </div>

        {{-- Desktop: precedente · numeri con ellissi · successiva --}}
        <div class="hidden items-center gap-1 sm:flex">
            @if ($paginator->onFirstPage())
                <span class="{{ $inattivo }}" aria-disabled="true">‹ Precedente</span>
            @else
                <button type="button" class="{{ $bottone }}" wire:click="previousPage('{{ $paginator->getPageName() }}')"
                    x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                    aria-label="Pagina precedente">‹ Precedente</button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $ellissi }}" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span wire:key="pag-{{ $paginator->getPageName() }}-{{ $page }}"
                                class="{{ $attivo }}" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" wire:key="pag-{{ $paginator->getPageName() }}-{{ $page }}"
                                class="{{ $bottone }}" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                                aria-label="Vai a pagina {{ $page }}">{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" class="{{ $bottone }}" wire:click="nextPage('{{ $paginator->getPageName() }}')"
                    x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                    aria-label="Pagina successiva">Successiva ›</button>
            @else
                <span class="{{ $inattivo }}" aria-disabled="true">Successiva ›</span>
            @endif
        </div>
    </nav>
@endif

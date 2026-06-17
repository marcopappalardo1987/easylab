<div class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
    <div class="mx-auto w-full max-w-md">

        {{-- Intestazione --}}
        <div class="text-center">
            <span class="inline-flex items-center gap-1 rounded-full bg-success-100 px-2 py-0.5 text-xs font-medium text-success-500">
                ● TALL stack
            </span>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-neutral-900">Verifica stack</h1>
            <p class="mt-1 text-sm text-neutral-600">Componente temporaneo — conferma che Livewire e Alpine sono operativi.</p>
        </div>

        {{-- Card --}}
        <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">

            {{-- Test 1: reattività Livewire (wire:click, niente reload) --}}
            <div>
                <h2 class="text-sm font-medium text-neutral-800">Livewire — reattività server</h2>
                <div class="mt-2 flex items-center gap-4">
                    <span class="text-3xl font-semibold tabular-nums text-neutral-900">{{ $count }}</span>
                    <button type="button" wire:click="increment"
                            class="inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                        + Incrementa
                    </button>
                </div>
                <p class="mt-1 text-xs text-neutral-400">Il numero sale senza ricaricare la pagina.</p>
            </div>

            <hr class="my-6 border-neutral-200">

            {{-- Test 2: reattività Alpine (x-data/x-show, lato client) --}}
            <div x-data="{ open: false }">
                <h2 class="text-sm font-medium text-neutral-800">Alpine — reattività client</h2>
                <button type="button" x-on:click="open = !open"
                        class="mt-2 inline-flex items-center justify-center rounded-md border border-neutral-200 bg-white px-4 py-2.5 font-medium text-neutral-800 transition hover:bg-neutral-50">
                    <span x-text="open ? 'Nascondi dettaglio' : 'Mostra dettaglio'"></span>
                </button>
                <div x-show="open" x-cloak class="mt-3 rounded-md bg-primary-50 px-3 py-2 text-sm text-primary-900">
                    Alpine funziona: questo blocco è gestito interamente lato client.
                </div>
            </div>

            <hr class="my-6 border-neutral-200">

            {{-- Versioni --}}
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-neutral-600">PHP</dt><dd class="font-medium tabular-nums text-neutral-900">{{ $phpVersion }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-600">Laravel</dt><dd class="font-medium tabular-nums text-neutral-900">{{ $laravelVersion }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-600">Livewire</dt><dd class="font-medium tabular-nums text-neutral-900">{{ $livewireVersion }}</dd></div>
            </dl>
        </div>

        <p class="mt-6 text-center text-xs text-neutral-400">Rotta di verifica · rimovibile dal punto 10</p>
    </div>
</div>

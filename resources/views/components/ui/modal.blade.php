@props([
    'title' => null,
    'close' => null,
])

<div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true"
    @if ($close) x-data x-on:keydown.escape.window="$wire.{{ $close }}()" @endif>
    {{-- Backdrop --}}
    <div class="fixed inset-0 bg-neutral-900/40"
        @if ($close) wire:click="{{ $close }}" @endif></div>

    {{-- Panel --}}
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="relative w-full max-w-lg rounded-lg bg-white p-6 shadow-md">
            <div class="flex items-start justify-between gap-4">
                @if ($title)
                    <h2 class="text-lg font-semibold text-neutral-900">{{ $title }}</h2>
                @endif
                @if ($close)
                    <button type="button" wire:click="{{ $close }}"
                        class="-m-2 rounded-md p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                        aria-label="Chiudi">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endif
            </div>

            <div class="mt-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>

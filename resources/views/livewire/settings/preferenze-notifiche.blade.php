<div class="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6">

    {{-- Header --}}
    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Notifiche</h1>

    <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-neutral-900">Riepilogo email delle scadenze</h2>
                <p class="mt-1 text-sm text-neutral-600">
                    Un'email al giorno con le scadenze appena superate e quelle in arrivo
                    nei prossimi 30 giorni. Nessuna email nei giorni in cui non cambia nulla.
                </p>
            </div>

            <button type="button"
                    wire:click="$toggle('riceveEmailScadenze')"
                    role="switch"
                    aria-checked="{{ $riceveEmailScadenze ? 'true' : 'false' }}"
                    aria-label="Riepilogo email delle scadenze"
                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none {{ $riceveEmailScadenze ? 'bg-primary-600' : 'bg-neutral-200' }}">
                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $riceveEmailScadenze ? 'translate-x-5' : 'translate-x-0' }}"></span>
            </button>
        </div>

        <hr class="my-6 border-neutral-200">

        <p class="text-sm text-neutral-600">
            Le notifiche <strong>in applicazione</strong> restano sempre attive: sono la
            copia di ciò che vedi comunque entrando, e non lasciano Easy Lab.
        </p>

        <div class="mt-6 flex items-center gap-3">
            <button type="button" wire:click="salva"
                    class="inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                Salva
            </button>

            <span x-data="{ visibile: false }"
                  x-on:preferenze-salvate.window="visibile = true; setTimeout(() => visibile = false, 2500)"
                  x-show="visibile" x-cloak
                  class="text-sm text-success-600">Preferenze salvate.</span>
        </div>
    </div>
</div>

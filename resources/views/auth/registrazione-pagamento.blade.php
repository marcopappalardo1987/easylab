<x-guest-layout title="Attiva l'abbonamento — Easy Lab">
    {{--
        L'ultimo passo prima di Stripe. `$azione` è una URL **firmata a sé**: la
        firma che ha aperto questa pagina vale per il GET, non per il POST.
    --}}
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-md">
            <div class="flex flex-col items-center text-center">
                <x-brand-logo variante="completo" class="h-20 w-auto sm:h-24" />
                <h1 class="sr-only">Easy Lab — attiva il tuo abbonamento</h1>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
                <h2 class="text-lg font-semibold text-ink">Indirizzo confermato</h2>
                <p class="mt-1 text-sm text-ink-2">
                    Manca solo il pagamento: l'account viene creato quando la transazione va a buon fine.
                </p>

                <dl class="mt-6 space-y-3 border-t border-border pt-6 text-sm">
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-ink-2">Laboratorio</dt>
                        <dd class="text-right font-medium text-ink">{{ $registrazione->nome_ente }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-ink-2">Email</dt>
                        <dd class="min-w-0 truncate text-right font-medium text-ink">{{ $registrazione->email }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-ink-2">Piano</dt>
                        <dd class="text-right font-medium text-ink">{{ $piano }}</dd>
                    </div>
                </dl>

                @if ($vendibile)
                    <form method="POST" action="{{ $azione }}" class="mt-6">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                            Vai al pagamento
                        </button>
                    </form>

                    <p class="mt-4 text-center text-xs text-ink-3">
                        Il pagamento avviene su Stripe. I dati della carta non passano da Easy Lab.
                    </p>
                @else
                    {{-- Il piano scelto non è più vendibile: archiviato dal
                         listino, o rimasto senza price di Stripe fra il modulo e
                         adesso. Non si ripiega su un altro piano — sceglierlo al
                         posto di chi paga sarebbe peggio del rifiuto. --}}
                    <div class="mt-6 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        Il piano che avevi scelto non è più disponibile. Ricompila il modulo per sceglierne uno attivo:
                        non è stato addebitato nulla.
                    </div>
                    <div class="mt-4">
                        <x-ui.button variant="secondary" :href="route('registrazione.mostra')">Ricomincia</x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    </main>
</x-guest-layout>

<x-guest-layout title="Registrazione — Easy Lab">
    {{--
        Il ritorno da Stripe. Due esiti e nessuna via di mezzo.

        ⛔ **Il ramo «non ancora» non mostra MAI il motivo tecnico.** Un rifiuto
        di `CompletaRegistrazione` dice che un'email appartiene già a un
        amministratore, o che un piano è sparito dal listino: informazioni
        operative su una superficie pubblica. Il testo vero sta nel log; qui si
        legge «ci pensiamo noi».

        ⛔ E **nessuno viene autenticato da questa pagina**: il ruolo Admin ha il
        2FA obbligatorio, e la catena giusta è pagamento → login → 2FA.
    --}}
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-md">
            <div class="flex flex-col items-center text-center">
                <x-brand-logo variante="completo" class="h-20 w-auto sm:h-24" />
                <h1 class="sr-only">Easy Lab — esito della registrazione</h1>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 text-center shadow-sm md:p-8">
                @if ($riuscita)
                    <h2 class="text-lg font-semibold text-ink">{{ $registrazione->nome_ente }} è attivo</h2>
                    <p class="mt-2 text-sm text-ink-2">
                        Il pagamento è andato a buon fine e il tuo account è pronto.
                        Accedi con <span class="font-medium text-ink">{{ $registrazione->email }}</span>
                        e la password che hai scelto.
                    </p>
                    <p class="mt-4 text-sm text-ink-3">
                        Al primo accesso ti chiederemo di attivare la verifica in due passaggi:
                        è obbligatoria per chi amministra un account.
                    </p>
                    <div class="mt-6">
                        <x-ui.button :href="route('login')">Accedi a Easy Lab</x-ui.button>
                    </div>
                @else
                    <h2 class="text-lg font-semibold text-ink">Stiamo completando l'attivazione</h2>
                    <p class="mt-2 text-sm text-ink-2">
                        Non risulta ancora un pagamento confermato per questa registrazione.
                        Se hai appena pagato può volerci qualche istante: ti scriviamo noi
                        a <span class="font-medium text-ink">{{ $registrazione->email }}</span>
                        appena l'account è pronto.
                    </p>
                    <p class="mt-4 text-sm text-ink-3">
                        Se invece hai interrotto il pagamento, non è stato addebitato nulla
                        e puoi riprendere quando vuoi.
                    </p>
                    <div class="mt-6">
                        <x-ui.button variant="secondary" :href="route('login')">Torna all'accesso</x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    </main>
</x-guest-layout>

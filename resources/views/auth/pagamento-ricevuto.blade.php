<x-guest-layout title="Pagamento ricevuto — Easy Lab">
    {{--
        Dove Stripe rimanda chi ha pagato su un Payment Link (🔗 ADR-039).

        ⛔ **Non afferma niente che non sappia.** La pagina non legge la sessione
        e non provisiona: l'account lo fa nascere il webhook, che può arrivare
        qualche secondo dopo questo redirect. Scrivere «il tuo account è pronto»
        sarebbe quindi vero *quasi* sempre, cioè falso quando conta — e chi
        andasse a fare il login troverebbe una porta chiusa senza capire perché.
        Si dice cosa succede adesso e dove guardare.

        ⛔ E **nessun dato del pagamento**: l'unico parametro in URL è un id di
        sessione di Stripe, che non prova l'identità di nessuno. Mostrare cifra,
        email o ragione sociale a chi apre quell'indirizzo sarebbe pubblicare i
        dati di un cliente a chiunque conosca — o indovini — un `cs_...`.
    --}}
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-md">
            <div class="flex flex-col items-center text-center">
                <x-brand-logo variante="completo" class="h-20 w-auto sm:h-24" />
                <h1 class="sr-only">Easy Lab — pagamento ricevuto</h1>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 text-center shadow-sm md:p-8">
                <h2 class="text-lg font-semibold text-ink">Pagamento ricevuto</h2>

                <p class="mt-2 text-sm text-ink-2">
                    Grazie. Stiamo preparando il tuo account: fra pochi istanti riceverai
                    un&rsquo;email con il link per <strong>scegliere la password</strong> ed entrare.
                </p>

                <p class="mt-4 text-sm text-ink-3">
                    Arriva all&rsquo;indirizzo che hai indicato a Stripe. Se non la vedi,
                    controlla lo spam prima di riprovare a pagare: il pagamento è già registrato.
                </p>

                <p class="mt-4 text-sm text-ink-3">
                    Al primo accesso ti chiederemo di attivare la verifica in due passaggi:
                    è obbligatoria per chi amministra un account.
                </p>
            </div>
        </div>
    </main>
</x-guest-layout>

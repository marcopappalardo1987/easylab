<x-guest-layout title="Controlla la posta — Easy Lab">
    {{--
        🔴 **Questa pagina è la difesa anti-enumerazione, non un messaggio di
        cortesia.** È identica per chi si è appena registrato e per chi ha usato
        l'email di un account che esiste già: stesso testo, stesso indirizzo
        mostrato, stessa assenza di dettagli. Ciò che cambia è solo cosa arriva
        nella casella — che è raggiungibile dal solo proprietario. Aggiungere
        qui una riga del tipo «se hai già un account…» rimetterebbe in piedi
        esattamente la fuga che il controller ha appena chiuso.
    --}}
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-md">
            <div class="flex flex-col items-center text-center">
                <x-brand-logo variante="completo" class="h-20 w-auto sm:h-24" />
                <h1 class="sr-only">Easy Lab — conferma il tuo indirizzo</h1>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 text-center shadow-sm md:p-8">
                <h2 class="text-lg font-semibold text-ink">Controlla la posta</h2>

                <p class="mt-2 text-sm text-ink-2">
                    @if ($email)
                        Abbiamo scritto a <span class="font-medium text-ink">{{ $email }}</span>.
                    @else
                        Ti abbiamo scritto all'indirizzo che hai indicato.
                    @endif
                    Apri il messaggio e conferma l'indirizzo per proseguire.
                </p>

                <p class="mt-4 text-sm text-ink-3">
                    Il link vale un'ora. Se non arriva nulla, controlla la posta indesiderata
                    e poi ricompila il modulo: te ne inviamo uno nuovo.
                </p>

                <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
                    <x-ui.button variant="secondary" :href="route('registrazione.mostra')">Ricompila il modulo</x-ui.button>
                    <x-ui.button variant="ghost" :href="route('login')">Vai all'accesso</x-ui.button>
                </div>
            </div>
        </div>
    </main>
</x-guest-layout>

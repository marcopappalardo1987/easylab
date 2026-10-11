<x-guest-layout title="Crea il tuo account — Easy Lab">
    {{--
        Il modulo pubblico del self-signup (🔗 ADR-012, ADR-032).

        ⛔ **HTML e `@csrf`, non un componente Livewire.** `throttle` è un
        middleware di ROTTA e ogni update Livewire passa da `/livewire/update`:
        un form Livewire avrebbe il rate limiting sul solo GET iniziale, cioè
        non l'avrebbe. Vedi il docblock di `RegistrazionePubblica`.

        ⚠️ Solo token semantici (`bg-surface`, `text-ink`, `border-border`) e
        nessuna variante `dark:`: il tema si scambia sotto, e
        `SuperficiTokenizzateGuardrailTest` ha la lista da migrare ormai vuota.
    --}}
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-lg">

            <div class="flex flex-col items-center text-center">
                <x-brand-logo variante="completo" class="h-20 w-auto sm:h-24" />
                <h1 class="sr-only">Easy Lab — crea il tuo account</h1>
            </div>

            <div class="mt-8 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">

                @if (count($piani) === 0)
                    {{-- 🔴 Guasto di CONFIGURAZIONE, non piano gratuito. Il price
                         di Stripe non è agganciato su questo ambiente, e la
                         dottrina del progetto (`easylab:abbona`, `App\Support\Piani`)
                         è di dirlo invece di ripiegare su qualcosa di gratuito:
                         un ripiego rassicurante nasconderebbe l'errore e
                         regalerebbe account. --}}
                    <h2 class="text-lg font-semibold text-ink">Registrazioni chiuse</h2>
                    <p class="mt-2 text-sm text-ink-2">
                        In questo momento non è possibile creare un account da qui.
                        Scrivici e ti apriamo noi il tuo spazio di lavoro.
                    </p>
                    <div class="mt-6">
                        <x-ui.button variant="secondary" :href="route('login')">Torna all'accesso</x-ui.button>
                    </div>
                @else
                    <h2 class="text-lg font-semibold text-ink">Crea il tuo account</h2>
                    <p class="mt-1 text-sm text-ink-2">
                        Ti mandiamo un'email per confermare l'indirizzo, poi si passa al pagamento.
                        L'account viene creato solo dopo.
                    </p>

                    @if ($errors->any())
                        <div class="mt-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('registrazione.avvia') }}" class="mt-6 space-y-5">
                        @csrf

                        {{-- 🔴 L'honeypot. Nascosto e fuori dal flusso di
                             tabulazione, con `autocomplete="off"` — o il gestore
                             di password del browser lo compilerebbe al posto di
                             un utente vero, bloccando una persona. Il nome è
                             plausibile (`sito_web`) apposta: un campo chiamato
                             «honeypot» un bot lo salta. --}}
                        <div class="hidden" aria-hidden="true">
                            <label for="{{ $campoTrappola }}">Non compilare questo campo</label>
                            <input type="text" id="{{ $campoTrappola }}" name="{{ $campoTrappola }}"
                                   tabindex="-1" autocomplete="off" value="">
                        </div>

                        <x-ui.input label="Nome del laboratorio o dello studio" name="nome_ente" type="text"
                                    required autofocus value="{{ old('nome_ente') }}"
                                    placeholder="Laboratorio Analisi Aurora" />

                        <x-ui.input label="Il tuo nome e cognome" name="nome_referente" type="text"
                                    required autocomplete="name" value="{{ old('nome_referente') }}"
                                    placeholder="Maria Bianchi" />

                        <x-ui.input label="Email" name="email" type="email" required autocomplete="username"
                                    value="{{ old('email') }}" placeholder="nome@laboratorio.it" />

                        <x-ui.input label="Password" name="password" type="password" required
                                    autocomplete="new-password" rivelabile />
                        <x-ui.input label="Conferma password" name="password_confirmation" type="password"
                                    required autocomplete="new-password" />

                        {{-- Il piano. Un elenco di radio anche quando ce n'è uno
                             solo: così la scelta è sempre esplicita nel POST, e
                             il giorno in cui il listino ne offrirà due questa
                             vista non cambia. I prezzi vengono dal LISTINO A
                             DATABASE (`App\Support\Piani`), mai da
                             `config/easylab.php`, che dal 27 Ago 2026 è solo il
                             bootstrap (ADR-035). --}}
                        <fieldset>
                            <legend class="block text-sm font-medium text-ink">Piano</legend>
                            <div class="mt-2 space-y-2">
                                @foreach ($piani as $indice => $unPiano)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-border-strong bg-surface p-3 hover:bg-surface-sunken">
                                        <input type="radio" name="piano" value="{{ $unPiano->codice }}"
                                               class="mt-1 h-4 w-4 border border-border text-brand focus:ring-ring"
                                               @checked(old('piano', $pianoPreselezionato ?? $piani[0]->codice) === $unPiano->codice)>
                                        <span class="min-w-0">
                                            <span class="block font-medium text-ink">{{ $unPiano->etichetta }}</span>
                                            <span class="block text-sm text-ink-2">
                                                {{ number_format($unPiano->prezzo_mensile_cent / 100, 2, ',', '.') }} € al mese
                                                @if ($unPiano->max_enti !== null)
                                                    · fino a {{ $unPiano->max_enti }} {{ $unPiano->max_enti === 1 ? 'sede' : 'sedi' }}
                                                @else
                                                    · sedi illimitate
                                                @endif
                                                · {{ $unPiano->tettoStrumentiInParole() }}
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <button type="submit"
                                class="flex w-full items-center justify-center rounded-md bg-brand px-4 py-2.5 font-medium text-brand-ink transition hover:bg-brand-hover focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-canvas focus:outline-none">
                            Continua
                        </button>
                    </form>
                @endif
            </div>

            <p class="mt-6 text-center text-sm text-ink-2">
                Hai già un account? <a href="{{ route('login') }}" class="font-medium text-brand hover:text-brand-hover">Accedi</a>
            </p>
        </div>
    </main>
</x-guest-layout>

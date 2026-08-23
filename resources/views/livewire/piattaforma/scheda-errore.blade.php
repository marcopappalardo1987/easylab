{{--
    L'error tracker interno — la scheda di una issue.

    ⚠️ **Sola lettura, e senza un solo `wire:click`.** Il dettaglio di ogni
    occorrenza si apre con un `<details>` **nativo**: nessun round-trip, nessuna
    azione, e la pagina resta leggibile anche se JavaScript non parte. Una
    schermata che si consulta quando l'applicazione sta già andando male è il
    posto sbagliato per dipendere da altro codice.

    ⚠️ **Qui dentro ci sono dati personali** — stack trace, ip, user agent,
    input di richiesta — e la sanificazione è avvenuta **in scrittura**, a monte
    (blocco 4): ciò che si legge qui è già ciò che resta dopo la denylist. Non
    si aggiunge nessuna ripulitura di facciata, che darebbe l'impressione che il
    database sia più pulito di quanto è.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <a href="{{ route('piattaforma.errori') }}" class="text-sm text-primary-700 hover:underline">
            <span aria-hidden="true">←</span> Torna agli errori
        </a>
    </div>

    <div class="mt-3">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">
            {{ class_basename($errore->classe) }}
        </h1>
        <p class="mt-1 font-mono text-xs text-neutral-500">{{ $errore->classe }}</p>
        <p class="mt-1 font-mono text-sm text-neutral-700">{{ $errore->file }}:{{ $errore->riga }}</p>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        @if ($errore->stato === 'risolto')
            <x-ui.badge variant="success">Risolto</x-ui.badge>
            <span class="text-xs text-neutral-500">
                da {{ $errore->risoltoDa?->name ?? 'un utente non più presente' }}
                @if ($errore->risolto_at) il {{ $errore->risolto_at->format('d/m/Y H:i') }} @endif
            </span>
        @elseif ($errore->stato === 'ignorato')
            <x-ui.badge variant="neutral">Ignorato</x-ui.badge>
        @else
            <x-ui.badge variant="danger">Aperto</x-ui.badge>
            @if ($errore->riaperto_automaticamente_at)
                <span class="text-xs text-warning-800">
                    riaperto automaticamente il {{ $errore->riaperto_automaticamente_at->format('d/m/Y H:i') }}
                </span>
            @endif
        @endif
    </div>

    {{-- 🔴 **Le due cifre nella stessa frase**, dallo stesso componente
         dell'elenco: qui pesa più che là, perché è questa la pagina in cui si
         scorrono le occorrenze conservate — e vederne dieci sotto un contatore
         che dice diecimila, senza la seconda cifra a spiegarlo, si legge come
         una perdita di dati. --}}
    <p class="mt-4 text-sm text-neutral-700">
        <x-errori.cifre :errore="$errore" />
    </p>
    <p class="mt-1 text-sm text-neutral-600 tabular-nums">
        prima volta: {{ $errore->prima_occorrenza_at?->format('d/m/Y H:i:s') ?? '—' }}
        ·
        ultima volta: {{ $errore->ultima_occorrenza_at?->format('d/m/Y H:i:s') ?? '—' }}
    </p>

    {{-- 🔴 **Il messaggio è un campione, e va etichettato come tale.**
         L'impronta che raggruppa le occorrenze è `classe + due frame`: il
         messaggio ne è **fuori**, perché è interpolato («Utente 42 non
         trovato») e dentro l'impronta darebbe una issue per occorrenza. La
         conseguenza è che due occorrenze della stessa issue possono avere
         messaggi **diversi**, e chiamare questo «il messaggio dell'errore»
         manderebbe a cercare una costante che non c'è.

         ⚠️ E si dice **quale** campione: la colonna `errori.messaggio` si
         scrive una volta sola, alla nascita della issue (`Errore::create()` in
         `CatturaErrori::registra()`), e non viene mai aggiornata — è quindi
         quello della **prima** volta, non l'ultimo visto. Ogni occorrenza qui
         sotto porta il proprio, ed è là che si guarda per vedere come varia. --}}
    <div class="mt-6">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            Messaggio <span class="font-normal normal-case">— campione, non «il» messaggio</span>
        </h2>
        <p class="mt-1 text-xs text-neutral-500">
            L'impronta non include il messaggio, quindi occorrenze diverse della stessa issue possono averne
            di diversi. Questo è quello della <strong>prima volta</strong>; ogni occorrenza qui sotto porta il proprio.
        </p>
        <p class="mt-2 whitespace-pre-wrap break-words rounded-md bg-neutral-50 p-3 font-mono text-xs text-neutral-900">{{ $errore->messaggio }}</p>
    </div>

    <div class="mt-8">
        <h2 class="text-lg font-semibold text-neutral-900">Occorrenze conservate</h2>
        <p class="mt-1 text-sm text-neutral-600">
            Un <strong>campione</strong>, non l'elenco completo: al più
            {{ config('easylab.errori.contesti_per_errore') }} per errore, e non più di una ogni
            {{ config('easylab.errori.finestra_contesto_secondi') }} secondi.
        </p>
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($occorrenze as $occorrenza)
            <details wire:key="occorrenza-{{ $occorrenza->id }}"
                     @if ($loop->first && $occorrenze->onFirstPage()) open @endif
                     class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm">
                <summary class="cursor-pointer text-sm text-neutral-800">
                    <span class="font-medium tabular-nums">{{ $occorrenza->avvenuta_at?->format('d/m/Y H:i:s') ?? '—' }}</span>
                    {{-- `contesto` dice come leggere le colonne accanto: fuori
                         da `http`, `percorso` è il comando o la classe del job e
                         `codice_http` è null. --}}
                    <x-ui.badge variant="info" class="ml-2">{{ $occorrenza->contesto }}</x-ui.badge>
                    <span class="ml-2 font-mono text-xs text-neutral-600">{{ $occorrenza->metodo }} {{ $occorrenza->percorso }}</span>
                    @if ($occorrenza->codice_http)
                        <span class="ml-2 font-mono text-xs text-neutral-500">HTTP {{ $occorrenza->codice_http }}</span>
                    @endif
                </summary>

                <div class="mt-3 border-t border-neutral-200 pt-3">
                    <dl class="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr]">
                        <dt class="font-medium text-neutral-700">Messaggio</dt>
                        <dd class="whitespace-pre-wrap break-words font-mono text-neutral-900">{{ $occorrenza->messaggio }}</dd>

                        <dt class="font-medium text-neutral-700">Utente</dt>
                        <dd class="text-neutral-900">
                            @if ($occorrenza->user_id === null)
                                {{-- **Non** «Sistema»: sarebbe un'affermazione.
                                     Copre ospiti, console e coda.

                                     ⚠️ E il titolo è **condizionale**: `users` non
                                     ha soft delete, quindi cancellando un utente
                                     `user_id` va a NULL per la chiave esterna
                                     mentre `impersonato_da` — che è una colonna
                                     nuda — resta. Dire «nessun utente
                                     autenticato» accanto a un «per conto di»
                                     valorizzato sarebbe un'affermazione falsa,
                                     cioè il difetto che questo ramo evita
                                     rifiutando «Sistema». --}}
                                <span class="text-neutral-400"
                                      title="{{ $occorrenza->impersonato_da === null
                                          ? 'Nessun utente autenticato'
                                          : 'L\'utente non è più presente: resta solo chi lo stava impersonando' }}">—</span>
                            @else
                                {{ $utenti[$occorrenza->user_id] ?? '#'.$occorrenza->user_id }}
                            @endif
                        </dd>

                        @if ($occorrenza->impersonato_da)
                            {{-- ⚠️ **Questo id può non risolvere.**
                                 `impersonato_da` è una colonna nuda, senza FK —
                                 la scelta è del percorso caldo dentro il gestore
                                 delle eccezioni — e `users` **non ha soft
                                 delete**: l'utente può essere stato cancellato
                                 davvero. Si stampa allora il numero, che è meno
                                 di un nome ma è molto più di niente: dice che
                                 dietro quella sessione c'era qualcun altro. --}}
                            <dt class="font-medium text-warning-800">Per conto di</dt>
                            <dd class="text-warning-800">
                                @if (isset($utenti[$occorrenza->impersonato_da]))
                                    {{ $utenti[$occorrenza->impersonato_da] }}
                                @else
                                    #{{ $occorrenza->impersonato_da }} <span class="text-neutral-500">(utente non più presente)</span>
                                @endif
                            </dd>
                        @endif

                        <dt class="font-medium text-neutral-700">IP</dt>
                        <dd class="font-mono text-neutral-900">{{ $occorrenza->ip ?? '—' }}</dd>

                        <dt class="font-medium text-neutral-700">User agent</dt>
                        <dd class="break-words font-mono text-neutral-900">{{ $occorrenza->user_agent ?? '—' }}</dd>
                    </dl>

                    {{-- L'input della richiesta, **già ripulito in scrittura**:
                         `password`, `token`, `secret`, `code`, `recovery_code`,
                         `two_factor`, `signature`, `expires`, `_token` non
                         arrivano fin qui in chiaro (`App\Support\ChiaviSensibili`). --}}
                    @if (! empty($occorrenza->input))
                        <div class="mt-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Input</h3>
                            <dl class="mt-1 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr]">
                                @foreach ($occorrenza->input as $chiave => $valore)
                                    <dt class="font-mono font-medium text-neutral-700">{{ $chiave }}</dt>
                                    {{-- Un valore annidato si rende come JSON e
                                         non con `{{ }}` nudo, che su un array
                                         andrebbe in «Array to string conversion»
                                         — cioè romperebbe la pagina proprio sul
                                         dato che serve a capire l'errore. --}}
                                    <dd class="whitespace-pre-wrap break-words font-mono text-neutral-900">{{ is_scalar($valore) || $valore === null ? var_export($valore, true) : json_encode($valore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                                @endforeach
                            </dl>
                        </div>
                    @endif

                    <div class="mt-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Stack trace</h3>
                        {{-- ⚠️ **Senza argomenti di funzione**, e non per
                             troncamento: `CatturaErrori::traccia()` fa
                             `unset($frame['args'])` su ogni frame prima di
                             salvare. `getTrace()` li restituirebbe **integri** —
                             password, codici di recupero, oggetti vivi. --}}
                        <pre class="mt-1 overflow-x-auto rounded-md bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100">{{ $occorrenza->stack_trace }}</pre>
                    </div>
                </div>
            </details>
        @empty
            <x-ui.card class="text-center text-sm text-neutral-500">
                {{-- ⚠️ **Non «nessuna occorrenza»**: le occorrenze ci sono — le
                     conta la cifra qui sopra. A mancare è il **contesto**, e i
                     due fatti si confondono solo se li si dice con la stessa
                     parola. Succede quando la issue è nata prima del blocco 4,
                     o quando il campionamento non è ancora scattato. --}}
                Nessun contesto conservato per questo errore.
            </x-ui.card>
        @endforelse
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-neutral-500">
            @if ($occorrenze->total() > 0)
                {{ $occorrenze->firstItem() }}–{{ $occorrenze->lastItem() }} di {{ number_format($occorrenze->total(), 0, ',', '.') }} contesti conservati
            @endif
        </p>

        {{ $occorrenze->links() }}
    </div>

</div>

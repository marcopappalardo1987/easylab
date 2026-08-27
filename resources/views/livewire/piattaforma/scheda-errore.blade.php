{{--
    L'error tracker interno — la scheda di una issue.

    ⚠️ **Le uniche azioni della pagina sono i tre pulsanti di stato**
    (risolvi/ignora/riapri), e sono anche le uniche scritture dell'intero error
    tracker fuori dal gestore delle eccezioni. Tutto il resto è in sola lettura:
    il dettaglio di ogni occorrenza si apre con un `<details>` **nativo** —
    nessun round-trip, nessuna azione — e resta leggibile anche se JavaScript non
    parte. Una schermata che si consulta quando l'applicazione sta già andando
    male è il posto sbagliato per dipendere da altro codice.

    ⚠️ **Qui dentro ci sono dati personali** — stack trace, ip, user agent,
    input di richiesta — e la sanificazione è avvenuta **in scrittura**, a monte
    (blocco 4): ciò che si legge qui è già ciò che resta dopo la denylist. Non
    si aggiunge nessuna ripulitura di facciata, che darebbe l'impressione che il
    database sia più pulito di quanto è.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <a href="{{ route('piattaforma.errori') }}" class="text-sm text-brand hover:underline">
            <span aria-hidden="true">←</span> Torna agli errori
        </a>
    </div>

    <div class="mt-3">
        <h1 class="text-2xl font-bold tracking-tight text-ink">
            {{ class_basename($errore->classe) }}
        </h1>
        <p class="mt-1 font-mono text-xs text-ink-3">{{ $errore->classe }}</p>
        <p class="mt-1 font-mono text-sm text-ink-2">{{ $errore->file }}:{{ $errore->riga }}</p>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        @if ($errore->stato === 'risolto')
            <x-ui.badge variant="success">Risolto</x-ui.badge>
            <span class="text-xs text-ink-3">
                da {{ $errore->risoltoDa?->name ?? 'un utente non più presente' }}
                @if ($errore->risolto_at) il {{ $errore->risolto_at->format('d/m/Y H:i') }} @endif
            </span>
        @elseif ($errore->stato === 'ignorato')
            <x-ui.badge variant="neutral">Ignorato</x-ui.badge>
        @else
            <x-ui.badge variant="danger">Aperto</x-ui.badge>
            @if ($errore->riaperto_automaticamente_at)
                <span class="text-xs text-warn-soft-ink">
                    riaperto automaticamente il {{ $errore->riaperto_automaticamente_at->format('d/m/Y H:i') }}
                </span>
            @endif
        @endif
    </div>

    {{-- 🔴 **I tre gesti, e nessuna modale di conferma.**

         Si offrono **solo** i due che portano altrove: da «aperto» si risolve o
         si ignora, da «risolto» si riapre o si ignora, da «ignorato» si riapre o
         si risolve. Un pulsante che rimette la issue nello stato in cui già si
         trova scriverebbe una riga di audit che dice «da risolto a risolto» —
         rumore in un registro che deve restare leggibile.

         ⚠️ Niente conferma: il gesto è reversibile con un click da questa stessa
         pagina e ognuno lascia la propria riga nel registro. La modale
         dell'editor dei permessi esiste perché là il gesto ricade su tutti gli
         utenti di un ruolo; qui ricade su una riga di diagnostica.

         ⚠️ **`ignora` è marcato `secondary` e non `danger`**, per quanto sia il
         gesto più conseguente dei tre: non distrugge niente e si disfa con
         «Riapri». È «risolvi» a essere l'azione principale, perché è quella che
         si compie novantanove volte su cento.

         L'`aria-label` sul gruppo non è decorazione: è ciò da cui i test
         estraggono **questo** blocco, per poter poi asserire sul testo che
         l'utente legge davvero e non su un marcatore invisibile. --}}
    <div role="group" aria-label="Azioni sull'errore" class="mt-4 flex flex-wrap items-center gap-2">
        @if ($errore->stato !== 'risolto')
            <x-ui.button wire:click="risolvi" wire:target="risolvi" wire:loading.attr="disabled">
                Risolvi
            </x-ui.button>
        @endif

        @if ($errore->stato !== 'aperto')
            <x-ui.button variant="secondary" wire:click="riapri" wire:target="riapri" wire:loading.attr="disabled">
                Riapri
            </x-ui.button>
        @endif

        @if ($errore->stato !== 'ignorato')
            <x-ui.button variant="secondary" wire:click="ignora" wire:target="ignora" wire:loading.attr="disabled">
                Ignora
            </x-ui.button>
        @endif
    </div>

    {{-- Cosa vuol dire ciascuno dei tre, accanto ai pulsanti e non in una guida
         altrove: «risolto» e «ignorato» si somigliano finché non si scopre che
         **solo il primo si riapre da sé**, e scoprirlo dopo aver zittito un bug
         vero è tardi. --}}
    <p class="mt-2 max-w-3xl text-xs text-ink-2">
        <strong>Risolto</strong> vuol dire «credo di averlo sistemato»: una nuova occorrenza lo contraddice e
        riapre l'errore da sé, azzerando il conteggio dei contesti conservati per poter raccogliere la prova
        successiva al tentativo di correzione. <strong>Ignorato</strong> vuol dire «so che c'è e non me ne
        importa»: le occorrenze continuano a essere contate, ma l'errore <strong>non si riapre mai</strong> da
        solo. È l'unico modo di zittire qualcosa qui dentro.
    </p>

    {{-- 🔴 **Le due cifre nella stessa frase**, dallo stesso componente
         dell'elenco: qui pesa più che là, perché è questa la pagina in cui si
         scorrono le occorrenze conservate — e vederne dieci sotto un contatore
         che dice diecimila, senza la seconda cifra a spiegarlo, si legge come
         una perdita di dati.

         Il colore lo decide questo contenitore: `x-errori.cifre` eredita la
         tinta e non va toccato. --}}
    <p class="mt-4 text-sm text-ink-2">
        <x-errori.cifre :errore="$errore" />
    </p>
    <p class="mt-1 text-sm text-ink-2 tabular-nums">
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
        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-3">
            Messaggio <span class="font-normal normal-case">— campione, non «il» messaggio</span>
        </h2>
        <p class="mt-1 text-xs text-ink-3">
            L'impronta non include il messaggio, quindi occorrenze diverse della stessa issue possono averne
            di diversi. Questo è quello della <strong>prima volta</strong>; ogni occorrenza qui sotto porta il proprio.
        </p>
        {{-- ⚠️ `bg-surface-code`: superficie monospazio (DS §8.2), non un incasso
             qualunque — è la ragione per cui il token esiste. --}}
        <p class="mt-2 whitespace-pre-wrap break-words rounded-md bg-surface-code p-3 font-mono text-xs text-ink">{{ $errore->messaggio }}</p>
    </div>

    <div class="mt-8">
        <h2 class="text-lg font-semibold text-ink">Occorrenze conservate</h2>
        <p class="mt-1 text-sm text-ink-2">
            Un <strong>campione</strong>, non l'elenco completo: al più
            {{ config('easylab.errori.contesti_per_errore') }} per errore, e non più di una ogni
            {{ config('easylab.errori.finestra_contesto_secondi') }} secondi.
        </p>
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($occorrenze as $occorrenza)
            <details wire:key="occorrenza-{{ $occorrenza->id }}"
                     @if ($loop->first && $occorrenze->onFirstPage()) open @endif
                     class="rounded-lg border border-border bg-surface p-4 shadow-sm">
                <summary class="cursor-pointer text-sm text-ink">
                    <span class="font-medium tabular-nums">{{ $occorrenza->avvenuta_at?->format('d/m/Y H:i:s') ?? '—' }}</span>
                    {{-- `contesto` dice come leggere le colonne accanto: fuori
                         da `http`, `percorso` è il comando o la classe del job e
                         `codice_http` è null. --}}
                    <x-ui.badge variant="info" class="ml-2">{{ $occorrenza->contesto }}</x-ui.badge>
                    <span class="ml-2 font-mono text-xs text-ink-2">{{ $occorrenza->metodo }} {{ $occorrenza->percorso }}</span>
                    @if ($occorrenza->codice_http)
                        <span class="ml-2 font-mono text-xs text-ink-3">HTTP {{ $occorrenza->codice_http }}</span>
                    @endif
                </summary>

                <div class="mt-3 border-t border-border pt-3">
                    <dl class="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr]">
                        <dt class="font-medium text-ink-2">Messaggio</dt>
                        <dd class="whitespace-pre-wrap break-words font-mono text-ink">{{ $occorrenza->messaggio }}</dd>

                        <dt class="font-medium text-ink-2">Utente</dt>
                        <dd class="text-ink">
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
                                {{-- ⚠️ `text-neutral-400` era sotto AA per il testo (DS §2.2):
                                     questo trattino è un'informazione, non un separatore. --}}
                                <span class="text-ink-3"
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
                            <dt class="font-medium text-warn-soft-ink">Per conto di</dt>
                            <dd class="text-warn-soft-ink">
                                @if (isset($utenti[$occorrenza->impersonato_da]))
                                    {{ $utenti[$occorrenza->impersonato_da] }}
                                @else
                                    #{{ $occorrenza->impersonato_da }} <span class="text-ink-3">(utente non più presente)</span>
                                @endif
                            </dd>
                        @endif

                        <dt class="font-medium text-ink-2">IP</dt>
                        <dd class="font-mono text-ink">{{ $occorrenza->ip ?? '—' }}</dd>

                        <dt class="font-medium text-ink-2">User agent</dt>
                        <dd class="break-words font-mono text-ink">{{ $occorrenza->user_agent ?? '—' }}</dd>
                    </dl>

                    {{-- L'input della richiesta, **già ripulito in scrittura**:
                         `password`, `token`, `secret`, `code`, `recovery_code`,
                         `two_factor`, `signature`, `expires`, `_token` non
                         arrivano fin qui in chiaro (`App\Support\ChiaviSensibili`). --}}
                    @if (! empty($occorrenza->input))
                        <div class="mt-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-3">Input</h3>
                            <dl class="mt-1 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr]">
                                @foreach ($occorrenza->input as $chiave => $valore)
                                    <dt class="font-mono font-medium text-ink-2">{{ $chiave }}</dt>
                                    {{-- Un valore annidato si rende come JSON e
                                         non con `{{ }}` nudo, che su un array
                                         andrebbe in «Array to string conversion»
                                         — cioè romperebbe la pagina proprio sul
                                         dato che serve a capire l'errore. --}}
                                    <dd class="whitespace-pre-wrap break-words font-mono text-ink">{{ is_scalar($valore) || $valore === null ? var_export($valore, true) : json_encode($valore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                                @endforeach
                            </dl>
                        </div>
                    @endif

                    <div class="mt-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-3">Stack trace</h3>
                        {{-- ⚠️ **Senza argomenti di funzione**, e non per
                             troncamento: `CatturaErrori::traccia()` fa
                             `unset($frame['args'])` su ogni frame prima di
                             salvare. `getTrace()` li restituirebbe **integri** —
                             password, codici di recupero, oggetti vivi.

                             ⚠️ `bg-surface-code`, non più `bg-neutral-900` fisso:
                             era letteralmente il caso che ha fatto nascere il
                             token (DS §8.2, F0.3). Prima restava sempre scuro
                             anche in tema chiaro; ora segue il tema come ogni
                             altra superficie monospazio. --}}
                        <pre class="mt-1 overflow-x-auto rounded-md bg-surface-code p-3 text-xs leading-relaxed text-ink">{{ $occorrenza->stack_trace }}</pre>
                    </div>
                </div>
            </details>
        @empty
            <x-ui.card class="text-center text-sm text-ink-3">
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
        <p class="text-xs text-ink-3">
            @if ($occorrenze->total() > 0)
                {{ $occorrenze->firstItem() }}–{{ $occorrenze->lastItem() }} di {{ number_format($occorrenze->total(), 0, ',', '.') }} prove conservate in tutto
            @endif
        </p>

        {{ $occorrenze->links() }}
    </div>

</div>

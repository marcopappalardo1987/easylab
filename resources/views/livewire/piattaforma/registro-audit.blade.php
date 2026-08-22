<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Registro di audit</h1>
        <p class="mt-1 text-sm text-neutral-600">
            Chi ha fatto cosa, e quando, su tutta la piattaforma.
        </p>
    </div>

    {{-- ⚠️ Non è una nota di colore: è il limite di affidabilità del dato, e
         chi legge un registro deve saperlo prima di trarne conclusioni. Fino al
         22 Ago 2026 le righe scritte durante un'impersonazione nominano
         l'impersonato e non chi stava agendo davvero. Lo storico non si
         riscrive. --}}
    <x-ui.card class="mt-4 border-warning-500 bg-warning-100">
        <p class="text-sm text-warning-800">
            <span aria-hidden="true">⚠️</span>
            Le azioni compiute <strong>durante un'impersonazione</strong> portano il nome di chi è stato
            impersonato. Dal <strong>{{ App\Support\AuditLog::ATTRIBUZIONE_AFFIDABILE_DA }}</strong> le righe
            scritte <strong>dentro una richiesta</strong> dicono anche chi c'era dietro; per quelle precedenti
            quell'informazione non esiste.
        </p>
        <p class="mt-2 text-xs text-warning-800">
            Restano senza attribuzione le scritture <strong>differite</strong> — code, comandi di console,
            webhook — perché lì non c'è una sessione da interrogare.
        </p>
    </x-ui.card>

    {{-- Filtri. Ogni valore è in query string, quindi una vista filtrata si
         manda per link — che davanti a un registro è come si chiede a qualcun
         altro di guardare lo stesso fatto.

         ⚠️ **Nessuno di questi filtri legge `description`**, che è testo libero:
         il raggruppamento passa da `event` e da `subject_type`. «Cerca» tocca
         `description`, ma è una ricerca e si vede subito se non trova. --}}
    <div class="mt-8 flex flex-wrap items-end gap-3">
        <div class="min-w-56 flex-1">
            <label for="audit-cerca" class="block text-sm font-medium text-neutral-700">Cerca</label>
            <input id="audit-cerca" type="search" wire:model.live.debounce.300ms="cerca"
                   placeholder="Testo della descrizione"
                   class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
        </div>

        <div>
            <label for="audit-azione" class="block text-sm font-medium text-neutral-700">Azione</label>
            <select id="audit-azione" wire:model.live="azione"
                    class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">Tutte</option>
                {{-- La sentinella: le righe senza `event` sono gli atti — le
                     `activity()` esplicite, che nessuno scrittore marca. --}}
                <option value="{{ $this::ATTI }}">Atti</option>
                @foreach ($this->verbi() as $verbo => $etichettaVerbo)
                    <option value="{{ $verbo }}">{{ $etichettaVerbo }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="audit-soggetto" class="block text-sm font-medium text-neutral-700">Soggetto</label>
            <select id="audit-soggetto" wire:model.live="soggetto"
                    class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">Tutti</option>
                @foreach ($this->tipiSoggetto() as $tipo => $sostantivo)
                    <option value="{{ $tipo }}">{{ $sostantivo }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="audit-chi" class="block text-sm font-medium text-neutral-700">Chi</label>
            <input id="audit-chi" type="search" wire:model.live.debounce.300ms="chi"
                   placeholder="Nome o email"
                   class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
        </div>

        <div>
            <label for="audit-dal" class="block text-sm font-medium text-neutral-700">Dal</label>
            <input id="audit-dal" type="date" wire:model.live="dal"
                   class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
        </div>

        <div>
            <label for="audit-al" class="block text-sm font-medium text-neutral-700">Al</label>
            <input id="audit-al" type="date" wire:model.live="al"
                   class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
        </div>
    </div>

    {{-- La scorciatoia, e non un sesto filtro fra gli altri: raggiunge le righe
         che il filtro «Chi» non può raggiungere per costruzione — login falliti,
         console, webhook, coda. Senza, si trovano solo scorrendo. --}}
    <label class="mt-3 flex w-fit items-center gap-2 text-sm text-neutral-700">
        <input type="checkbox" wire:model.live="senzaUtente"
               class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500">
        Solo azioni senza utente autenticato
    </label>

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        {{-- La direzione è l'unico ordinamento offerto, e la freccia
                             legge quella **applicata**, non la property. --}}
                        <th scope="col" class="py-3 pl-4 pr-3"
                            aria-sort="{{ $direzione === 'asc' ? 'ascending' : 'descending' }}">
                            <button type="button" wire:click="inverti" class="uppercase tracking-wide hover:text-neutral-800">
                                Quando <span aria-hidden="true">{{ $direzione === 'asc' ? '▲' : '▼' }}</span>
                            </button>
                        </th>
                        <th scope="col" class="px-3 py-3" aria-sort="none">Chi</th>
                        <th scope="col" class="px-3 py-3" aria-sort="none">Azione</th>
                        <th scope="col" class="px-3 py-3" aria-sort="none">Soggetto</th>
                        <th scope="col" class="px-3 py-3 text-right"><span class="sr-only">Dettaglio</span><span aria-hidden="true">⌄</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-200">
                    @forelse ($righe as $riga)
                        @php
                            $etichetta = App\Support\Audit\SoggettiAudit::etichetta($riga);
                            // `?->` e non `->`: il logger inizializza sempre `properties`, ma una
                            // riga inserita fuori da lì — una migration di correzione, un fix a
                            // mano su una tabella append-only — ha la colonna a `null`. La vista
                            // di un registro non è il posto dove cadere per un dato imperfetto.
                            $perConto = $riga->properties?->get('impersonato_da');
                            $dettaglio = App\Support\Audit\DettaglioAttivita::di($riga);
                        @endphp

                        <tr wire:key="attivita-{{ $riga->id }}" class="align-top hover:bg-neutral-50">
                            <td class="whitespace-nowrap py-3 pl-4 pr-3 text-neutral-600 tabular-nums">
                                {{ $riga->created_at?->format('d/m/Y H:i:s') ?? '—' }}
                            </td>

                            <td class="px-3 py-3">
                                <div>
                                    @if ($riga->causer)
                                        <span class="font-medium text-neutral-900">{{ $riga->causer->name }}</span>
                                    @else
                                        {{-- **Non** «Sistema»: sarebbe un'affermazione. Copre
                                             login falliti, console, webhook e coda. --}}
                                        <span class="text-neutral-400" title="Azione senza utente autenticato">—</span>
                                    @endif

                                    @if ($perConto)
                                        <span class="mt-0.5 block text-xs text-warning-800">
                                            per conto di {{ $impersonatori[$perConto] ?? '#'.$perConto }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-3">
                                <div>
                                    <span class="text-neutral-900">{{ $riga->description }}</span>
                                    @if ($riga->event)
                                        <x-ui.badge variant="neutral" class="ml-1">{{ App\Support\AuditLog::VERBI[$riga->event] ?? $riga->event }}</x-ui.badge>
                                    @else
                                        {{-- `event` è NULL su tutte le righe esplicite: sono
                                             atti, non eventi CRUD, e la distinzione è la sola
                                             affidabile — `description` è testo libero. --}}
                                        <x-ui.badge variant="primary" class="ml-1">Atto</x-ui.badge>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-3 text-neutral-700">
                                @if ($etichetta->sostantivo === null)
                                    <span class="text-neutral-400" title="Questa azione non ricade su una riga">nessun soggetto</span>
                                @else
                                    {{ $etichetta->testo() }}
                                    @if ($etichetta->cestinato)
                                        <x-ui.badge variant="warning" class="ml-1">cestinato</x-ui.badge>
                                    @elseif ($etichetta->mancante)
                                        <x-ui.badge variant="warning" class="ml-1">non più presente</x-ui.badge>
                                    @endif
                                @endif
                            </td>

                            {{-- ⚠️ **Il pulsante c'è solo se c'è qualcosa da
                                 aprire.** Una freccia che apre una scatola vuota
                                 insegna a non fidarsi della freccia, e su un
                                 registro il lettore deve poter dedurre «niente
                                 dettaglio» dalla riga chiusa. La condizione è
                                 `vuoto()`, cioè la stessa che decide il
                                 contenuto: una sola fonte di verità fra qui e
                                 la classe, o le due divergono. --}}
                            <td class="px-3 py-3 text-right">
                                @if ($dettaglio->vuoto())
                                    <span class="text-neutral-300" title="Questa riga non porta dettagli" aria-hidden="true">·</span>
                                @else
                                    <button type="button" wire:click="espandi({{ $riga->id }})"
                                            class="text-neutral-500 hover:text-primary-700"
                                            aria-expanded="{{ $espanso === $riga->id ? 'true' : 'false' }}"
                                            aria-controls="dettaglio-{{ $riga->id }}">
                                        <span class="sr-only">Dettaglio della riga {{ $riga->id }}</span>
                                        <span aria-hidden="true">{{ $espanso === $riga->id ? '▾' : '▸' }}</span>
                                    </button>
                                @endif
                            </td>
                        </tr>

                        @if ($espanso === $riga->id && ! $dettaglio->vuoto())
                            <tr wire:key="dettaglio-{{ $riga->id }}" class="bg-neutral-50">
                                <td colspan="5" class="px-4 py-3" id="dettaglio-{{ $riga->id }}">
                                    {{-- Due sorgenti, due forme. `attribute_changes`
                                         è un diff e si legge come tale;
                                         `properties` sono fatti a sé. Fonderle
                                         darebbe «attributes → {…}», cioè JSON
                                         grezzo al posto dell'informazione. --}}
                                    @if ($dettaglio->cambi !== [])
                                        <table class="w-full text-xs">
                                            <thead class="text-left uppercase tracking-wide text-neutral-500">
                                                <tr>
                                                    <th scope="col" class="py-1 pr-3 font-medium">Campo</th>
                                                    <th scope="col" class="px-3 py-1 font-medium">Prima</th>
                                                    <th scope="col" class="px-3 py-1 font-medium">Dopo</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-neutral-200">
                                                @foreach ($dettaglio->cambi as $cambio)
                                                    <tr wire:key="cambio-{{ $riga->id }}-{{ $cambio['campo'] }}">
                                                        <td class="py-1 pr-3 font-medium text-neutral-700">{{ $cambio['campo'] }}</td>
                                                        <td class="whitespace-pre-wrap px-3 py-1 text-neutral-500">{{ $cambio['prima'] }}</td>
                                                        <td class="whitespace-pre-wrap px-3 py-1 text-neutral-900">{{ $cambio['dopo'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif

                                    @if ($dettaglio->proprieta !== [])
                                        <dl class="{{ $dettaglio->cambi === [] ? '' : 'mt-3 border-t border-neutral-200 pt-3' }} grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[max-content_1fr]">
                                            @foreach ($dettaglio->proprieta as $proprieta)
                                                <dt class="font-medium text-neutral-700">{{ $proprieta['chiave'] }}</dt>
                                                <dd class="whitespace-pre-wrap text-neutral-900">{{ $proprieta['valore'] }}</dd>
                                            @endforeach
                                        </dl>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            {{-- Due messaggi, perché sono due fatti diversi: un
                                 registro vuoto e una ricerca che non trova
                                 mandano a fare due cose opposte. «Nessuna riga
                                 con questi filtri» davanti a un registro
                                 genuinamente vuoto manda a cercare un filtro da
                                 togliere che non c'è. --}}
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-500">
                                @if ($filtriApplicati)
                                    Nessuna riga con questi filtri.
                                @else
                                    Nessuna riga nel registro.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-neutral-500">
            @if ($righe->total() > 0)
                {{ $righe->firstItem() }}–{{ $righe->lastItem() }} di {{ number_format($righe->total(), 0, ',', '.') }} righe
            @endif
        </p>

        {{ $righe->links() }}
    </div>

</div>

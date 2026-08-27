{{--
    Archivio documentale d'Ente (🔗 ADR-026/031, DS §8.1/§8.2).

    ⛔ **Solo token semantici, e mai la variante `dark:`**: questo file non è in
    `DA_MIGRARE` di `SuperficiTokenizzateGuardrailTest`, quindi è rosso al primo
    `bg-white` o `text-neutral-800`. Il tema si scambia sotto i token.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Documenti</h1>
            <p class="mt-1 text-sm text-ink-2">
                Manuali, certificati e report di tutte le macchine dell'Ente.
            </p>
        </div>

        @can('documenti.export_pdf')
            {{-- ⚠️ **Guardia di transizione, non decorazione.** La rotta
                 dell'indice PDF vive in `routes/web.php`, che in questa
                 lavorazione è tenuto da una mano sola: finché non è registrata,
                 un `route()` nudo qui dentro sarebbe un 500 su una pagina che
                 per il resto funziona. Quando la rotta c'è, questo `@if` è
                 sempre vero e si può togliere. --}}
            @if (Route::has('documenti.export-pdf'))
                {{-- I parametri sono quelli del **filtro corrente**: senza, il
                     foglio e lo schermo parlerebbero di due insiemi diversi
                     senza che nessuno dei due dica quale. --}}
                <x-ui.button variant="secondary" :href="route('documenti.export-pdf', $parametriExport)" target="_blank">
                    Indice PDF
                </x-ui.button>
            @endif
        @endcan
    </div>

    {{-- I filtri. Ogni valore è in query string, quindi una vista filtrata si
         manda per link — ed è anche ciò che l'indice PDF rilegge per esportare
         esattamente quello che si sta guardando. --}}
    <div class="mt-8 flex flex-wrap items-end gap-3">
        <div class="min-w-56 flex-1">
            <label for="doc-cerca" class="block text-sm font-medium text-ink-2">Cerca</label>
            <input id="doc-cerca" type="search" wire:model.live.debounce.300ms="cerca"
                   placeholder="Nome del file"
                   class="mt-1 block w-full rounded-md border-border-strong bg-surface text-sm shadow-sm focus:border-brand focus:ring-ring">
        </div>

        <div>
            <label for="doc-tipo" class="block text-sm font-medium text-ink-2">Tipo</label>
            <select id="doc-tipo" wire:model.live="tipo"
                    class="mt-1 block rounded-md border-border-strong bg-surface text-sm shadow-sm focus:border-brand focus:ring-ring">
                <option value="">Tutti</option>
                @foreach ($this->tipiDocumento() as $valore => $etichetta)
                    <option value="{{ $valore }}">{{ $etichetta }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="doc-macchina" class="block text-sm font-medium text-ink-2">Macchina</label>
            {{-- Solo le macchine che hanno almeno un documento visibile:
                 un'opzione che porta a zero risultati può solo frustrare. --}}
            <select id="doc-macchina" wire:model.live="strumentoId"
                    class="mt-1 block rounded-md border-border-strong bg-surface text-sm shadow-sm focus:border-brand focus:ring-ring">
                <option value="">Tutte</option>
                @foreach ($macchine as $macchina)
                    {{-- ⚠️ **L'etichetta si compone in PHP, non concatenando
                         direttive.** Blade non riconosce un `@if` preceduto da un
                         carattere di parola, quindi un `@endif@if` di fila si
                         compila male e lascia un `endif` orfano — la trappola già
                         pagata sul titolo del foglio PDF. E «(cestinata)» non è
                         un dettaglio: la macchina è cestinata ma i suoi documenti
                         sono ancora in elenco, quindi l'opzione deve dirlo o è
                         indistinguibile da una macchina viva. --}}
                    @php
                        $etichettaMacchina = $macchina->nome
                            .($macchina->trashed() ? ' (cestinata)' : '')
                            .($macchina->matricola ? ' · '.$macchina->matricola : '');
                    @endphp
                    <option value="{{ $macchina->id }}">{{ $etichettaMacchina }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="doc-dal" class="block text-sm font-medium text-ink-2">Dal</label>
            <input id="doc-dal" type="date" wire:model.live="dal"
                   class="mt-1 block rounded-md border-border-strong bg-surface text-sm shadow-sm focus:border-brand focus:ring-ring">
        </div>

        <div>
            <label for="doc-al" class="block text-sm font-medium text-ink-2">Al</label>
            <input id="doc-al" type="date" wire:model.live="al"
                   class="mt-1 block rounded-md border-border-strong bg-surface text-sm shadow-sm focus:border-brand focus:ring-ring">
        </div>
    </div>

    <x-ui.card class="mt-6 !p-0">
        <div class="overflow-x-auto">
            <table class="tabella-a-card w-full text-left text-sm">
                <thead class="border-b border-border text-xs tracking-wide text-ink-3 uppercase">
                    <tr>
                        {{-- La colonna esiste solo per chi attraversa più Enti (il
                             Tecnico, ADR-007/030): per tutti gli altri ripeterebbe
                             lo stesso nome su ogni riga. --}}
                        @if ($mostraEnte)
                            <th class="px-4 py-3 font-semibold">Ente</th>
                        @endif
                        <th class="px-4 py-3 font-semibold">Nome</th>
                        <th class="px-4 py-3 font-semibold">Tipo</th>
                        <th class="px-4 py-3 font-semibold">Macchina</th>
                        <th class="px-4 py-3 font-semibold">Allegato a</th>
                        <th class="px-4 py-3 font-semibold">Dimensione</th>
                        <th class="px-4 py-3 font-semibold">Caricato</th>
                        <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($documenti as $documento)
                        <tr wire:key="doc-{{ $documento->id }}">
                            @if ($mostraEnte)
                                {{-- ⚠️ Il nome arriva da una query **scopata**: un Ente
                                     raggiunto solo da una macchina assegnata e fuori
                                     portafoglio non è visibile come nodo (ADR-030,
                                     esposizione minima), e allora la riga lo dichiara
                                     invece di inventarne uno. --}}
                                <td data-etichetta="Ente" class="px-4 py-3 whitespace-nowrap text-ink-2">
                                    @if (isset($enti[$documento->tenant_id]))
                                        {{ $enti[$documento->tenant_id] }}
                                    @else
                                        <span class="text-ink-3">Ente non visibile</span>
                                    @endif
                                </td>
                            @endif
                            <td data-etichetta="Nome" class="px-4 py-3 font-medium text-ink">{{ $documento->nome }}</td>
                            <td data-etichetta="Tipo" class="px-4 py-3 text-ink-2">{{ $documento->tipo->label() }}</td>
                            <td data-etichetta="Macchina" class="px-4 py-3 text-ink-2">
                                {{-- ⚠️ **`strumento` può essere `null` su una riga
                                     VISIBILE**, e non è un caso di scuola:
                                     `AccessibleStrumenti` toglie di proposito il
                                     `SoftDeletingScope`, quindi i documenti di una
                                     macchina **cestinata** restano in elenco (come li
                                     vede l'Admin, che quel filtro non ce l'ha) mentre
                                     la relazione `belongsTo` passa dagli scope di
                                     `Strumento` e risolve a `null`. Un
                                     `->strumento->nome` diretto è un 500, e
                                     `route('strumenti.show', null)` è una
                                     `UrlGenerationException`. --}}
                                @if ($documento->strumento === null)
                                    <span class="text-ink-3">Macchina cestinata</span>
                                @else
                                    @can('strumenti.view')
                                        <a href="{{ route('strumenti.show', $documento->strumento) }}"
                                           class="text-brand hover:text-brand-hover">{{ $documento->strumento->nome }}</a>
                                    @else
                                        {{ $documento->strumento->nome }}
                                    @endcan
                                @endif
                            </td>
                            <td data-etichetta="Allegato a" class="px-4 py-3 text-ink-2">
                                {{-- ⚠️ `instanceof` e non `->documentabile->descrizione`:
                                     il morph passa dai global scope del model puntato, e
                                     `Intervento` ha `SoftDeletes` — un intervento
                                     cestinato lascia il suo documento in piedi con la
                                     relazione che risolve a `null`. Questo idioma, già
                                     scritto in `_documenti.blade.php`, è null-safe per
                                     costruzione; un accesso diretto è un 500 in pagina. --}}
                                @if ($documento->documentabile instanceof App\Models\Intervento)
                                    {{ $documento->documentabile->descrizione }}
                                @else
                                    <span class="text-ink-3">La macchina</span>
                                @endif
                            </td>
                            <td data-etichetta="Dimensione" class="px-4 py-3 whitespace-nowrap text-ink-2">{{ $documento->dimensioneLeggibile() }}</td>
                            <td data-etichetta="Caricato" class="px-4 py-3 whitespace-nowrap text-ink-2">
                                {{ $documento->created_at->format('d/m/Y') }}
                                @if ($documento->caricatoBy)
                                    <span class="text-ink-3">· {{ $documento->caricatoBy->name }}</span>
                                @endif
                            </td>
                            <td data-azioni class="px-4 py-3 text-right whitespace-nowrap max-md:flex max-md:flex-wrap max-md:gap-x-5 max-md:text-sm">
                                {{-- «Vedere» e «scaricare» sono due domande diverse, e il
                                     progetto tiene un caso sintetico apposta per
                                     congelarle distinte (`DocumentiTest`). --}}
                                @can('documenti.download')
                                    <a href="{{ route('documenti.download', $documento) }}"
                                       class="text-xs font-medium text-brand hover:text-brand-hover">Scarica</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            {{-- Tre messaggi, perché sono tre fatti diversi e mandano a
                                 fare tre cose diverse: «Nessun documento con questi
                                 filtri» davanti a un archivio genuinamente vuoto manda a
                                 cercare un filtro da togliere che non esiste. La
                                 condizione arriva dallo **stesso** oggetto che ha
                                 applicato i filtri, non da una lista riscritta qui. --}}
                            {{-- Il colspan segue la colonna condizionale, o la riga
                                 del vuoto non copre la tabella. --}}
                            <td colspan="{{ $mostraEnte ? 8 : 7 }}" class="px-4 py-10 text-center text-sm text-ink-3">
                                @if ($documenti->total() > 0)
                                    {{-- 🔴 **La pagina fuori intervallo viene per prima**,
                                         e non è un caso di scuola: un link condiviso o un
                                         segnalibro su `?page=2` sopravvive alla
                                         cancellazione dei documenti di quella pagina.
                                         `paginate()` torna zero righe con `total()`
                                         pieno, e senza questo ramo la pagina scriveva
                                         «Nessun documento caricato. Si allegano dalla
                                         scheda di una macchina» davanti a un archivio che
                                         ne contiene venticinque — cioè mandava a caricare
                                         il primo documento chi ne aveva già venticinque.
                                         Vale per l'elenco filtrato quanto per quello
                                         intero, quindi sta **sopra** entrambi. --}}
                                    Nessun documento a pagina {{ $documenti->currentPage() }}: in elenco ce ne sono {{ number_format($documenti->total(), 0, ',', '.') }}.
                                    <button type="button" wire:click="gotoPage(1)"
                                            class="font-medium text-brand hover:text-brand-hover">Torna alla prima pagina</button>
                                @elseif ($filtriApplicati)
                                    Nessun documento con questi filtri.
                                @else
                                    Nessun documento caricato. Si allegano dalla scheda di una macchina, nel tab Documenti.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-ink-3">
            {{-- ⚠️ La condizione è su `firstItem()`, non su `total()`: fuori
                 intervallo `firstItem()` e `lastItem()` valgono **`null`** con
                 `total()` pieno, e la riga stampava letteralmente «– di 25
                 documenti» — un intervallo senza numeri. Il totale però resta
                 un'informazione vera e va detto lo stesso. --}}
            @if ($documenti->firstItem() !== null)
                {{ $documenti->firstItem() }}–{{ $documenti->lastItem() }} di {{ number_format($documenti->total(), 0, ',', '.') }} documenti
            @elseif ($documenti->total() > 0)
                {{ number_format($documenti->total(), 0, ',', '.') }} documenti in tutto
            @endif
        </p>

        {{ $documenti->links() }}
    </div>

</div>

{{-- Scadenzario aggregato degli interventi aperti — 🔗 ADR-005/007/011/018/030,
     Wireframe §5, DS §4/§5/§7/§8.2.

     ⛔ Solo token **semantici** (`bg-surface`, `text-ink*`, `border-border`,
     `text-warn-soft-ink`, `text-bad-dot`, `ring-ring`): nessuna tonalità di
     scala e nessuna variante `dark:`, o `PaletteGuardrailTest` e
     `SuperficiTokenizzateGuardrailTest` diventano rossi — e in tema scuro si
     leggerebbe nero su nero senza che nulla dia errore. --}}
<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    <h1 class="text-2xl font-bold tracking-tight text-ink">Scadenzario</h1>
    {{-- Il perimetro si NOMINA sotto al titolo, come nella dashboard: un totale
         senza perimetro finisce citato in riunione per un altro. --}}
    <p class="mt-1 text-sm text-ink-2">
        Gli interventi ancora da fare su tutte le macchine che vedi.
    </p>

    {{-- Le tre partizioni: scaduto, entro la soglia di ADR-005, oltre. Sono le
         stesse due domande del digest (ADR-011) più il resto, e ognuna
         INDIRIZZA l'elenco — come i riquadri della dashboard. --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <button type="button" wire:click="filtra('scaduti')" class="rounded-lg text-left transition">
            <x-ui.stat-tile label="Scaduti" :valore="$contatori['scaduti']"
                            class="h-full hover:border-brand {{ $statoAttivo === 'scaduti' ? 'ring-2 ring-ring' : '' }}" />
        </button>

        <button type="button" wire:click="filtra('in_scadenza')" class="rounded-lg text-left transition">
            <x-ui.stat-tile label="In scadenza" :valore="$contatori['in_scadenza']"
                            :dettaglio="'entro '.$soglia.' giorni'"
                            class="h-full hover:border-brand {{ $statoAttivo === 'in_scadenza' ? 'ring-2 ring-ring' : '' }}" />
        </button>

        <button type="button" wire:click="filtra('oltre')" class="rounded-lg text-left transition">
            <x-ui.stat-tile label="Oltre" :valore="$contatori['oltre']"
                            :dettaglio="'oltre '.$soglia.' giorni'"
                            class="h-full hover:border-brand {{ $statoAttivo === 'oltre' ? 'ring-2 ring-ring' : '' }}" />
        </button>
    </div>

    <div class="mt-3">
        <button type="button" wire:click="filtra(null)"
            class="text-sm font-medium {{ $statoAttivo === null ? 'text-ink' : 'text-brand hover:text-brand-hover' }}">
            Tutti gli interventi aperti
        </button>
    </div>

    {{-- Filtri --}}
    <x-ui.card class="mt-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-56 flex-1">
                <x-ui.input name="search" wire:model.live.debounce.300ms="search"
                    placeholder="Cerca per macchina o descrizione…" />
            </div>

            {{-- Le etichette vengono da `TipoIntervento::label()`, unica fonte
                 (ADR-021): un `ucfirst($value)` renderebbe
                 «Manutenzione_full_risk». --}}
            <select wire:model.live="tipo" aria-label="Tipo di intervento"
                class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-64">
                <option value="">Tutti i tipi</option>
                @foreach ($tipi as $t)
                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                @endforeach
            </select>

            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-ink-2">
                {{-- ⚠️ `border` esplicito accanto al colore: senza, la preflight
                     di Tailwind v4 (`border: 0 solid` su `*`) rende il contorno
                     inerte e la casella resta senza bordo. --}}
                <input type="checkbox" wire:model.live="soloMiei"
                    class="rounded border border-border-strong text-brand focus:ring-ring">
                Solo i miei
            </label>

            <button type="button" wire:click="invertiOrdine"
                class="inline-flex items-center gap-1 rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink hover:bg-surface-sunken focus:ring-2 focus:ring-ring focus:outline-none">
                {{-- ⛔ `$direzione` e mai `$sortDir`: quest'ultimo è la property
                     pubblica, cioè il valore GREZZO della query string, e
                     Livewire la condivide con la vista dopo i dati di
                     `view()`. La freccia deve indicare l'ordine applicato. --}}
                <span aria-hidden="true">{{ $direzione === 'asc' ? '↑' : '↓' }}</span>
                {{ $direzione === 'asc' ? 'Prima le più vicine' : 'Prima le più lontane' }}
            </button>
        </div>
    </x-ui.card>

    {{-- Tabella. Su mobile diventa una lista di card per CSS
         (`tabella-a-card`), quindi ogni `<td>` espone la propria intestazione in
         `data-etichetta`, con lo STESSO testo del `<th>`. --}}
    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="tabella-a-card w-full text-left text-sm">
                <thead class="border-b border-border bg-surface-sunken text-xs tracking-wide text-ink-3 uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Scadenza</th>
                        <th class="px-4 py-3 font-semibold">Stato</th>
                        <th class="px-4 py-3 font-semibold">Macchina</th>
                        <th class="px-4 py-3 font-semibold">Ubicazione</th>
                        <th class="px-4 py-3 font-semibold">Tipo</th>
                        <th class="px-4 py-3 font-semibold">Descrizione</th>
                        <th class="px-4 py-3 font-semibold">Assegnatario</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @php
                        // ⚠️ **Questo è un RAGGRUPPAMENTO, non un filtro.**
                        // Filtrare in PHP dopo `paginate()` darebbe pagine
                        // incomplete e conteggi falsi (lezione del filtro
                        // `stato` di `ElencoStrumenti`); raggruppare no, perché
                        // l'ordine è TOTALE — data più id — quindi i mesi sono
                        // contigui anche fra una pagina e l'altra e nessuna riga
                        // viene tolta. È anche la «metà calendario» del nome:
                        // una griglia a caselle avrebbe richiesto di caricare un
                        // mese intero per volta, cioè un secondo modello di
                        // paginazione.
                        $meseCorrente = null;
                    @endphp
                    @forelse ($interventi as $intervento)
                        @php
                            $mese = $intervento->data_scadenza->format('Y-m');
                            $nuovoMese = $mese !== $meseCorrente;
                            $meseCorrente = $mese;
                            $scaduto = $intervento->isScaduto();
                        @endphp

                        @if ($nuovoMese)
                            <tr wire:key="mese-{{ $mese }}">
                                <td colspan="7" class="bg-surface-sunken px-4 py-2 text-xs font-semibold tracking-wide text-ink-3 uppercase">
                                    {{ $intervento->data_scadenza->translatedFormat('F Y') }}
                                </td>
                            </tr>
                        @endif

                        <tr wire:key="int-{{ $intervento->id }}">
                            {{-- «In ritardo» non è la tripletta del semaforo (DS §4):
                                 è un accento testuale sulla data, con lo stesso token
                                 di lettura usato dall'elenco strumenti. --}}
                            <td data-etichetta="Scadenza" class="px-4 py-3 whitespace-nowrap tabular-nums {{ $scaduto ? 'font-medium text-warn-soft-ink' : 'text-ink-2' }}">
                                {{ $intervento->data_scadenza->format('d/m/Y') }}
                            </td>
                            {{-- Colore + forma + etichetta, mai il solo colore (DS §4). --}}
                            <td data-etichetta="Stato" class="px-4 py-3">
                                @if ($scaduto)
                                    <x-ui.badge variant="danger"><span aria-hidden="true">✗</span> Scaduto</x-ui.badge>
                                @else
                                    <x-ui.badge variant="neutral"><span aria-hidden="true">◷</span> Pianificato</x-ui.badge>
                                @endif
                            </td>
                            {{-- Il nome è un link SOLO se la macchina è ancora
                                 leggibile: la scheda di una macchina cestinata
                                 risponde 404, perché il binding implicito
                                 `{strumento}` applica il soft delete, e un link
                                 che porta a un errore è peggio di nessun link.
                                 `base()` toglie già da questo elenco le righe di
                                 una macchina cestinata, quindi il ramo `@else`
                                 non si raggiunge dall'interfaccia: resta come
                                 difesa, perché è la vista a non doversi fidare
                                 di una relazione che può tornare null. --}}
                            <td data-etichetta="Macchina" class="px-4 py-3 font-medium text-ink">
                                @if ($intervento->strumento !== null)
                                    <a href="{{ route('strumenti.show', $intervento->strumento_id) }}" wire:navigate class="hover:text-brand">
                                        {{ $intervento->strumento->nome }}
                                    </a>
                                @else
                                    <span class="text-ink-3">Macchina non più disponibile</span>
                                @endif
                            </td>
                            <td data-etichetta="Ubicazione" class="px-4 py-3 text-ink-2">
                                {{ $intervento->strumento?->percorsoUbicazione($nodi) ?: '—' }}
                            </td>
                            <td data-etichetta="Tipo" class="px-4 py-3 text-ink-2">{{ $intervento->tipo->label() }}</td>
                            <td data-etichetta="Descrizione" class="px-4 py-3 text-ink">{{ $intervento->descrizione }}</td>
                            {{-- ⛔ `tecnicoLabel()` e mai `$intervento->tecnico->name`:
                                 `users` non ha il TenantScope, quindi il nome di un
                                 assegnatario di un altro Ente comparirebbe qui. Il
                                 metodo rende `—` fuori Ente e lascia visibili i Tecnici
                                 esterni (`tenant_id` null, ADR-007). --}}
                            <td data-etichetta="Assegnatario" class="px-4 py-3 text-ink-2">{{ $intervento->tecnicoLabel() }}</td>
                        </tr>
                    @empty
                        <tr>
                            {{-- Due messaggi, perché sono due fatti diversi: un elenco
                                 filtrato che si dichiara vuoto manda a cercare interventi
                                 che ci sono, e «nessun risultato per i filtri» senza filtri
                                 manda a togliere un filtro che non c'è. La condizione NON
                                 si riscrive qui: il componente espone un predicato solo,
                                 costruito con gli stessi confronti con cui applica i filtri. --}}
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-ink-3">
                                {{ $this->haFiltriAttivi() ? 'Nessun risultato per i filtri applicati.' : 'Nessun intervento aperto.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-ink-2">
            <label for="perPage">Righe per pagina</label>
            <select id="perPage" wire:model.live="perPage"
                class="rounded-md border border-border-strong bg-surface py-1.5 pr-8 pl-2 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                @foreach ($this->opzioniPerPage() as $opzione)
                    <option value="{{ $opzione }}">{{ $opzione }}</option>
                @endforeach
            </select>
            <span class="text-ink-3">
                {{ $interventi->firstItem() ?? 0 }}–{{ $interventi->lastItem() ?? 0 }} di {{ $interventi->total() }}
            </span>
        </div>

        <div class="flex-1">
            {{ $interventi->links() }}
        </div>
    </div>
</div>

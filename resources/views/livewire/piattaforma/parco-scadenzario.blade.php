{{-- Parco clienti — scheda «Scadenzario»: cosa scade su tutto il parco, di
     tutti i clienti nel perimetro (🔗 ADR-037; DS §4/§5/§7/§8.2).

     ⛔ Solo token **semantici** (`bg-surface`, `text-ink*`, `border-border`,
     `text-warn-soft-ink`): nessuna tonalità di scala e nessuna variante `dark:`,
     o `PaletteGuardrailTest` e `SuperficiTokenizzateGuardrailTest` diventano
     rossi — e in tema scuro si leggerebbe nero su nero senza che nulla dia
     errore.

     ⛔ Da qui si GUARDA e non si scrive: nessun form, nessun `wire:click` che
     modifichi qualcosa. La sola leva è «impersona», che porta la modifica dove
     lascia una riga di audit col contesto del cliente.

     ⚠️ I flag di riga si calcolano in un blocco php dedicato e il markup resta
     PIATTO: dei condizionali annidati dentro un paragrafo hanno già fatto
     sparire dalla vista compilata un blocco intero, in questo stesso giro.

     ⛔ E in questi commenti NON si scrive il nome della direttiva php col suo
     prefisso: Blade estrae i blocchi grezzi PRIMA di togliere i commenti, e un
     prefisso lasciato qui dentro fa aprire un blocco che si chiude al primo
     terminatore vero — inghiottendo tutto ciò che sta in mezzo, tabella
     compresa. Costato una pagina in 500 mentre si scriveva questa. --}}

<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
    <x-piattaforma.nav />
    <x-parco.nav />

    <h1 class="mt-6 text-2xl font-bold tracking-tight text-ink">Scadenzario di tutti i clienti</h1>

    {{-- Il perimetro si NOMINA sotto al titolo: un totale senza perimetro
         finisce citato in riunione per un altro. --}}
    <p class="mt-1 text-sm text-ink-2">
        Gli interventi ancora da fare sulle macchine dei clienti selezionati. Sola lettura:
        per intervenire si impersona il cliente, e il gesto resta registrato.
    </p>

    {{-- ─── Il perimetro: su QUALI clienti si sta guardando ─────────────── --}}

    <x-ui.card class="mt-6">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="perimetro-modo" class="block text-sm font-medium text-ink">Clienti</label>
                <select id="perimetro-modo" wire:model.live="modo"
                    class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-56">
                    <option value="tutti">Tutti i clienti</option>
                    <option value="piano">Per piano</option>
                    <option value="scelti">Scelti a mano</option>
                </select>
            </div>

            @if ($modo === 'piano')
                <div>
                    <label for="perimetro-piano" class="block text-sm font-medium text-ink">Piano</label>
                    <select id="perimetro-piano" wire:model.live="piano"
                        class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-56">
                        <option value="">Scegli un piano…</option>
                        @foreach ($piani as $codice)
                            <option value="{{ $codice }}">{{ App\Support\Piani::etichetta($codice) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($modo === 'scelti')
                <div>
                    <label for="perimetro-clienti" class="block text-sm font-medium text-ink">Quali clienti</label>
                    <select id="perimetro-clienti" wire:model.live="clientiScelti" multiple size="4"
                        class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-64">
                        @foreach ($selezionabili as $cliente)
                            <option value="{{ $cliente->id }}">{{ $cliente->ragione_sociale }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- ─── Le tre partizioni, cliccabili come i riquadri della dashboard ── --}}

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

    {{-- ─── Filtri dell'elenco (il perimetro sta sopra: è il suo confine) ── --}}

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

            <button type="button" wire:click="invertiOrdine"
                class="inline-flex items-center gap-1 rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink hover:bg-surface-sunken focus:ring-2 focus:ring-ring focus:outline-none">
                {{-- ⛔ `$direzione` e mai `$sortDir`: quest'ultimo è il valore
                     GREZZO della query string, e la freccia deve indicare
                     l'ordine applicato. --}}
                <span aria-hidden="true">{{ $direzione === 'asc' ? '↑' : '↓' }}</span>
                {{ $direzione === 'asc' ? 'Prima le più urgenti' : 'Prima le più lontane' }}
            </button>
        </div>
    </x-ui.card>

    {{-- ─── L'elenco ───────────────────────────────────────────────────────
         Cliente e sede sono le PRIME due colonne, e non è una scelta estetica:
         su una vista cross-cliente una riga senza intestazione è un intervento
         attribuito a chiunque, ed è la prima cosa che il committente ha chiesto.

         Su mobile la tabella diventa una lista di card per CSS
         (`tabella-a-card`), quindi ogni `<td>` espone la propria intestazione in
         `data-etichetta`, con lo STESSO testo del `<th>`. --}}

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="tabella-a-card w-full text-left text-sm">
                <thead class="border-b border-border bg-surface-sunken text-xs tracking-wide text-ink-3 uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Cliente</th>
                        <th class="px-4 py-3 font-semibold">Sede</th>
                        <th class="px-4 py-3 font-semibold">Macchina</th>
                        <th class="px-4 py-3 font-semibold">Tipo</th>
                        <th class="px-4 py-3 font-semibold">Descrizione</th>
                        <th class="px-4 py-3 font-semibold">Scadenza</th>
                        <th class="px-4 py-3 font-semibold">Quanto manca</th>
                        <th class="px-4 py-3 text-right font-semibold">Azioni</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    @forelse ($interventi as $intervento)
                        @php
                            // ⚠️ Tutti i flag della riga si calcolano QUI, e il
                            // markup resta piatto: `@if` annidati dentro un
                            // paragrafo hanno già fatto sparire dalla vista
                            // compilata un blocco intero.
                            $titolare = $titolari[$intervento->tenant_id] ?? ['cliente' => '', 'sede' => '', 'account_id' => null];
                            $macchina = $macchine[$intervento->strumento_id] ?? null;
                            $scaduto = $intervento->isScaduto();
                            $manca = \App\Support\Piattaforma\ScadenzarioParco::quantoManca($intervento->data_scadenza);
                            $accountId = $titolare['account_id'];
                            $suoi = $accountId === null ? collect() : ($candidati[$accountId] ?? collect());
                            $puoImpersonare = auth()->user()->can('utenti.impersonate');
                        @endphp

                        <tr wire:key="int-{{ $intervento->id }}">
                            {{-- ⚠️ Mai una cella vuota al posto del cliente: su
                                 questa vista «di chi è questa riga» non è un
                                 dettaglio, e un trattino muto si legge come un
                                 difetto invece che come un fatto. Il caso non è
                                 raggiungibile — le righe sono già filtrate sul
                                 perimetro — ma la vista non deve fidarsi di una
                                 mappa che potrebbe non contenere la chiave. --}}
                            <td data-etichetta="Cliente" class="px-4 py-3 font-medium text-ink">
                                {{ $titolare['cliente'] ?: 'Cliente non identificato' }}
                            </td>
                            <td data-etichetta="Sede" class="px-4 py-3 text-ink-2">
                                {{ $titolare['sede'] ?: '—' }}
                            </td>
                            <td data-etichetta="Macchina" class="px-4 py-3 text-ink">
                                {{ $macchina?->nome ?? 'Macchina non più disponibile' }}
                            </td>
                            <td data-etichetta="Tipo" class="px-4 py-3 text-ink-2">{{ $intervento->tipo->label() }}</td>
                            <td data-etichetta="Descrizione" class="px-4 py-3 text-ink">{{ $intervento->descrizione }}</td>

                            {{-- «In ritardo» non è la tripletta del semaforo (DS
                                 §4): è un accento testuale sulla data, con lo
                                 stesso token di lettura dell'elenco strumenti. --}}
                            <td data-etichetta="Scadenza"
                                class="px-4 py-3 whitespace-nowrap tabular-nums {{ $scaduto ? 'font-medium text-warn-soft-ink' : 'text-ink-2' }}">
                                {{ $intervento->data_scadenza->format('d/m/Y') }}
                            </td>

                            {{-- Colore + forma + etichetta, mai il solo colore (DS §4). --}}
                            <td data-etichetta="Quanto manca" class="px-4 py-3 whitespace-nowrap">
                                @if ($scaduto)
                                    <x-ui.badge variant="danger"><span aria-hidden="true">✗</span> {{ $manca }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="neutral"><span aria-hidden="true">◷</span> {{ $manca }}</x-ui.badge>
                                @endif
                            </td>

                            {{-- L'unica leva della pagina, ed è ciò che rende il
                                 confine rapido invece che fastidioso: si guarda
                                 da qui, si agisce impersonando. Un candidato →
                                 link diretto; più d'uno → si sceglie, perché «il
                                 primo» sarebbe una decisione presa
                                 dall'ordinamento di una query. --}}
                            <td data-etichetta="Azioni" class="px-4 py-3 text-right whitespace-nowrap">
                                @if (! $puoImpersonare)
                                    <span class="text-xs text-ink-3">—</span>
                                @elseif ($suoi->count() === 1)
                                    {{-- `<a href>` GET e non un'azione Livewire:
                                         `take()` sostituisce l'utente in
                                         sessione, e una risposta Livewire
                                         lascerebbe in pagina un componente
                                         montato per l'utente precedente, col suo
                                         scope e i suoi permessi già risolti. --}}
                                    <a href="{{ route('impersonate', $suoi->first()) }}"
                                       class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft"
                                       title="Impersona {{ $suoi->first()->name }}">
                                        <span aria-hidden="true">👁</span> Impersona
                                    </a>
                                @elseif ($suoi->count() > 1)
                                    <button type="button" wire:click="apriScelta({{ $accountId }})"
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft">
                                        <span aria-hidden="true">👁</span> Impersona ({{ $suoi->count() }})
                                    </button>
                                @else
                                    {{-- ⚠️ Si DICE, invece di lasciare una cella
                                         vuota: un'assenza muta fa chiedere se sia
                                         un difetto, ed è già successo su questa
                                         piattaforma. I casi sono tre: il cliente
                                         non ha membri, l'unico membro è chi sta
                                         guardando, oppure si sta già
                                         impersonando — e allora non si impersona
                                         ancora. --}}
                                    <span class="text-xs text-ink-3" title="Nessun membro impersonabile">Nessun membro impersonabile</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        @php
                            // Tre fatti diversi, tre messaggi. Un elenco filtrato
                            // che si dichiara vuoto manda a cercare interventi che
                            // ci sono; «nessun risultato per i filtri» senza filtri
                            // manda a togliere un filtro che non c'è; e un
                            // perimetro vuoto non è un parco senza scadenze.
                            // La condizione dei filtri NON si riscrive qui: il
                            // componente espone un predicato solo.
                            $vuoto = match (true) {
                                $perimetroVuoto => 'Nessun cliente selezionato: scegli chi vuoi guardare.',
                                $this->haFiltriAttivi() => 'Nessun risultato per i filtri applicati.',
                                default => 'Nessun intervento aperto sui clienti selezionati.',
                            };
                        @endphp

                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-sm text-ink-3">{{ $vuoto }}</td>
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

    {{-- La scelta del membro, gatata **tre volte** come nella cabina: la prima è
         `apriScelta()`/`updatingSceltaImpersonazione()`, che chiedono
         `utenti.impersonate` perché la property arriva dal browser; la seconda è
         `clienteScelto()`, che rilegge dalla porta e torna `null` senza quel
         permesso; questo `@can` è la terza e la più debole — da sola non
         reggerebbe nulla, ma toglie il markup a chi non deve vederlo. --}}
    @can('utenti.impersonate')
        @if ($sceltaImpersonazione !== null)
            @php
                // Riletti dalla porta e non pescati dalla pagina: filtrare o
                // cambiare pagina con la modale aperta lascerebbe a schermo un
                // guscio col titolo troncato e la lista vuota.
                $scelto = $this->clienteScelto();
                $suoiScelti = $this->candidatiScelti();
            @endphp

            @if ($scelto)
                <x-ui.modal :title="'Impersona un membro di '.$scelto->ragione_sociale" close="chiudiScelta">
                    <p class="text-sm text-ink-2">
                        Si entra come una persona: permessi, Ente attivo e visibilità saranno i suoi.
                        L'ingresso è registrato e resta un banner in cima a ogni pagina.
                    </p>

                    <ul class="mt-4 divide-y divide-border">
                        @foreach ($suoiScelti as $membro)
                            <li wire:key="candidato-{{ $membro->id }}" class="flex items-center justify-between gap-3 py-2">
                                <span>
                                    <span class="block text-sm font-medium text-ink">{{ $membro->name }}</span>
                                    <span class="block text-xs text-ink-3">{{ $membro->email }}</span>
                                </span>
                                <a href="{{ route('impersonate', $membro) }}"
                                   class="rounded-md bg-brand px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-hover">
                                    <span aria-hidden="true">👁</span> Entra
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.modal>
            @endif
        @endif
    @endcan
</div>

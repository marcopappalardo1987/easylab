{{-- Parco clienti — scheda **Strumenti** (🔗 ADR-037).

     ⛔ Nessuna azione che scriva, da nessuna parte in questa vista. Da qui si
     GUARDA oltre il proprio Ente; ogni modifica passa dall'impersonazione, che
     è per cliente e lascia una riga di audit col contesto.

     ⚠️ Il nome della macchina **non è un link**: `strumenti.show` risolve con
     route model binding, quindi passa dai global scope di `Strumento` e per un
     cliente che non è il proprio darebbe un 404. Un link che porta a un 404 è
     peggio di nessun link — e la strada per arrivarci c'è: si impersona.

     ⚠️ I flag di riga si calcolano in un blocco `@php … @endphp` e il markup
     resta piatto: in questo stesso giro tre `@if` annidati dentro un paragrafo
     hanno fatto sparire un blocco intero dalla vista compilata. --}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
    <x-piattaforma.nav />
    <x-parco.nav />

    <div class="mt-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-ink">{{ $titolo }}</h1>
            <p class="mt-1 text-sm text-ink-2">
                Il parco macchine dei clienti nel perimetro, con lo stato del semaforo.
                Sola lettura: per intervenire si entra come una persona del cliente.
            </p>
        </div>
    </div>

    {{-- ─── Filtri ─────────────────────────────────────────────────────────
         Il perimetro sta per primo e da solo: è il filtro che decide DI CHI
         sono le righe, e leggerlo dopo la ricerca farebbe credere che l'elenco
         sia già di tutti. --}}
    <x-ui.card class="mt-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="modo" class="block text-xs font-medium tracking-wide text-ink-3 uppercase">Clienti</label>
                <select id="modo" wire:model.live="modo"
                    class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-56">
                    <option value="tutti">Tutti i clienti</option>
                    <option value="piano">Per piano</option>
                    <option value="scelti">Quelli che scelgo</option>
                </select>
            </div>

            @if ($modo === 'piano')
                <div>
                    <label for="piano" class="block text-xs font-medium tracking-wide text-ink-3 uppercase">Piano</label>
                    <select id="piano" wire:model.live="piano"
                        class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-52">
                        <option value="">— scegli un piano —</option>
                        @foreach ($piani as $codice)
                            <option value="{{ $codice }}">{{ App\Support\Piani::etichetta($codice) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($modo === 'scelti')
                <div>
                    <label for="accountIds" class="block text-xs font-medium tracking-wide text-ink-3 uppercase">
                        Clienti scelti ({{ $clientiScelti }})
                    </label>
                    {{-- ⚠️ Selezione VUOTA = nessuna riga, mai «tutti»: è la
                         trappola che `Perimetro` esiste per chiudere, e la vista
                         lo dice a schermo invece di lasciarlo dedurre. --}}
                    <select id="accountIds" wire:model.live="accountIds" multiple size="4"
                        class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-72">
                        @foreach ($selezionabili as $cliente)
                            <option value="{{ $cliente->id }}">{{ $cliente->ragione_sociale }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="min-w-56 flex-1">
                <label for="search" class="block text-xs font-medium tracking-wide text-ink-3 uppercase">Cerca</label>
                <x-ui.input name="search" wire:model.live.debounce.300ms="search"
                    class="mt-1" placeholder="Nome, modello, matricola…" />
            </div>
        </div>

        {{-- ⚠️ Il filtro «solo arancioni» dell'elenco per-Ente qui NON c'è, ed è
             una scelta scritta: la sua forma SQL è scopata per tenant e
             classificherebbe come verdi le macchine altrui; rifarla non-scopata
             sarebbe una seconda copia della regola del semaforo (🔗 ADR-005).
             Meglio un filtro assente di uno che mente. --}}
        <p class="mt-3 text-xs text-ink-3">
            Lo stato del semaforo si legge su ogni riga, ma non è un filtro:
            la sua forma SQL vale dentro un solo Ente, e qui direbbe il falso.
        </p>
    </x-ui.card>

    {{-- ─── Tabella ────────────────────────────────────────────────────────
         🔴 Cliente e sede su OGNI riga: su una vista cross-cliente il rischio
         non è sbagliare macchina, è sbagliare cliente. --}}
    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-surface-sunken text-xs tracking-wide text-ink-3 uppercase">
                    <tr>
                        @php
                            $freccia = fn (string $col) => $ordinaPer === $col ? ($ordinaDir === 'asc' ? '↑' : '↓') : '';
                            $ordinabili = [
                                'cliente' => 'Cliente',
                                'sede' => 'Sede',
                                'nome' => 'Macchina',
                                'modello' => 'Modello',
                                'matricola' => 'Matricola',
                            ];
                        @endphp

                        <th class="px-3 py-3 font-semibold">Stato</th>

                        @foreach ($ordinabili as $col => $etichetta)
                            <th class="px-3 py-3 font-semibold">
                                <button type="button" wire:click="sort('{{ $col }}')"
                                    class="inline-flex items-center gap-1 text-left uppercase hover:text-ink-2">
                                    {{ $etichetta }} <span class="text-brand">{{ $freccia($col) }}</span>
                                </button>
                            </th>
                        @endforeach

                        {{-- Derivata: non ordinabile, e il perché sta nel
                             docblock del componente. --}}
                        <th class="px-3 py-3 font-semibold">Prossima scadenza</th>
                        <th class="px-3 py-3 text-right font-semibold">Azioni</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    @forelse ($strumenti as $macchina)
                        @php
                            $stato = $righe->semaforo($macchina);
                            $scadenza = $righe->scadenza($macchina, $vedeGaranzieRicambio);
                            $candidati = $candidatiPerAccount[$macchina->cliente_id] ?? collect();
                            $puoImpersonare = auth()->user()->can('utenti.impersonate');
                        @endphp

                        <tr wire:key="parco-str-{{ $macchina->id }}" class="hover:bg-surface-sunken">
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center gap-1">
                                    <x-ui.semaforo :stato="$stato" />
                                    <x-ui.semaforo-forzato :strumento="$macchina" />
                                </span>
                            </td>

                            <td class="px-3 py-3 font-medium text-ink">{{ filled($macchina->cliente_nome) ? $macchina->cliente_nome : '—' }}</td>
                            <td class="px-3 py-3 text-ink-2">{{ filled($macchina->sede_nome) ? $macchina->sede_nome : '—' }}</td>
                            <td class="px-3 py-3 text-ink">{{ $macchina->nome }}</td>
                            <td class="px-3 py-3 text-ink-2">{{ filled($macchina->modello) ? $macchina->modello : '—' }}</td>
                            <td class="px-3 py-3 text-ink-2">{{ filled($macchina->matricola) ? $macchina->matricola : '—' }}</td>

                            {{-- «In ritardo» non è la tripletta del semaforo (DS §4): è un
                                 accento testuale sulla colonna scadenza, e usa lo stesso
                                 token di lettura dell'arancione. --}}
                            <td class="px-3 py-3 whitespace-nowrap {{ $scadenza['inRitardo'] ? 'font-medium text-warn-soft-ink' : 'text-ink-2' }}">
                                {{ $scadenza['testo'] }}
                            </td>

                            <td class="px-3 py-3 text-right">
                                @if (! $puoImpersonare)
                                    {{-- Una cella vuota si legge come «manca qualcosa»; il
                                         trattino dice «niente da fare qui». --}}
                                    <span class="text-xs text-ink-3">—</span>
                                @elseif ($candidati->count() === 1)
                                    {{-- `<a href>` GET e non un'azione Livewire: `take()`
                                         sostituisce l'utente in sessione, e una risposta
                                         Livewire lascerebbe in pagina un componente montato
                                         per l'utente precedente, col suo scope. --}}
                                    <a href="{{ route('impersonate', $candidati->first()) }}"
                                       class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft"
                                       title="Impersona {{ $candidati->first()->name }}">
                                        <span aria-hidden="true">👁</span> Impersona
                                    </a>
                                @elseif ($candidati->count() > 1)
                                    <button type="button" wire:click="apriScelta({{ $macchina->cliente_id }})"
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft">
                                        <span aria-hidden="true">👁</span> Impersona ({{ $candidati->count() }})
                                    </button>
                                @else
                                    {{-- ⚠️ Un'assenza muta fa chiedere se sia un difetto: è
                                         già successo su questa piattaforma. Si dice perché
                                         non c'è nessuno da impersonare. --}}
                                    <span class="text-xs text-ink-3"
                                          title="Nessun membro impersonabile: il Developer non lo è mai, e te stesso nemmeno.">
                                        Nessun membro impersonabile
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            {{-- ⚠️ Fatti diversi, messaggi diversi — e il messaggio
                                 arriva dal componente perché deve conoscere il modo
                                 GREZZO, cioè quali filtri questa vista sta davvero
                                 disegnando: col modo «per piano» e nessun piano
                                 scelto il perimetro è vuoto ma il multi-select dei
                                 clienti non esiste a schermo, e mandare lì è mandare
                                 a cercare un difetto. --}}
                            <td colspan="8" class="px-3 py-10 text-center text-sm text-ink-3">{{ $messaggioVuoto }}</td>
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
                {{ $strumenti->firstItem() ?? 0 }}–{{ $strumenti->lastItem() ?? 0 }} di {{ $strumenti->total() }}
            </span>
        </div>

        <div class="flex-1">
            {{ $strumenti->links() }}
        </div>
    </div>

    {{-- ─── La scelta del membro ───────────────────────────────────────────
         Gatata tre volte, come nella cabina: `apriScelta()` e
         `updatingSceltaImpersonazione()` chiedono il permesso perché la
         property arriva dal browser; `clienteScelto()` rilegge dalla porta e
         torna `null` senza quel permesso; questo `@can` è la terza e la più
         debole — da sola non reggerebbe nulla, ma toglie il markup a chi non
         deve vederlo. --}}
    @can('utenti.impersonate')
        @if ($sceltaImpersonazione !== null)
            @php
                // Riletti dalla porta e non pescati dalla pagina: filtrare o
                // paginare con la modale aperta lascerebbe a schermo un guscio
                // col titolo troncato e la lista vuota.
                $scelto = $this->clienteScelto();
                $suoi = $this->candidatiScelti();
            @endphp

            @if ($scelto)
                <x-ui.modal :title="'Impersona un membro di '.$scelto->ragione_sociale" close="chiudiScelta">
                    <p class="text-sm text-ink-2">
                        Si entra come una persona: permessi, Ente attivo e visibilità saranno i suoi.
                        L'ingresso è registrato e resta un banner in cima a ogni pagina.
                    </p>

                    <ul class="mt-4 divide-y divide-border">
                        @foreach ($suoi as $membro)
                            <li wire:key="parco-candidato-{{ $membro->id }}" class="flex items-center justify-between gap-3 py-2">
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

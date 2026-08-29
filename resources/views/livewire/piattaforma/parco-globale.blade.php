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
                    {{-- ⛔ Nessuna `<option value="scelti">`: quel modo non ha
                         più un controllo. Il componente normalizza a
                         `preferiti` proprio perché una `<select>` legata a un
                         valore senza `<option>` evidenzia la PRIMA voce — cioè
                         direbbe «Tutti i clienti» sopra una tabella che tutti i
                         clienti non li mostra. --}}
                    <option value="tutti">Tutti i clienti</option>
                    <option value="piano">Per piano</option>
                    <option value="preferiti">★ I miei preferiti</option>
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

            {{-- ★ I preferiti sono in SOLA LETTURA, e non per pigrizia: si
                 scelgono altrove — con la stella nell'elenco Clienti della
                 cabina — perché sono una preferenza durevole della persona e
                 non un filtro da ricomporre a ogni visita. Qui si dice quali
                 sono, quanti sono e dove si cambiano; un controllo che li
                 modificasse da questa pagina rimetterebbe in piedi, sotto un
                 nome nuovo, la `<select multiple>` che la ★ sostituisce.

                 ⚠️ Insieme VUOTO = nessuna riga, mai «tutti»: è la trappola che
                 `Perimetro` esiste per chiudere, e il messaggio dell'elenco lo
                 dice a schermo invece di lasciarlo dedurre. --}}
            {{-- ⚠️ `w-full` — cioè una RIGA propria dentro la barra — e non una
                 colonna accanto agli altri controlli, per due ragioni misurate:
                 (a) i nomi sono lunghi e sono N, e in una colonna stretta sei
                 preferiti diventavano sei righe incolonnate con mezza barra
                 vuota accanto; (b) la barra è `items-end`, quindi un riquadro
                 più alto dei suoi vicini li fa **scendere** — la `select`
                 «Clienti» finiva a metà altezza, staccata dalla propria
                 etichetta. Su una riga sua non tira giù nessuno. Un `flex-1` non
                 basta: qui i controlli in barra sono quattro e il riquadro
                 ricadeva sul proprio `min-w`. La **stessa** forma sulle altre
                 due schede: un filtro che si presenta in tre modi diversi è, per
                 chi guarda, tre filtri. --}}
            @if ($modo === 'preferiti')
                <div class="w-full">
                    <p class="block text-xs font-medium tracking-wide text-ink-3 uppercase">
                        I miei preferiti ({{ $preferiti->count() }})
                    </p>
                    <div class="mt-1 rounded-md border border-border-strong bg-surface-sunken px-3 py-2 text-sm text-ink">
                        @if ($preferiti->isEmpty())
                            <p class="text-ink-3">Nessuno, per ora.</p>
                        @else
                            <p class="leading-relaxed">{{ $preferiti->pluck('ragione_sociale')->implode(', ') }}</p>
                        @endif
                        {{-- L'ancora porta alla barra dei filtri dell'elenco
                             Clienti: senza, il link atterra in cima alla cabina
                             e la tabella con le ★ resta sotto i grafici. --}}
                        <a href="{{ route('piattaforma.index') }}#elenco-clienti"
                            class="mt-1 inline-block text-xs font-medium text-brand hover:underline">
                            Gestisci i preferiti nell'elenco Clienti
                        </a>
                    </div>
                </div>
            @endif

            {{-- Filtro semaforo (🔗 ADR-005): stato EFFETTIVO, cioè forzato se
                 c'è. Stesse etichette dell'elenco per-Ente: due schermi che
                 chiamano lo stesso stato con due nomi diversi sono due stati,
                 per chi legge. --}}
            <div>
                <label for="stato" class="block text-xs font-medium tracking-wide text-ink-3 uppercase">Stato</label>
                <select id="stato" wire:model.live="stato"
                    class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-52">
                    <option value="">Tutti gli stati</option>
                    <option value="arancione">◐ Azione richiesta</option>
                    <option value="verde">● In regola</option>
                    <option value="rosso">■ Non idoneo</option>
                </select>
            </div>

            <div class="min-w-56 flex-1">
                <label for="search" class="block text-xs font-medium tracking-wide text-ink-3 uppercase">Cerca</label>
                <x-ui.input name="search" wire:model.live.debounce.300ms="search"
                    class="mt-1" placeholder="Nome, modello, matricola…" />
            </div>

            {{-- Obsolescenza (🔗 ADR-014): asse indipendente dal semaforo, e la
                 soglia è quella di CIASCUNA sede — qui più che altrove, perché
                 i clienti in elenco l'hanno scelta ognuno per sé.

                 ⚠️ `border` esplicito accanto al colore: senza, la preflight di
                 Tailwind v4 (`border: 0 solid` su `*`) rende il contorno inerte
                 e il quadratino non ha bordo. --}}
            <label class="flex items-center gap-2 py-2.5 text-sm whitespace-nowrap text-ink-2">
                <input type="checkbox" wire:model.live="soloObsoleti"
                    class="rounded border border-border-strong text-brand focus:ring-ring">
                ⏳ Solo obsoleti
            </label>
        </div>
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
                            // ⚠️ `$statoRiga` e non `$stato`: `$stato` è la property
                            // pubblica che porta il FILTRO semaforo, e Livewire la
                            // passa alla vista. Assegnarla qui la sostituiva, dal primo
                            // giro di loop in poi, con lo `StatoSemaforo` dell'ultima
                            // riga disegnata — invisibile oggi, perché nulla sotto la
                            // tabella la rilegge, e pronta a mentire alla prima striscia
                            // dei filtri attivi messa in fondo alla pagina. Un test
                            // strutturale lo vieta per tutte le property del componente.
                            $statoRiga = $righe->semaforo($macchina);
                            $obsoleta = $righe->obsoleta($macchina);
                            $sogliaRiga = $righe->soglia($macchina);
                            $scadenza = $righe->scadenza($macchina, $vedeGaranzieRicambio);
                            $candidati = $candidatiPerAccount[$macchina->cliente_id] ?? collect();
                            $puoImpersonare = auth()->user()->can('utenti.impersonate');

                            // 🔴 Si entra e si ATTERRA SULLA MACCHINA della riga,
                            // non in dashboard: il tasto serve a intervenire in
                            // fretta, e la rotta del pacchetto rimanda a una
                            // destinazione fissa — bisognava ritrovare a mano la
                            // macchina appena vista in elenco (Marco, 29 Ago 2026).
                            // Le guardie sono le stesse del pacchetto più il
                            // permesso del parco, e stanno nel controller.
                            $sullaMacchina = fn ($membro) => route('piattaforma.parco.impersona', [
                                'utente' => $membro->id,
                                'strumento' => $macchina->id,
                            ]);
                        @endphp

                        <tr wire:key="parco-str-{{ $macchina->id }}" class="hover:bg-surface-sunken">
                            <td class="px-3 py-3">
                                <span class="inline-flex flex-wrap items-center gap-1">
                                    <x-ui.semaforo :stato="$statoRiga" />
                                    <x-ui.semaforo-forzato :strumento="$macchina" />
                                    {{-- ⏳ Obsolescenza (🔗 ADR-014, DS §4): asse
                                         SEPARATO, convive col pallino invece di
                                         sostituirlo. Markup inline e non
                                         `<x-ui.obsoleto>`: quel componente chiede la
                                         soglia al model, cioè a una relazione scopata
                                         che qui risolve a NULL e ricade su 10 — direbbe
                                         «oltre la soglia di 10 anni» sulle sedi che ne
                                         hanno un'altra, contraddicendo il filtro sulla
                                         riga accanto. La soglia arriva dalla join. --}}
                                    @if ($obsoleta)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-obs-soft px-2 py-0.5 text-xs font-medium text-obs-soft-ink"
                                              title="Installato il {{ $macchina->data_installazione->format('d/m/Y') }} — oltre la soglia di {{ $sogliaRiga }} anni">
                                            <span aria-hidden="true">⏳</span> Obsoleto
                                        </span>
                                    @endif
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
                                    <a href="{{ $sullaMacchina($candidati->first()) }}"
                                       class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft"
                                       title="Entra come {{ $candidati->first()->name }} sulla scheda di {{ $macchina->nome }}">
                                        <span aria-hidden="true">👁</span> Impersona
                                    </a>
                                @elseif ($candidati->count() > 1)
                                    <button type="button" wire:click="apriSceltaSuMacchina({{ $macchina->cliente_id }}, {{ $macchina->id }})"
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
                                 scelto il perimetro è vuoto ma la tendina dei piani
                                 è l'unico controllo a schermo, e mandare altrove è
                                 mandare a cercare un difetto. Senza preferiti il
                                 rimando esce del tutto da questa pagina — la ★ sta
                                 nell'elenco Clienti — e il messaggio lo dice. --}}
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

                // La modale si apre DA una riga, quindi conosce la macchina e
                // atterra lì come il tasto singolo. Il ramo `null` non è teorico:
                // `apriScelta()` resta chiamabile senza nominarne una — da un
                // test, o dalla property spinta dal browser — e in quel caso si
                // torna alla destinazione fissa del pacchetto invece di inventare
                // una macchina.
                $versoIlMembro = fn ($membro) => $macchinaScelta === null
                    ? route('impersonate', $membro)
                    : route('piattaforma.parco.impersona', ['utente' => $membro->id, 'strumento' => $macchinaScelta]);
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
                                <a href="{{ $versoIlMembro($membro) }}"
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

@php
    use App\Support\Piani;

    [$ordinatoPer, $direzione] = $this->ordinamentoEffettivo();
@endphp

<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Piattaforma</h1>
        <p class="mt-1 text-sm text-neutral-600">
            I clienti di EasyLab, le loro sedi e lo stato dei contratti.
        </p>
    </div>

    {{-- I quattro numeri. Ognuno porta il proprio contesto sotto: un totale
         senza «di cui» è la cifra che poi viene citata da sola.

         ⚠️ Sono **totali di piattaforma e non seguono i filtri** — filtrare su
         «Attivi» con tutti bloccati mostrerebbe «Clienti 37» sopra un «Nessun
         cliente con questi filtri». È una scelta (i KPI sono la fotografia
         dell'azienda, non della ricerca in corso), quindi va detta invece che
         lasciata dedurre da chi legge due numeri in disaccordo. --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

        <x-ui.stat-tile
            label="Ricavo mensile"
            :valore="number_format($riepilogo->mrrEuro(), 0, ',', '.').' €'"
            :dettaglio="$riepilogo->mrrBloccatoCent > 0
                ? 'a listino · '.number_format($riepilogo->mrrBloccatoEuro(), 0, ',', '.').' € fermi per insoluto'
                : 'a listino'" />

        <x-ui.stat-tile
            label="Clienti"
            :valore="$riepilogo->clienti"
            :dettaglio="$riepilogo->dettaglioClienti()
                .($riepilogo->clientiBloccati > 0 ? ' · '.$riepilogo->clientiBloccati.' 🔒' : '')" />

        <x-ui.stat-tile
            label="Sedi"
            :valore="$riepilogo->sedi"
            dettaglio="Enti dei clienti" />

        <x-ui.stat-tile
            label="Strumenti"
            :valore="$riepilogo->strumenti"
            dettaglio="Macchine di tutti i clienti" />

    </div>

    <p class="mt-2 text-xs text-neutral-500">
        I quattro numeri sono totali di piattaforma: non cambiano con i filtri qui sotto.
    </p>

    {{-- Un piano fuori catalogo non fa esplodere la pagina, ma non si nasconde
         nemmeno: chi legge deve poterlo riparare, e questa è l'unica schermata
         da cui si ripara. Il link al filtro è parte della riparazione — un
         avviso che segnala un problema senza dare la strada per raggiungerlo,
         su trecento clienti, è peggio che tacere. --}}
    @if ($riepilogo->pianiSconosciuti > 0)
        <x-ui.card class="mt-4 border-warning-500 bg-warning-100">
            <p class="text-sm text-warning-800">
                <span aria-hidden="true">⚠️</span>
                {{ $riepilogo->pianiSconosciuti }}
                {{ $riepilogo->pianiSconosciuti === 1 ? 'account ha un piano' : 'account hanno un piano' }}
                che non è più a catalogo: {{ $riepilogo->pianiSconosciuti === 1 ? 'vale' : 'valgono' }}
                0 € nel ricavo qui sopra.
                <button type="button" wire:click="$set('piano', '{{ $this::FUORI_CATALOGO }}')"
                        class="font-medium underline hover:text-warning-900">Mostra{{ $riepilogo->pianiSconosciuti === 1 ? 'lo' : 'li' }}</button>
            </p>
        </x-ui.card>
    @endif

    {{-- Filtri. Ogni valore è in query string, quindi una vista filtrata si
         manda a qualcuno per link — che è come si chiede aiuto su un cliente. --}}
    <div class="mt-8 flex flex-wrap items-end gap-3">
        <div class="min-w-56 flex-1">
            <label for="cerca-cliente" class="block text-sm font-medium text-neutral-700">Cerca</label>
            <input id="cerca-cliente" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Ragione sociale, P.IVA o nome della sede"
                   class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
        </div>

        <div>
            <label for="filtro-piano" class="block text-sm font-medium text-neutral-700">Piano</label>
            <select id="filtro-piano" wire:model.live="piano"
                    class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">Tutti</option>
                @foreach (Piani::codici() as $codice)
                    <option value="{{ $codice }}">{{ Piani::etichetta($codice) }}</option>
                @endforeach
                <option value="{{ $this::FUORI_CATALOGO }}">Fuori catalogo</option>
            </select>
        </div>

        <div>
            <label for="filtro-stato" class="block text-sm font-medium text-neutral-700">Stato</label>
            <select id="filtro-stato" wire:model.live="stato"
                    class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">Tutti</option>
                <option value="attivo">Attivi</option>
                {{-- Due voci e non una: fondere le sorgenti nel filtro rifà in
                     ricerca il difetto che ADR-013 evita nei dati. --}}
                <option value="bloccato_manuale">Bloccati a mano</option>
                <option value="bloccato_stripe">Bloccati da Stripe</option>
            </select>
        </div>

        <div>
            <label for="per-pagina" class="block text-sm font-medium text-neutral-700">Per pagina</label>
            <select id="per-pagina" wire:model.live="perPage"
                    class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                @foreach ($this->opzioniPerPage() as $taglia)
                    <option value="{{ $taglia }}">{{ $taglia }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ⚠️ **Deviazione dichiarata dalla checklist §7 del Design System**, che
         vuole `tabella-a-card` con i `data-etichetta` su ogni vista nuova: qui
         la tabella resta a scorrimento orizzontale sotto i 640px. La ragione è
         che questa pagina si guarda da scrivania — è la cabina di regia di chi
         amministra la piattaforma, non una vista da campo — e le righe hanno
         una seconda riga espandibile che in modalità card non ha una forma
         ovvia. Precedente nella stessa direzione: `elenco-strumenti`. Se un
         giorno si volesse chiudere, i `<td>` hanno già **un solo figlio
         diretto** ciascuno, che è la condizione che la checklist pone. --}}
    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                {{-- La freccia legge l'ordinamento **effettivo** e non le property:
                     `sortBy` può valere `password` mentre la query ha usato il
                     fallback, e una freccia che indica una colonna diversa da
                     quella applicata è una bugia piccola e quindi credibile. --}}
                <thead class="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        @foreach ([
                            'ragione_sociale' => ['Cliente', 'py-3 pl-4 pr-3'],
                            'piano' => ['Piano', 'px-3 py-3'],
                        ] as $colonna => [$etichetta, $classi])
                            <th scope="col" class="{{ $classi }}"
                                aria-sort="{{ $ordinatoPer === $colonna ? ($direzione === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <button type="button" wire:click="ordina('{{ $colonna }}')"
                                        class="uppercase tracking-wide hover:text-neutral-800">
                                    {{ $etichetta }}
                                    @if ($ordinatoPer === $colonna)
                                        <span aria-hidden="true">{{ $direzione === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach

                        <th scope="col" class="px-3 py-3">Stato</th>
                        <th scope="col" class="px-3 py-3 text-right">Sedi</th>
                        <th scope="col" class="px-3 py-3 text-right">Strumenti</th>

                        <th scope="col" class="px-3 py-3"
                            aria-sort="{{ $ordinatoPer === 'created_at' ? ($direzione === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <button type="button" wire:click="ordina('created_at')"
                                    class="uppercase tracking-wide hover:text-neutral-800">
                                Cliente dal
                                @if ($ordinatoPer === 'created_at')
                                    <span aria-hidden="true">{{ $direzione === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </button>
                        </th>

                        <th scope="col" class="py-3 pl-3 pr-4"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-200">
                    @forelse ($clienti as $cliente)
                        @php
                            $sue = $sediPerAccount[$cliente->id] ?? collect();
                            $aCatalogo = Piani::esiste($cliente->piano);
                            $max = $aCatalogo ? Piani::maxEnti($cliente->piano) : null;
                        @endphp

                        <tr wire:key="cliente-{{ $cliente->id }}" class="align-top hover:bg-neutral-50">
                            <td class="py-3 pl-4 pr-3">
                                {{-- Un solo figlio diretto: la checklist §7 del Design
                                     System avverte che in modalità card i figli del
                                     `<td>` finirebbero affiancati. --}}
                                <div>
                                    <button type="button" wire:click="espandi({{ $cliente->id }})"
                                            class="text-left font-medium text-neutral-900 hover:text-primary-700"
                                            aria-expanded="{{ $espanso === $cliente->id ? 'true' : 'false' }}"
                                            aria-controls="sedi-{{ $cliente->id }}">
                                        <span aria-hidden="true">{{ $espanso === $cliente->id ? '▾' : '▸' }}</span>
                                        {{ $cliente->ragione_sociale }}
                                    </button>
                                    @if ($cliente->partita_iva)
                                        <p class="mt-0.5 text-xs text-neutral-500">P.IVA {{ $cliente->partita_iva }}</p>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-3">
                                @if ($aCatalogo)
                                    <x-ui.badge :variant="$cliente->piano === 'free' ? 'neutral' : 'primary'">
                                        {{ Piani::etichetta($cliente->piano) }}
                                    </x-ui.badge>
                                @else
                                    {{-- Un piano dismesso non si nasconde: questa è la schermata da cui si ripara. --}}
                                    <x-ui.badge variant="warning">{{ $cliente->piano }} — fuori catalogo</x-ui.badge>
                                @endif
                            </td>

                            {{-- Due badge distinti e mai uno solo: `is_locked` significa
                                 «almeno una sorgente accesa», e fonderle in UI ricrea il
                                 difetto che la separazione esiste per impedire (ADR-013). --}}
                            <td class="space-y-1 px-3 py-3">
                                @if ($cliente->locked_at)
                                    <x-ui.badge variant="danger">🔒 A mano</x-ui.badge>
                                @endif
                                @if ($cliente->stripe_locked_at)
                                    <x-ui.badge variant="danger">🔒 Insoluto</x-ui.badge>
                                @endif
                                @unless ($cliente->is_locked)
                                    <x-ui.badge variant="success">Attivo</x-ui.badge>
                                @endunless
                            </td>

                            {{-- ⚠️ `?` e non `∞` sul piano fuori catalogo: `maxEnti()`
                                 restituisce `null` per «illimitato», e il ternario lo
                                 produce anche per «non lo so». Fonderli farebbe leggere
                                 il caso corrotto come il più permissivo dei due —
                                 proprio sulla riga che la pagina invita a riparare. --}}
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{ $sue->count() }}<span class="text-neutral-400"> / {{ $aCatalogo ? ($max ?? '∞') : '?' }}</span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                {{ number_format($strumentiPerAccount[$cliente->id] ?? 0, 0, ',', '.') }}
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-neutral-600 tabular-nums">
                                {{ $cliente->created_at?->format('d/m/Y') }}
                            </td>

                            <td class="py-3 pl-3 pr-4 text-right">
                                {{-- Si impersona una **persona**, non un contratto: l'Account
                                     non ha sessione né permessi. Un candidato → link diretto;
                                     più d'uno → si sceglie, perché «il primo» sarebbe una
                                     decisione presa dall'ordinamento di una query. --}}
                                @php
                                    $candidati = $candidatiPerAccount[$cliente->id] ?? collect();
                                    // Il trattino vale per la **cella**, non per una sola leva:
                                    // dentro il ramo dell'impersonazione finiva accanto ai due
                                    // pulsanti delle altre, dicendo «niente da fare qui» proprio
                                    // dove c'erano due cose da fare.
                                    $qualcosaDaFare = ($candidati->isNotEmpty() && auth()->user()->can('utenti.impersonate'))
                                        || auth()->user()->can('lockout', $cliente)
                                        || auth()->user()->can('manage', $cliente);
                                @endphp

                                @unless ($qualcosaDaFare)
                                    <span class="text-xs text-neutral-400" title="Nessuna leva disponibile">—</span>
                                @endunless

                                @can('utenti.impersonate')

                                    @if ($candidati->count() === 1)
                                        {{-- `<a href>` GET e non un'azione Livewire: `take()`
                                             sostituisce l'utente in sessione, e una risposta
                                             Livewire lascerebbe in pagina un componente montato
                                             per l'utente precedente, col suo scope. --}}
                                        <a href="{{ route('impersonate', $candidati->first()) }}"
                                           class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50"
                                           title="Impersona {{ $candidati->first()->name }}">
                                            <span aria-hidden="true">👁</span> Impersona
                                        </a>
                                    @elseif ($candidati->count() > 1)
                                        <button type="button" wire:click="apriScelta({{ $cliente->id }})"
                                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50">
                                            <span aria-hidden="true">👁</span> Impersona ({{ $candidati->count() }})
                                        </button>
                                    @else
                                        {{-- Nessuno: o l'account non ha membri, o l'unico è chi
                                             sta guardando. Si dice, invece di lasciare una cella
                                             vuota che si legge come «funzione non disponibile». --}}
                                        <span class="text-xs text-neutral-400" title="Nessun membro impersonabile">—</span>
                                    @endif
                                @else
                                    {{-- Una cella vuota si legge come «manca qualcosa»; il
                                         trattino dice «niente da fare qui», che è la verità
                                         anche per chi non ha il permesso. --}}
                                    <span class="text-xs text-neutral-400">—</span>
                                @endcan

                                @can('lockout', $cliente)
                                    <button type="button" wire:click="apriLockout({{ $cliente->id }})"
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-neutral-600 hover:bg-neutral-100"
                                            title="{{ $cliente->locked_at ? 'Riapri la porta' : 'Blocca per insoluto' }}">
                                        <span aria-hidden="true">🔒</span> Lockout
                                    </button>
                                @endcan

                                @can('manage', $cliente)
                                    <button type="button" wire:click="apriFiscali({{ $cliente->id }})"
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-neutral-600 hover:bg-neutral-100"
                                            title="Dati fiscali">
                                        <span aria-hidden="true">🧾</span> Dati
                                    </button>
                                @endcan
                            </td>
                        </tr>

                        @if ($espanso === $cliente->id)
                            <tr wire:key="sedi-{{ $cliente->id }}" class="bg-neutral-50">
                                <td colspan="7" class="px-4 py-3" id="sedi-{{ $cliente->id }}">
                                    {{-- `$sue` e non `$sediPerAccount` intero: la riga
                                         aperta mostra le sedi di QUESTO cliente, e la
                                         differenza fra le due espressioni è la sola cosa
                                         che tiene i clienti separati in pagina. --}}
                                    @forelse ($sue as $sede)
                                        <div wire:key="sede-{{ $sede->id }}"
                                             class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 py-2 last:border-0">
                                            <span class="text-sm text-neutral-800">{{ $sede->nome }}</span>
                                            <span class="flex flex-wrap items-center gap-3">
                                                <span class="text-xs tabular-nums text-neutral-500">
                                                    {{ number_format($strumentiPerSede[$sede->id] ?? 0, 0, ',', '.') }} strumenti
                                                </span>

                                                {{-- Clausola di contratto, non impostazione di
                                                     anagrafica: il gate è `roles.manage`, che
                                                     l'Admin dell'Ente non ha (ADR-029). --}}
                                                @can('roles.manage')
                                                    <label class="flex items-center gap-2 text-xs text-neutral-600">
                                                        <span>Garanzie ricambio</span>
                                                        <select wire:change="fissaVisibilita({{ $sede->id }}, $event.target.value)"
                                                                class="rounded-md border-neutral-300 py-1 text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                                            @foreach ($this->statiVisibilita() as $stato)
                                                                <option value="{{ $stato->value }}" @selected($sede->visibilita_garanzie_ricambio === $stato)>{{ $stato->label() }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                @endcan
                                            </span>
                                        </div>
                                    @empty
                                        <p class="text-sm text-neutral-500">Nessuna sede: il cliente esiste ma non ha ancora un Ente.</p>
                                    @endforelse
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-neutral-500">
                                Nessun cliente con questi filtri.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- La scelta del membro, gatata **tre volte**, e non per abbondanza: la
         prima è `apriScelta()`/`updatingSceltaImpersonazione()`, che chiedono
         `utenti.impersonate` perché la property arriva dal browser; la seconda è
         `clienteScelto()`, che rilegge dalla porta e restituisce `null` senza
         quel permesso; questo `@can` è la terza e la più debole — da sola non
         reggerebbe nulla, ma toglie il markup a chi non deve vederlo. Un test le
         toglie tutte e tre insieme e diventa rosso. --}}
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
                <p class="text-sm text-neutral-600">
                    Si entra come una persona: permessi, Ente attivo e visibilità saranno i suoi.
                    L'ingresso è registrato e resta un banner in cima a ogni pagina.
                </p>

                <ul class="mt-4 divide-y divide-neutral-200">
                    @foreach ($suoi as $membro)
                        <li wire:key="candidato-{{ $membro->id }}" class="flex items-center justify-between gap-3 py-2">
                            <span>
                                <span class="block text-sm font-medium text-neutral-900">{{ $membro->name }}</span>
                                <span class="block text-xs text-neutral-500">{{ $membro->email }}</span>
                            </span>
                            <a href="{{ route('impersonate', $membro) }}"
                               class="rounded-md bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                                <span aria-hidden="true">👁</span> Entra
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ui.modal>
            @endif
        @endif
    @endcan

    @php $inLavorazione = $this->accountAperto(); @endphp

    @if ($inLavorazione && $pannello === 'lockout')
        <x-ui.modal :title="'Lockout — '.$inLavorazione->ragione_sociale" close="chiudiPannello">
            {{-- ⚠️ **Due interruttori e mai uno.** Quello di Stripe è in sola
                 lettura: il suo inverso è un evento di pagamento, e un umano che
                 dichiarasse «ha pagato» verrebbe smentito dal webhook successivo
                 — nel frattempo il cliente sarebbe rientrato senza pagare
                 (ADR-013). Il gesto inverso non è esposto in nessuna forma, e un
                 test cerca la chiamata sui token del sorgente. --}}
            <div class="rounded-md border border-neutral-200 p-3">
                <p class="text-sm font-medium text-neutral-900">Blocco automatico (Stripe)</p>
                @if ($inLavorazione->stripe_locked_at)
                    <p class="mt-1 text-sm text-danger-700">
                        Chiuso dal webhook il {{ $inLavorazione->stripe_locked_at->format('d/m/Y H:i') }}@if ($inLavorazione->stripe_lock_reason) — {{ $inLavorazione->stripe_lock_reason }}@endif
                    </p>
                    <p class="mt-1 text-xs text-neutral-500">
                        Si riapre da sé al primo pagamento riuscito. Non c'è un pulsante, ed è voluto.
                    </p>
                @else
                    <p class="mt-1 text-sm text-neutral-600">Nessun insoluto in corso.</p>
                @endif
            </div>

            <div class="mt-4 rounded-md border border-neutral-200 p-3">
                <p class="text-sm font-medium text-neutral-900">Blocco manuale</p>

                @if ($inLavorazione->locked_at)
                    {{-- Il motivo si legge **qui**: `/bloccato` è muta di proposito,
                         quindi questo è il solo posto dove ritrovarlo fra sei mesi. --}}
                    <p class="mt-1 text-sm text-danger-700">
                        Chiuso il {{ $inLavorazione->locked_at->format('d/m/Y H:i') }}@if ($inLavorazione->locked_reason) — {{ $inLavorazione->locked_reason }}@endif
                    </p>

                    <button type="button" wire:click="sbloccaAccount"
                            class="mt-3 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        Riapri la porta
                    </button>

                    @if ($inLavorazione->stripe_locked_at)
                        <p class="mt-2 text-xs text-warning-800">
                            ⚠️ L'insoluto Stripe resta acceso: l'account resterà chiuso comunque.
                        </p>
                    @endif
                @else
                    <label for="motivo-lockout" class="mt-3 block text-sm font-medium text-neutral-800">Motivo</label>
                    <input id="motivo-lockout" type="text" wire:model="motivoLockout"
                           placeholder="Fattura 2026/114 scaduta da 60 giorni"
                           class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    @error('motivoLockout')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-neutral-500">
                        Non lo vedrà il cliente: la pagina di blocco è muta. Lo leggerà chi riapre questo caso fra sei mesi.
                    </p>

                    <button type="button" wire:click="bloccaAccount"
                            class="mt-3 rounded-md bg-danger-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-danger-700">
                        Chiudi la porta
                    </button>
                @endif
            </div>
        </x-ui.modal>
    @endif

    @if ($inLavorazione && $pannello === 'fiscali')
        <x-ui.modal :title="'Dati fiscali — '.$inLavorazione->ragione_sociale" close="chiudiPannello">
            <div class="space-y-3">
                @foreach ([
                    'ragione_sociale' => 'Ragione sociale',
                    'partita_iva' => 'Partita IVA',
                    'codice_fiscale' => 'Codice fiscale',
                    'pec' => 'PEC',
                    'codice_destinatario_sdi' => 'Codice destinatario SDI',
                ] as $campo => $etichetta)
                    <div wire:key="fisc-{{ $campo }}">
                        <label for="fisc-{{ $campo }}" class="block text-sm font-medium text-neutral-800">{{ $etichetta }}</label>
                        <input id="fisc-{{ $campo }}" type="text" wire:model="fiscali.{{ $campo }}"
                               class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        @error('fiscali.'.$campo)
                            <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <p class="mt-3 text-xs text-neutral-500">
                Il codice destinatario è di 6 caratteri per la Pubblica Amministrazione, 7 per i privati.
                I dati si riallineano a Stripe alla prossima operazione di fatturazione, non da qui.
            </p>

            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="chiudiPannello"
                        class="rounded-md px-3 py-1.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100">Annulla</button>
                <button type="button" wire:click="salvaFiscali"
                        class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">Salva</button>
            </div>
        </x-ui.modal>
    @endif

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-neutral-500">
            @if ($clienti->total() > 0)
                {{ $clienti->firstItem() }}–{{ $clienti->lastItem() }} di {{ number_format($clienti->total(), 0, ',', '.') }} clienti
            @endif
        </p>

        {{ $clienti->links() }}
    </div>

</div>

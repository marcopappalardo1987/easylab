@php
    use App\Support\Piani;
    use App\Support\Piattaforma\Perimetro;

    // ⛔ I flag si calcolano QUI, in un blocco solo, e il markup resta piatto:
    // gli `@if` annidati dentro un paragrafo hanno già fatto sparire un blocco
    // intero dalla vista compilata, su questa stessa piattaforma.
    [$ordinatoPer, $direzione] = $ordinamento;

    // 🔴 `eNessuno()` non distingue «selezione vuota» da «piano non ancora
    // scelto»: sono lo stesso perimetro — `nessuno()` — ma NON la stessa
    // domanda. Scegliendo «Per piano» il piano è ancora vuoto, e la pagina
    // consigliava «scegli almeno un cliente» sotto una tendina di PIANI, senza
    // nessuna lista di clienti da usare. Il testo si ramifica sul modo, che è
    // già normalizzato dal componente.
    $pianoDaScegliere = $modo === Perimetro::PER_PIANO && $piano === '';

    // 🔴 E la stessa distinzione, un modo più in là: «non hai preferiti» non è
    // «non hai scelto nessun cliente». Il consiglio «scegline almeno uno dal
    // filtro qui sopra» manderebbe a una `<select>` che nel modo preferiti non
    // esiste — i preferiti si segnano nell'elenco Clienti, e il messaggio deve
    // mandare LÌ.
    //
    // ⚠️ Si guarda l'insieme **già intersecato** e non `eNessuno()`: i preferiti
    // possono essere non vuoti di ID e vuoti di CLIENTI — un preferito verso un
    // account cestinato, o diventato di piattaforma, dopo la segnatura — e
    // `eNessuno()` guarda la lista grezza, quindi quel caso non lo vede. La
    // pagina direbbe «questi clienti non hanno pezzi» di clienti che non ci
    // sono. È la stessa condizione che le altre due schede calcolano.
    $nessunPreferito = $modo === Perimetro::PREFERITI && $preferiti->isEmpty();

    // ⛔ E il perimetro «senza clienti» comprende quel caso, o il messaggio
    // comparirebbe SOPRA la tabella invece che al suo posto.
    $nessunCliente = $perimetro->eNessuno() || $nessunPreferito;

    $puoImpersonare = auth()->user()?->can('utenti.impersonate') ?? false;

    // La casella e la domanda non sono la stessa cosa: `ripulisciNome()` toglie
    // anche l'NBSP dei copia-incolla, quindi una casella che sembra piena può
    // non essere una ricerca. La pagina deve dire il vero su cosa ha cercato.
    $ricercaIgnorata = trim($search) !== '' && $cercato === '';
@endphp

<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />
    <x-parco.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-ink">{{ $titolo }}</h1>
        <p class="mt-1 text-sm text-ink-2">
            Il catalogo pezzi dei clienti nel perimetro, in sola lettura. Ogni riga dice di chi è:
            per cambiare qualcosa si impersona il cliente, e la modifica resta a suo nome nel registro.
        </p>
    </div>

    {{-- ── Il perimetro ──────────────────────────────────────────────────────
         Le tre property sono in query string, quindi una vista filtrata si manda
         per link — che è come si chiede aiuto su un cliente.

         ⛔ «Nessuno selezionato» NON è «nessun filtro»: su una vista
         cross-cliente la differenza fra le due letture è fra zero righe e le
         righe di tutti. `Perimetro` fa cadere lì ogni input che non si è capito,
         e questa pagina lo dice a schermo invece di mostrare una tabella vuota. --}}
    {{-- ⚠️ La barra sta su una `x-ui.card` come sulle altre due schede del
         Parco: era l'unica delle tre con i filtri appoggiati direttamente sullo
         sfondo della pagina, e le tre si visitano di fila dalle stesse tre
         linguette — una superficie che compare e scompare fra una linguetta e
         l'altra si legge come «questa pagina è un'altra cosa». --}}
    <x-ui.card class="mt-6">
        <div class="flex flex-wrap items-end gap-3">

            <div>
                <label for="parco-modo" class="block text-sm font-medium text-ink">Clienti</label>
                <select id="parco-modo" wire:model.live="modo"
                        class="mt-1 block rounded-md border border-border-strong bg-surface px-2 py-2 text-sm text-ink shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                    <option value="{{ Perimetro::TUTTI }}">Tutti i clienti</option>
                    <option value="{{ Perimetro::PER_PIANO }}">Per piano</option>
                    {{-- ⚠️ L'etichetta è IDENTICA sulle tre schede del Parco: due
                         schermi che chiamano lo stesso perimetro con due nomi
                         diversi sono due perimetri, per chi legge. --}}
                    <option value="{{ Perimetro::PREFERITI }}">★ I miei preferiti</option>
                </select>
            </div>

            @if ($modo === Perimetro::PER_PIANO)
                <div>
                    <label for="parco-piano" class="block text-sm font-medium text-ink">Piano</label>
                    <select id="parco-piano" wire:model.live="piano"
                            class="mt-1 block rounded-md border border-border-strong bg-surface px-2 py-2 text-sm text-ink shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">Scegli un piano</option>
                        @foreach (Piani::codici() as $codice)
                            <option value="{{ $codice }}">{{ Piani::etichetta($codice) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{-- ⛔ Niente `<select multiple>`: i preferiti si LEGGONO qui e si
                 CAMBIANO nell'elenco Clienti. Un secondo posto in cui modificarli
                 sarebbe un secondo insieme il giorno in cui i due divergessero. --}}
            {{-- ⚠️ Forma IDENTICA a quella delle altre due schede: riquadro
                 contornato su `bg-surface-sunken`, una **riga propria** (`w-full`)
                 dentro la barra, link in fondo. Prima era testo nudo appoggiato
                 sullo sfondo della pagina, cioè lo stesso filtro con tre aspetti
                 diversi su tre schermi che si visitano di fila. --}}
            @if ($modo === Perimetro::PREFERITI)
                <div class="w-full">
                    <p class="block text-sm font-medium text-ink">
                        I miei preferiti ({{ $preferiti->count() }})
                    </p>
                    <div class="mt-1 rounded-md border border-border-strong bg-surface-sunken px-3 py-2 text-sm text-ink">
                        @if ($preferiti->isEmpty())
                            <p class="text-ink-3">Nessuno, per ora.</p>
                        @else
                            <p class="leading-relaxed">{{ $preferiti->pluck('ragione_sociale')->implode(', ') }}</p>
                        @endif
                        {{-- L'ancora porta alla barra dei filtri dell'elenco
                             Clienti: senza, il link atterra in cima alla cabina e
                             la tabella con le ★ resta sotto i grafici. --}}
                        <a href="{{ route('piattaforma.index') }}#elenco-clienti"
                            class="mt-1 inline-block text-xs font-medium text-brand hover:underline">
                            Gestisci i preferiti nell'elenco Clienti
                        </a>
                    </div>
                </div>
            @endif

            <div class="min-w-56 flex-1">
                <label for="parco-cerca" class="block text-sm font-medium text-ink">Cerca un pezzo</label>
                <input id="parco-cerca" type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Nome del pezzo o codice costruttore"
                       class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
            </div>

            <div>
                <label for="parco-per-pagina" class="block text-sm font-medium text-ink">Per pagina</label>
                <select id="parco-per-pagina" wire:model.live="perPage"
                        class="mt-1 block rounded-md border border-border-strong bg-surface px-2 py-2 text-sm text-ink shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                    @foreach ($this->opzioniPerPage() as $taglia)
                        <option value="{{ $taglia }}">{{ $taglia }}</option>
                    @endforeach
                </select>
            </div>

        </div>
    </x-ui.card>

    @if ($ricercaIgnorata)
        <p class="mt-2 text-xs text-ink-3">
            La ricerca è vuota: quel testo è fatto di soli spazi. Sono elencati tutti i pezzi del perimetro.
        </p>
    @endif

    {{-- ⚠️ Cliente e sede si LEGGONO, non si ORDINANO, e la riga lo dice invece
         di lasciar cercare una freccia che non c'è: ordinare per nome del
         cliente vorrebbe una join su tabelle che perderebbero i propri scope, o
         una sottoquery scopata che tornerebbe NULL per ogni cliente diverso dal
         proprio. Il raggruppamento per cliente lo fa il filtro qui sopra. --}}
    <p class="mt-2 text-xs text-ink-3">
        Si ordina per pezzo e per data di inserimento. Per guardare un cliente alla volta si usa il filtro «Clienti».
    </p>

    @if ($pianoDaScegliere)
        <x-ui.card class="mt-4 border-warn-dot bg-warn-soft">
            <p class="text-sm text-warn-soft-ink">
                <span aria-hidden="true">⚠️</span>
                Nessun piano scelto: qui non c'è niente da mostrare.
                Scegli un piano dalla tendina «Piano», oppure torna a «Tutti i clienti».
                Un piano non scelto non vale «tutti».
            </p>
        </x-ui.card>
    @elseif ($nessunPreferito)
        <x-ui.card class="mt-4 border-warn-dot bg-warn-soft">
            <p class="text-sm text-warn-soft-ink">
                <span aria-hidden="true">⚠️</span>
                Non hai ancora clienti preferiti: segnali con la ★ nell'elenco Clienti.
                Qui non c'è niente da mostrare, e un insieme vuoto non vale «tutti».
            </p>
        </x-ui.card>
    @elseif ($nessunCliente)
        {{-- ⚠️ Difesa in profondità, oggi irraggiungibile: tolta la voce «scelti
             dalla tendina, un perimetro vuoto può nascere solo dal piano non
             ancora scelto o dai preferiti mancanti, cioè dai due rami qui
             sopra. Sta scritto perché il giorno in cui un quarto modo cadesse
             su `nessuno()` la pagina lo dica, invece di mostrare una tabella
             vuota che si legge come «questi clienti non hanno pezzi». --}}
        <x-ui.card class="mt-4 border-warn-dot bg-warn-soft">
            <p class="text-sm text-warn-soft-ink">
                <span aria-hidden="true">⚠️</span>
                Nessun cliente selezionato: qui non c'è niente da mostrare.
                Scegli un perimetro, oppure passa a «Tutti i clienti».
                Una selezione vuota non vale «tutti».
            </p>
        </x-ui.card>
    @endif

    @unless ($nessunCliente)
        <p class="mt-2 text-xs text-ink-3">
            {{ number_format($ricambi->total(), 0, ',', '.') }}
            {{ $ricambi->total() === 1 ? 'pezzo a catalogo' : 'pezzi a catalogo' }} nel perimetro.
        </p>

        {{-- ⚠️ Deviazione dichiarata dalla checklist §7 del Design System, la
             stessa della cabina: la tabella resta a scorrimento orizzontale
             sotto i 640px perché questa pagina si guarda da scrivania. I `<td>`
             hanno un solo figlio diretto ciascuno, che è la condizione per
             passare a `tabella-a-card` il giorno in cui lo si volesse. --}}
        <x-ui.card class="mt-4 !p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-border bg-surface-sunken text-left text-xs uppercase tracking-wide text-ink-3">
                        <tr>
                            <th scope="col" class="py-3 pl-4 pr-3">Cliente</th>
                            <th scope="col" class="px-3 py-3">Sede</th>

                            @foreach ([
                                'nome' => ['Pezzo', 'px-3 py-3'],
                                'created_at' => ['In catalogo dal', 'px-3 py-3'],
                            ] as $colonna => [$etichetta, $classi])
                                <th scope="col" class="{{ $classi }}"
                                    aria-sort="{{ $ordinatoPer === $colonna ? ($direzione === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <button type="button" wire:click="ordina('{{ $colonna }}')"
                                            class="uppercase tracking-wide hover:text-ink">
                                        {{ $etichetta }}
                                        @if ($ordinatoPer === $colonna)
                                            <span aria-hidden="true">{{ $direzione === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </button>
                                </th>
                            @endforeach

                            <th scope="col" class="px-3 py-3">Codice</th>
                            <th scope="col" class="px-3 py-3 text-right" title="Quanti clienti del perimetro hanno lo stesso pezzo a catalogo">
                                Presso
                            </th>
                            <th scope="col" class="py-3 pl-3 pr-4 text-right">
                                <span class="sr-only">Azioni</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border">
                        @forelse ($ricambi as $ricambio)
                            @php
                                // ⚠️ Sede e cliente vengono dalla PORTA, con lo
                                // stesso perimetro delle righe: se una delle due
                                // mancasse — caso non raggiungibile, perché il
                                // `tenant_id` di ogni riga viene da `idSedi()` —
                                // si scrive un trattino invece di mostrare un
                                // pezzo di cui non si sa di chi è.
                                $sede = $sedi->get($ricambio->tenant_id);
                                $cliente = $sede ? $clientiPerId->get($sede->account_id) : null;
                                $candidati = $cliente ? ($candidatiPerAccount[$cliente->id] ?? collect()) : collect();
                                $presso = $diffusione[$ricambio->nome_normalizzato] ?? 1;
                            @endphp

                            <tr wire:key="ricambio-{{ $ricambio->id }}" class="align-top">

                                <td class="py-3 pl-4 pr-3">
                                    <span class="font-medium text-ink">{{ $cliente?->ragione_sociale ?? '—' }}</span>
                                </td>

                                <td class="px-3 py-3">
                                    <span class="text-ink-2">{{ $sede?->nome ?? '—' }}</span>
                                </td>

                                <td class="px-3 py-3">
                                    <span>
                                        <span class="block font-medium text-ink">{{ $ricambio->nome }}</span>
                                        @if ($ricambio->descrizione)
                                            <span class="block text-xs text-ink-3">{{ $ricambio->descrizione }}</span>
                                        @endif
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-3 py-3 tabular-nums text-ink-2">
                                    {{ $ricambio->created_at?->format('d/m/Y') }}
                                </td>

                                <td class="px-3 py-3 text-ink-2">
                                    {{ $ricambio->codice ?: '—' }}
                                </td>

                                {{-- Il numero da solo direbbe una cosa e se ne
                                     capirebbe un'altra: «presso 9 clienti» sopra
                                     una pagina che ne mostra 2 non si sa se
                                     parli del perimetro o della piattaforma. Il
                                     titolo lo dice per esteso. --}}
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-ink-2"
                                    title="Clienti del perimetro che hanno «{{ $ricambio->nome }}» a catalogo">
                                    {{ $presso > 1 ? $presso.' clienti' : 'solo qui' }}
                                </td>

                                <td class="py-3 pl-3 pr-4 text-right">
                                    @if (! $puoImpersonare)
                                        {{-- Una cella vuota si legge come «manca
                                             qualcosa»; il trattino dice «niente
                                             da fare qui», che è la verità anche
                                             per chi non ha il permesso. --}}
                                        <span class="text-xs text-ink-3">—</span>
                                    @elseif ($cliente === null)
                                        <span class="text-xs text-ink-3" title="Cliente non risolto">—</span>
                                    @elseif ($candidati->count() === 1)
                                        {{-- `<a href>` GET e non un'azione
                                             Livewire: `take()` sostituisce
                                             l'utente in sessione, e una risposta
                                             Livewire lascerebbe in pagina un
                                             componente montato per l'utente
                                             precedente, col suo scope.

                                             ⛔ E la rotta è quella del
                                             pacchetto, NON quella del Parco che
                                             atterra sulla macchina: questa riga
                                             è una voce di catalogo, e un pezzo
                                             non ha una macchina univoca — sta
                                             su zero, una o venti. Sceglierne
                                             una la farebbe decidere a una
                                             query, e l'indirizzo del pulsante
                                             direbbe per giunta dove il pezzo è
                                             montato: ciò che questa scheda non
                                             dice (ADR-029). Il perché per
                                             esteso sta nel docblock del
                                             componente. --}}
                                        <a href="{{ route('impersonate', $candidati->first()) }}"
                                           class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft"
                                           title="Impersona {{ $candidati->first()->name }} ({{ $cliente->ragione_sociale }})">
                                            <span aria-hidden="true">👁</span> Impersona
                                        </a>
                                    @elseif ($candidati->count() > 1)
                                        <button type="button" wire:click="apriScelta({{ $cliente->id }})"
                                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft">
                                            <span aria-hidden="true">👁</span> Impersona ({{ $candidati->count() }})
                                        </button>
                                    @else
                                        {{-- Nessuno: o il cliente non ha membri,
                                             o l'unico è chi sta guardando, o si
                                             sta già impersonando. Si DICE: su
                                             questa piattaforma un'assenza muta ha
                                             già fatto chiedere «non esiste o è
                                             rotto?». --}}
                                        <span class="text-xs text-ink-3" title="Nessun membro impersonabile">—</span>
                                    @endif
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-ink-3">
                                    Nessun pezzo a catalogo con questi filtri.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div class="mt-4">
            {{ $ricambi->links() }}
        </div>
    @endunless

    {{-- La scelta del membro, gatata **tre volte** come in cabina, e non per
         abbondanza: `apriScelta()` e `updatingSceltaImpersonazione()` chiedono
         `utenti.impersonate` perché la property arriva dal browser;
         `clienteScelto()` rilegge dalla porta e torna `null` senza quel
         permesso; questo `@can` è la terza e la più debole — da sola non
         reggerebbe nulla, ma toglie il markup a chi non deve vederlo. --}}
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

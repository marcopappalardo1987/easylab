{{--
    L'editor della matrice ruolo→permesso — per ora **in sola lettura**.

    Le 324 celle si guardano, non si toccano: nessun `wire:click`, nessuna
    scrittura. È deliberato (vedi il docblock di `EditorRuoli`): leggere
    correttamente la matrice è un lavoro a sé, e questa tappa esiste perché si
    possa verificare a occhio, su staging, che `MatriceRuoli::stato()` dica il
    vero prima che esista un bottone che scrive.

    ⚠️ **Orientamento**: 6 ruoli in colonna, 54 permessi in riga — lo stesso di
    `Schema Ruoli §5`, perché questa pagina si affianca a quel documento e chi
    confronta i due non deve trasporre a mente. Attrito di vocabolario che ne
    segue: il «set bloccato è una colonna» dei documenti (un permesso attraverso
    tutti i ruoli) è qui una **riga**, e «la riga del Developer» è qui una
    **colonna**.

    ⚠️ **Ciò che si vede qui non protegge niente.** Il 🔒 e la colonna inerte
    sono presentazione; la guardia sta in `App\Support\Rbac\MatriceRuoli`, che
    rifiuta prima di toccare il database. Un `@if` in Blade si toglie in un
    secondo, e `Livewire::test()` non passa nemmeno dai middleware.

    ⚠️ **Deviazione dichiarata dalla checklist §7 del Design System**, che vuole
    `tabella-a-card` con i `data-etichetta` su ogni vista nuova. Qui la tabella
    resta a scorrimento orizzontale sotto i 640px, e la ragione è più forte che
    per la cabina (che deviò per lo stesso motivo, e prima ancora
    `elenco-strumenti`): una griglia esiste **per il confronto fra colonne** —
    «l'Admin ce l'ha e il Tenant no» si legge in un colpo d'occhio e in nessun
    altro modo. Spezzata in 54 schede da sei righe ciascuna, il confronto
    sparisce e restano 324 fatti isolati: sarebbe conforme alla checklist e
    inutile. La colonna dei nomi è invece `sticky` a sinistra, che è la
    mitigazione vera per uno schermo stretto. Questa è la cabina di regia di chi
    amministra la piattaforma, non una vista da campo.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Ruoli e permessi</h1>
        <p class="mt-1 text-sm text-neutral-600">
            Chi può fare cosa, per tutti i clienti insieme.
        </p>
    </div>

    {{-- ⚠️ Non è una nota di colore, è l'ambito del gesto. Con `teams = false`
         (config/permission.php) i permessi di un ruolo sono **globali**: una
         modifica qui vale per tutti gli Enti insieme, e non esiste un ambito
         per-Ente su cui provarla prima. Va detto in pagina e non solo in un
         commento, perché chi guarda questa griglia sta per cambiare il
         comportamento di tutti i clienti. --}}
    <x-ui.card class="mt-4 border-warning-500 bg-warning-100">
        <p class="text-sm text-warning-800">
            <span aria-hidden="true">⚠️</span>
            Questa matrice è <strong>globale</strong>: vale per tutti gli Enti insieme, senza rilascio
            progressivo. Le impostazioni per-Ente possono <strong>restringere</strong> questi permessi,
            mai allargarli.
        </p>
        <p class="mt-2 text-xs text-warning-800">
            In sola lettura per ora: le modifiche si fanno ancora da
            <code>config/rbac.php</code> e dal seeder.
        </p>
    </x-ui.card>

    {{-- La legenda. Serve perché tre dei quattro simboli non sono ovvi, e
         soprattutto perché 🔒 qui NON vuol dire «negato»: dice che la UI non
         può ridistribuire quel permesso, a nessun ruolo e in nessuna direzione.
         Le due cose si erano già confuse una volta, su `garanzie.ricambio.*`
         (ADR-027), e la confusione costò due voci nel set sbagliato per mesi. --}}
    <dl class="mt-6 flex flex-wrap gap-x-6 gap-y-1 text-xs text-neutral-600">
        <div class="flex items-center gap-1.5">
            <dt aria-hidden="true">✅</dt>
            <dd>il ruolo ha il permesso</dd>
        </div>
        <div class="flex items-center gap-1.5">
            <dt aria-hidden="true">❌</dt>
            <dd>non ce l'ha</dd>
        </div>
        <div class="flex items-center gap-1.5">
            <dt aria-hidden="true">🔒</dt>
            <dd>bloccato: non ridistribuibile dalla UI, a <em>nessun</em> ruolo — non «negato»</dd>
        </div>
        <div class="flex items-center gap-1.5">
            <dt aria-hidden="true">🔑</dt>
            <dd>riga di riserva della piattaforma: intoccabile</dd>
        </div>
    </dl>

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <caption class="sr-only">
                    Matrice dei permessi: {{ count(App\Support\Rbac::permissions()) }} permessi del
                    catalogo per {{ count($ruoli) }} ruoli.
                </caption>

                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" class="sticky left-0 z-10 bg-neutral-50 py-3 pl-4 pr-3 text-left">Permesso</th>

                        @foreach ($ruoli as $ruolo)
                            @php $ruoloProtetto = App\Support\Rbac::isRuoloProtetto($ruolo); @endphp
                            {{-- ⚠️ `Rbac::isRuoloProtetto()` e **non** un elenco
                                 scritto qui: è la stessa disciplina di
                                 `canBeImpersonated()` — «una definizione, mai
                                 una seconda copia», perché la copia il giorno in
                                 cui la prima cambia resta indietro in silenzio.
                                 La colonna che si vede inerte e la riga che il
                                 metodo di dominio rifiuta devono essere la
                                 stessa cosa. --}}
                            <th scope="col"
                                data-ruolo="{{ $ruolo }}"
                                @if ($ruoloProtetto) data-inerte="1" @endif
                                class="px-3 py-3 text-center {{ $ruoloProtetto ? 'text-neutral-400' : '' }}">
                                <span class="whitespace-nowrap">{{ $ruolo }}</span>
                                @if ($ruoloProtetto)
                                    <span class="mt-0.5 block normal-case tracking-normal"
                                          title="La chiave di riserva della piattaforma: non esiste un Gate::before da super-admin, quindi il Developer dipende davvero da questa riga.">
                                        <span aria-hidden="true">🔑</span> sola lettura
                                    </span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                @foreach ($gruppi as $prefisso => $permessi)
                    {{-- L'etichetta del gruppo è una trasformazione **meccanica**
                         del prefisso, non un nome scelto: se fosse scelto
                         sarebbe una mappa da tenere allineata al catalogo, cioè
                         il settimo elenco parallelo di questo progetto. Il nome
                         completo del permesso resta scritto per esteso in ogni
                         riga, così ciò che si confronta col documento è sempre
                         la stringa vera. --}}
                    <tbody wire:key="gruppo-{{ $prefisso }}" class="divide-y divide-neutral-100 border-b border-neutral-200">
                        <tr class="bg-neutral-50/60">
                            <th scope="colgroup" colspan="{{ count($ruoli) + 1 }}"
                                data-gruppo="{{ $prefisso }}"
                                class="sticky left-0 px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                {{ ucfirst(str_replace('_', ' ', $prefisso)) }}
                            </th>
                        </tr>

                        @foreach ($permessi as $permesso)
                            @php
                                $bloccato = App\Support\Rbac::isLocked($permesso);
                                // ⚠️ Il «non seminato»: il catalogo lo dichiara e a
                                // database non c'è. È la forma dell'incidente
                                // `fornitori.view` — «il documento affermava un
                                // default che la config non creava» — un gradino
                                // più in là. Va reso **come tale** e non dedotto da
                                // un ❌ su tutta la riga: un ❌ dice «nessuno ce
                                // l'ha», questo dice «non è accendibile finché non
                                // si semina», che è un'altra cosa e porta a un
                                // altro gesto.
                                $daSeminare = ! isset($seminati[$permesso]);
                            @endphp

                            <tr wire:key="permesso-{{ $permesso }}"
                                data-permesso="{{ $permesso }}"
                                @if ($bloccato) data-bloccato="1" @endif
                                @if ($daSeminare) data-da-seminare="1" @endif
                                class="hover:bg-neutral-50">
                                <th scope="row" class="sticky left-0 z-10 bg-white py-2 pl-4 pr-3 text-left font-normal hover:bg-neutral-50">
                                    <span class="font-mono text-xs text-neutral-800">{{ $permesso }}</span>
                                    @if ($bloccato)
                                        <span class="ml-1 whitespace-nowrap text-xs text-neutral-500"
                                              title="Nel set bloccato di config/rbac.php: la UI non può ridistribuirlo, a nessun ruolo e in nessuna direzione. Allargare o restringere il set è un'operazione di codice.">
                                            <span aria-hidden="true">🔒</span>
                                            <span class="sr-only">bloccato:</span> non modificabile
                                        </span>
                                    @endif
                                    @if ($daSeminare)
                                        <x-ui.badge variant="warning" class="ml-1">da seminare</x-ui.badge>
                                    @endif
                                </th>

                                @foreach ($ruoli as $ruolo)
                                    @php $ha = isset($matrice[$ruolo][$permesso]); @endphp
                                    {{-- `isset()` sulla matrice già in memoria, e
                                         **mai** `$role->hasPermissionTo()`: quello
                                         passa dal registrar e senza eager load
                                         sarebbero 324 risoluzioni per rendere una
                                         pagina. Il conteggio delle query che lo
                                         congela sta su `MatriceRuoli::stato()`, in
                                         isolamento. --}}
                                    <td data-ruolo="{{ $ruolo }}"
                                        data-stato="{{ $ha ? 'si' : 'no' }}"
                                        class="px-3 py-2 text-center">
                                        <span aria-hidden="true">{{ $ha ? '✅' : '❌' }}</span>
                                        <span class="sr-only">{{ $ruolo }}: {{ $ha ? 'sì' : 'no' }}</span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    </x-ui.card>

    {{-- ⚠️ **La striscia degli orfani, a parte e non fra le righe.**

         Sono righe di `permissions` che il catalogo non conosce più: il seeder
         usa `firstOrCreate` e non cancella mai ciò che ha smesso di conoscere.
         Non è un caso teorico — i `letture_contaore.*`, tolti dalla config in
         S3-bis (ADR-019), restarono attaccati a quattro ruoli del DB di sviluppo
         fino all'8 Ago 2026.

         Mescolarli alle 54 righe le legittimerebbe; ometterli farebbe dire alla
         pagina che il database è pulito quando non lo è. La terza via è
         mostrarli **fuori dalla griglia**, dove si vedono e non si toccano. Il
         blocco non compare affatto quando non ce ne sono, che è il caso normale:
         una sezione vuota permanente insegna a non guardarla. --}}
    @if ($orfani !== [])
        <div class="mt-8" data-orfani>
            <h2 class="text-sm font-semibold text-neutral-900">
                <span aria-hidden="true">⚠️</span>
                Permessi non più nel catalogo
            </h2>
            <p class="mt-1 text-xs text-neutral-600">
                Esistono a database ma <code>config/rbac.php</code> non li dichiara più. Non si riassegnano
                dalla UI e non si cancellano di qui: vanno rimossi a mano.
            </p>

            <x-ui.card class="mt-3 !p-0 border-warning-500">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead class="border-b border-neutral-200 bg-warning-100 text-xs uppercase tracking-wide text-warning-800">
                            <tr>
                                <th scope="col" class="py-3 pl-4 pr-3 text-left">Permesso orfano</th>
                                @foreach ($ruoli as $ruolo)
                                    <th scope="col" class="px-3 py-3 text-center">
                                        <span class="whitespace-nowrap">{{ $ruolo }}</span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            @foreach ($orfani as $orfano)
                                <tr wire:key="orfano-{{ $orfano }}" data-orfano="{{ $orfano }}">
                                    <th scope="row" class="py-2 pl-4 pr-3 text-left font-normal">
                                        <span class="font-mono text-xs text-neutral-800">{{ $orfano }}</span>
                                    </th>
                                    @foreach ($ruoli as $ruolo)
                                        @php $ha = isset($matrice[$ruolo][$orfano]); @endphp
                                        <td data-ruolo="{{ $ruolo }}" data-stato="{{ $ha ? 'si' : 'no' }}"
                                            class="px-3 py-2 text-center">
                                            <span aria-hidden="true">{{ $ha ? '✅' : '❌' }}</span>
                                            <span class="sr-only">{{ $ruolo }}: {{ $ha ? 'sì' : 'no' }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    @endif

</div>

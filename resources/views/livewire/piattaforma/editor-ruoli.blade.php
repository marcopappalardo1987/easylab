{{--
    L'editor della matrice ruolo→permesso.

    Ogni cella modificabile è un bottone, e un click **scrive subito**: non c'è
    un «salva». La ragione sta nel docblock di `EditorRuoli` ed è strutturale —
    un salvataggio di riga accetterebbe un array dal browser, e un permesso
    bloccato potrebbe essere *omesso* invece che revocato.

    ⚠️ **Tre famiglie di celle non sono bottoni**, e i tre marcatori esistevano
    già da prima che ci fossero i controlli: la riga bloccata (`data-bloccato`),
    la colonna del ruolo protetto (`data-inerte`) e la riga non seminata
    (`data-da-seminare`). Si **riusano**, non si ricalcolano: `MatriceRuoli`
    rifiuta esattamente quei tre gesti, e una seconda copia della condizione
    scritta qui divergerebbe dalla prima senza che nulla lo dica.

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
        <h1 class="text-2xl font-bold tracking-tight text-ink">Ruoli e permessi</h1>
        <p class="mt-1 text-sm text-ink-2">
            Chi può fare cosa, per tutti i clienti insieme.
        </p>
    </div>

    {{-- ⚠️ Non è una nota di colore, è l'ambito del gesto. Con `teams = false`
         (config/permission.php) i permessi di un ruolo sono **globali**: una
         modifica qui vale per tutti gli Enti insieme, e non esiste un ambito
         per-Ente su cui provarla prima. Va detto in pagina e non solo in un
         commento, perché chi guarda questa griglia sta per cambiare il
         comportamento di tutti i clienti. --}}
    <x-ui.card class="mt-4 !border-warn-dot !bg-warn-soft">
        <p class="text-sm text-warn-soft-ink">
            <span aria-hidden="true">⚠️</span>
            Questa matrice è <strong>globale</strong>: vale per tutti gli Enti insieme, senza rilascio
            progressivo. Le impostazioni per-Ente possono <strong>restringere</strong> questi permessi,
            mai allargarli.
        </p>
        {{-- 🔴 **Il secondo fattore non segue i permessi: segue i NOMI DEI
             RUOLI.** `EnsureTwoFactorIsEnabled` legge
             `Rbac::twoFactorRequiredRoles()`, quindi concedere un potere forte a
             un ruolo che non è in quell'elenco lo consegna a chi entra con la
             sola password. È la direzione in cui questa pagina fa danno davvero,
             ed è la meno intuitiva — l'istinto dice che concedere è additivo e
             reversibile. Va detto **qui**, dove si guarda la griglia e si decide,
             e non solo nella modale, che si apre quando la decisione è già
             presa.

             L'elenco si deriva dalla config e non si scrive a mano: sarebbe
             l'ennesimo elenco parallelo. --}}
        <p class="mt-2 text-sm text-warn-soft-ink" data-avviso-2fa="pagina">
            <span aria-hidden="true">⚠️</span>
            Il secondo fattore è obbligatorio <strong>per nome di ruolo</strong>, non per permesso:
            oggi lo richiedono {{ implode(', ', App\Support\Rbac::twoFactorRequiredRoles()) }}.
            Concedere un permesso a
            <strong>{{ implode(', ', array_diff(App\Support\Rbac::roleNames(), App\Support\Rbac::twoFactorRequiredRoles())) }}</strong>
            lo dà anche a chi accede con la sola password.
        </p>
        <p class="mt-2 text-xs text-warn-soft-ink">
            Ogni click scrive subito, e resta nel <a href="{{ route('piattaforma.audit') }}" class="underline">registro di audit</a>.
            Aggiungere o togliere un permesso dal <em>catalogo</em> resta un'operazione di codice.
        </p>
    </x-ui.card>

    {{-- ⚠️ **Gli errori si rendono in pagina, e non solo nella modale.**
         `MatriceRuoli` rifiuta con una `ValidationException` — fail-closed e
         rumorosa — e i tre gesti che rifiuta (cella bloccata, riga protetta,
         permesso fuori catalogo) sono per costruzione quelli che la pagina non
         offre: chi li produce non sta usando questa schermata. Se l'errore
         vivesse solo dentro la modale, una richiesta forgiata a mano tornerebbe
         **muta**, e il rifiuto si leggerebbe come «non è successo niente». --}}
    @if ($errors->any())
        <x-ui.card class="mt-4 !border-bad-dot !bg-bad-soft" data-errore>
            <p class="text-sm font-medium text-bad-soft-ink">Gesto rifiutato</p>
            <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-bad-soft-ink">
                @foreach ($errors->all() as $errore)
                    <li>{{ $errore }}</li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- La legenda. Serve perché tre dei quattro simboli non sono ovvi, e
         soprattutto perché 🔒 qui NON vuol dire «negato»: dice che la UI non
         può ridistribuire quel permesso, a nessun ruolo e in nessuna direzione.
         Le due cose si erano già confuse una volta, su `garanzie.ricambio.*`
         (ADR-027), e la confusione costò due voci nel set sbagliato per mesi. --}}
    <dl class="mt-6 flex flex-wrap gap-x-6 gap-y-1 text-xs text-ink-2">
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
        <div class="flex items-center gap-1.5">
            <dt aria-hidden="true">👆</dt>
            <dd>le celle senza 🔒 e senza 🔑 sono bottoni: un click inverte la cella e scrive</dd>
        </div>
        <div class="flex items-center gap-1.5">
            <dt class="text-brand">personalizzato</dt>
            <dd>la cella non dice ciò che dice <code>config/rbac.php</code>: il riseeding la riporterebbe indietro</dd>
        </div>
    </dl>

    {{-- 🔴 **La riconciliazione fra le due sorgenti.**

         Dal momento in cui la matrice si modifica da qui, `config/rbac.php`
         smette di essere la verità e diventa **il default**: le due divergono
         per costruzione, e la divergenza è la feature, non un guasto. Ciò che è
         pericoloso è che sia invisibile — perché `CLAUDE.md` ordina di
         riseminare dopo ogni modifica alla config, e il seeder fa
         `syncPermissions()`, cioè detacha tutto e riattacca dai default.
         Eseguito alla lettera, quell'ordine **corretto** cancella la matrice di
         runtime.

         Questo pannello è il confronto ruolo-per-ruolo che `CLAUDE.md` chiede a
         mano, fatto in pagina e a **zero query**. L'altra metà della stessa
         difesa vive nel seeder, che stampa il diff prima di sincronizzare e ne
         lascia una riga nel registro. --}}
    <x-ui.card class="mt-4" data-riconciliazione="{{ $quantePersonalizzate }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                @if ($quantePersonalizzate === 0)
                    <p class="text-sm text-ink-2">
                        La matrice a database <strong>coincide</strong> con <code class="text-xs">config/rbac.php</code>:
                        nessuna cella personalizzata.
                    </p>
                @else
                    <p class="text-sm text-ink">
                        <strong>{{ $quantePersonalizzate }}</strong>
                        {{ $quantePersonalizzate === 1 ? 'cella non dice' : 'celle non dicono' }} ciò che dice
                        <code class="text-xs">config/rbac.php</code>.
                    </p>
                    {{-- ⚠️ Il comando si scrive **per esteso**: chi arriva qui
                         dopo aver letto la riga di `CLAUDE.md` sta per lanciarlo,
                         e deve leggere accanto cosa fa davvero. --}}
                    <p class="mt-1 text-xs text-ink-2">
                        <span aria-hidden="true">⚠️</span>
                        <code class="text-xs">php artisan db:seed --class=RolesAndPermissionsSeeder</code>
                        le riporta <strong>tutte</strong> ai default:
                        <code class="text-xs">syncPermissions()</code> detacha e riattacca, non fonde.
                        Il comando stampa ciò che sta per portare via e ne lascia una riga nel
                        <a href="{{ route('piattaforma.audit') }}" class="underline">registro</a>.
                    </p>
                @endif
            </div>

            @if ($quantePersonalizzate > 0 || $soloDifferenze)
                {{-- L'interruttore resta anche quando le differenze sono zero, se
                     è acceso: senza, chi lo accende e poi riporta l'ultima cella
                     al default si troverebbe una griglia vuota e nessun modo di
                     tornare indietro. --}}
                <button type="button"
                        wire:click="$toggle('soloDifferenze')"
                        data-filtro-differenze="{{ $soloDifferenze ? 'attivo' : 'spento' }}"
                        class="shrink-0 rounded-md border px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-ring {{ $soloDifferenze ? 'border-brand bg-brand text-brand-ink hover:bg-brand-hover' : 'border border-border-strong text-ink hover:bg-surface-sunken' }}">
                    {{ $soloDifferenze ? 'Mostra tutti i permessi' : 'Mostra solo le differenze' }}
                </button>
            @endif
        </div>
    </x-ui.card>

    {{-- ⚠️ **Il «da seminare» col gesto accanto.** La griglia marca già le righe
         che il catalogo dichiara e il database non ha, ma un marcatore che non
         porta a un gesto lascia l'operatore a metà strada — e il gesto qui è
         proprio il comando che, sulle *altre* righe, distrugge. Le due cose
         vanno dette insieme o si sceglie alla cieca. --}}
    @if ($nonSeminati !== [])
        <x-ui.card class="mt-4 !border-warn-dot !bg-warn-soft" data-non-seminati="{{ count($nonSeminati) }}">
            <p class="text-sm text-warn-soft-ink">
                <span aria-hidden="true">⚠️</span>
                <strong>{{ count($nonSeminati) }}</strong>
                {{ count($nonSeminati) === 1 ? 'permesso del catalogo non esiste' : 'permessi del catalogo non esistono' }}
                a database ({{ implode(', ', $nonSeminati) }}): la riga è segnata «da seminare» e
                <strong>non è accendibile</strong> finché il permesso non viene creato.
            </p>
            <p class="mt-2 text-xs text-warn-soft-ink">
                <code class="text-xs">php artisan db:seed --class=RolesAndPermissionsSeeder</code>
                li crea — e nello stesso giro riporta l'intera matrice ai default, cancellando le
                personalizzazioni elencate qui sopra.
            </p>
        </x-ui.card>
    @endif

    {{-- ⚠️ **Una finestra che scorre, non una pagina lunga.** `overflow-x-auto`
         da solo non basta e non è una scelta di stile: la matrice è alta 54
         righe, e scorrendo la pagina l'intestazione dei ruoli usciva dallo
         schermo — restavano sei colonne di ✅/❌ senza più un nome sopra, cioè
         una matrice illeggibile proprio dove serve leggerla. Trovato guardando
         la pagina, non dai test: nessuna asserzione può accorgersi che
         un'intestazione è scorsa via.

         Il `sticky` dell'intestazione funziona **solo** dentro un contenitore
         che scorre di suo: `overflow-x-auto` rende `overflow-y` un `auto`
         implicito, ma senza un'altezza massima il contenitore non scorre mai e
         il `sticky` non ha nulla a cui ancorarsi. Da qui `max-h` esplicita. --}}
    <x-ui.card class="mt-4 !p-0">
        <div class="max-h-[75vh] overflow-auto">
            <table class="w-full border-collapse text-sm">
                <caption class="sr-only">
                    Matrice dei permessi: {{ count(App\Support\Rbac::permissions()) }} permessi del
                    catalogo per {{ count($ruoli) }} ruoli.
                </caption>

                <thead class="border-b border-border bg-surface-sunken text-xs uppercase tracking-wide text-ink-3">
                    <tr>
                        {{-- L'angolo: ancorato su **due** lati, quindi sopra a
                             entrambe le fasce (z-30 > z-20 dell'intestazione
                             > z-10 della colonna dei nomi). --}}
                        <th scope="col" class="sticky left-0 top-0 z-30 bg-surface-sunken py-3 pl-4 pr-3 text-left">Permesso</th>

                        @foreach ($ruoli as $ruolo)
                            @php $ruoloProtetto = isset($inerti[$ruolo]); @endphp
                            {{-- ⚠️ L'insieme arriva da `render()`, che lo deriva
                                 da `Rbac::isRuoloProtetto()` — **la** definizione
                                 di quale riga è inerte. Non si richiama qui e
                                 non si riscrive nelle celle: è la disciplina di
                                 `canBeImpersonated()` («una definizione, mai una
                                 seconda copia»), e da questo blocco in poi conta
                                 doppio, perché la colonna che si vede inerte e
                                 la colonna che non ha bottoni devono essere la
                                 stessa cosa — e devono coincidere con ciò che
                                 `MatriceRuoli` rifiuta. --}}
                            <th scope="col"
                                data-ruolo="{{ $ruolo }}"
                                @if ($ruoloProtetto) data-inerte="1" @endif
                                class="sticky top-0 z-20 bg-surface-sunken px-3 py-3 text-center {{ $ruoloProtetto ? 'text-ink-3' : '' }}">
                                <span class="whitespace-nowrap">{{ $ruolo }}</span>
                                @if ($ruoloProtetto)
                                    <span class="mt-0.5 block normal-case tracking-normal"
                                          title="La chiave di riserva della piattaforma: non esiste un Gate::before da super-admin, quindi il Developer dipende davvero da questa riga.">
                                        <span aria-hidden="true">🔑</span> sola lettura
                                    </span>
                                @elseif (isset($senzaDueFattori[$ruolo]))
                                    {{-- 🔴 La colonna dice **da sé** che non ha il
                                         secondo fattore obbligatorio. Il pannello
                                         in cima lo dichiara una volta, ma è qui —
                                         sopra la colonna che si sta per accendere
                                         — che l'informazione arriva nel momento in
                                         cui serve. Deriva da
                                         `Rbac::twoFactorRequiredRoles()`, cioè
                                         dallo stesso elenco che
                                         `EnsureTwoFactorIsEnabled` legge: non c'è
                                         una seconda copia da tenere allineata. --}}
                                    <span class="mt-0.5 block normal-case tracking-normal text-warn-soft-ink"
                                          title="EnsureTwoFactorIsEnabled richiede il secondo fattore per nome di ruolo, e questo ruolo non è nell'elenco: un permesso concesso qui arriva anche a chi entra con la sola password."
                                          data-senza-2fa="1">
                                        <span aria-hidden="true">⚠️</span> senza 2FA
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
                    <tbody wire:key="gruppo-{{ $prefisso }}" class="divide-y divide-border border-b border-border">
                        <tr class="bg-surface-sunken">
                            <th scope="colgroup" colspan="{{ count($ruoli) + 1 }}"
                                data-gruppo="{{ $prefisso }}"
                                {{-- Sfondo proprio e `z-10`: era ancorata a
                                     sinistra ma trasparente, quindi scorrendo in
                                     orizzontale il nome del gruppo si sarebbe
                                     letto **sopra** le celle che gli passavano
                                     sotto. --}}
                                class="sticky left-0 z-10 bg-surface-sunken px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-ink-3">
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
                                class="hover:bg-surface-sunken">
                                <th scope="row" class="sticky left-0 z-10 bg-surface py-2 pl-4 pr-3 text-left font-normal hover:bg-surface-sunken">
                                    <span class="font-mono text-xs text-ink">{{ $permesso }}</span>
                                    @if ($bloccato)
                                        <span class="ml-1 whitespace-nowrap text-xs text-ink-3"
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
                                    @php
                                        $ha = isset($matrice[$ruolo][$permesso]);
                                        // ⚠️ **I tre marcatori, riusati e non
                                        // ricalcolati.** Sono esattamente i tre
                                        // gesti che `MatriceRuoli` rifiuta —
                                        // permesso bloccato, ruolo protetto,
                                        // permesso non seminato — e scriverne
                                        // qui una seconda definizione
                                        // significherebbe che il giorno in cui
                                        // la prima cambia la pagina offre un
                                        // bottone che il dominio rifiuta (o,
                                        // peggio, lo nasconde dove il dominio
                                        // acconsentirebbe).
                                        //
                                        // ⚠️ E resta **presentazione**: un `@if`
                                        // in Blade si toglie in un secondo, e
                                        // `Livewire::test()` non passa dai
                                        // middleware. La protezione è in
                                        // `MatriceRuoli::applica()`, che rifiuta
                                        // prima di toccare il database.
                                        $modificabile = ! $bloccato && ! $daSeminare && ! isset($inerti[$ruolo]);
                                        // ⚠️ Il quarto marcatore, e l'unico che
                                        // **non** dice cosa la pagina permette:
                                        // dice che questa cella e
                                        // `config/rbac.php` non sono d'accordo,
                                        // cioè che il riseeding la riporterebbe
                                        // indietro. Deriva da `render()`, che
                                        // legge la config **una volta per
                                        // ruolo** invece di una volta per cella.
                                        $diverge = $personalizzate[$ruolo][$permesso] ?? null;
                                    @endphp
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
                                        @if ($modificabile)
                                            {{-- ⚠️ `wire:loading.attr="disabled"`
                                                 non è cosmesi: `commuta()`
                                                 **inverte**, quindi un doppio
                                                 click su una cella che non chiede
                                                 conferma varrebbe concedi + revoca
                                                 — due righe nel registro e lo
                                                 stato di partenza, cioè un gesto
                                                 che sembra non aver fatto niente.
                                                 Il `wire:target` lo restringe a
                                                 questa azione, o basterebbe un
                                                 render qualunque a spegnere la
                                                 griglia intera. --}}
                                            <button type="button"
                                                    wire:click="chiedi('{{ $ruolo }}', '{{ $permesso }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="chiedi"
                                                    class="rounded-md px-2 py-1 hover:bg-brand-soft-strong focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50">
                                                <span aria-hidden="true">{{ $ha ? '✅' : '❌' }}</span>
                                                {{-- Il testo per chi ascolta dice
                                                     lo stato **e** il gesto: un
                                                     bottone che annuncia solo
                                                     «Tenant: sì» non fa capire che
                                                     premerlo toglie il permesso. --}}
                                                <span class="sr-only">{{ $ruolo }}: {{ $ha ? 'sì' : 'no' }} — {{ $ha ? 'revoca' : 'concedi' }} {{ $permesso }}</span>
                                            </button>
                                        @else
                                            <span aria-hidden="true">{{ $ha ? '✅' : '❌' }}</span>
                                            <span class="sr-only">{{ $ruolo }}: {{ $ha ? 'sì' : 'no' }}</span>
                                        @endif

                                        {{-- **I due valori affiancati, non solo
                                             il fatto che divergano.** «Questa
                                             cella è personalizzata» da solo
                                             obbligherebbe ad aprire
                                             `config/rbac.php` per sapere dove il
                                             riseeding la riporterebbe — cioè a
                                             fare a mano la metà del confronto
                                             che questo pannello esiste per
                                             togliere. --}}
                                        @if ($diverge)
                                            <span class="mt-0.5 block text-[10px] font-medium leading-tight text-brand"
                                                  data-personalizzato="{{ $diverge }}"
                                                  title="Il database dice il contrario di config/rbac.php. Un `db:seed --class=RolesAndPermissionsSeeder` riporterebbe questa cella al default.">
                                                personalizzato:
                                                config {{ $diverge === 'concesso' ? 'no' : 'sì' }} →
                                                adesso {{ $diverge === 'concesso' ? 'sì' : 'no' }}
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach

                {{-- Il caso raggiungibile solo con l'interruttore acceso: il
                     catalogo non è mai vuoto. Una tabella con la sola
                     intestazione si legge come un guasto, e qui è invece la
                     risposta migliore possibile. --}}
                @if ($gruppi === [])
                    <tbody>
                        <tr>
                            <td colspan="{{ count($ruoli) + 1 }}" class="px-4 py-8 text-center text-sm text-ink-3"
                                data-nessuna-differenza>
                                Nessuna cella diversa da <code class="text-xs">config/rbac.php</code>:
                                la matrice a database è quella dei default.
                            </td>
                        </tr>
                    </tbody>
                @endif
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
            <h2 class="text-sm font-semibold text-ink">
                <span aria-hidden="true">⚠️</span>
                Permessi non più nel catalogo
            </h2>
            <p class="mt-1 text-xs text-ink-2">
                Esistono a database ma <code>config/rbac.php</code> non li dichiara più. Non si riassegnano
                dalla UI e non si cancellano di qui: vanno rimossi a mano.
            </p>
            {{-- ⚠️ **E il riseeding non li porta via**, che è la domanda
                 immediatamente successiva per chi ha appena letto, due riquadri
                 più su, che quel comando riporta tutto ai default: il seeder usa
                 `firstOrCreate` e non cancella mai ciò che ha smesso di
                 conoscere. È così che i `letture_contaore.*` sono rimasti
                 attaccati a quattro ruoli del database di sviluppo fino all'8
                 Ago 2026. --}}
            <p class="mt-1 text-xs text-ink-2">
                <code>php artisan db:seed --class=RolesAndPermissionsSeeder</code> <strong>non</strong> li rimuove:
                il seeder usa <code>firstOrCreate</code> e non cancella ciò che il catalogo non dichiara più.
                Li <strong>stacca</strong> però da ogni ruolo — <code>syncPermissions()</code> riattacca solo i
                permessi del catalogo — quindi dopo un reset le ✅ qui sotto spariscono e la riga orfana
                resta a database, senza più nessuno che la porti.
            </p>

            <x-ui.card class="mt-3 !p-0 !border-warn-dot">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead class="border-b border-border bg-warn-soft text-xs uppercase tracking-wide text-warn-soft-ink">
                            <tr>
                                <th scope="col" class="py-3 pl-4 pr-3 text-left">Permesso orfano</th>
                                @foreach ($ruoli as $ruolo)
                                    <th scope="col" class="px-3 py-3 text-center">
                                        <span class="whitespace-nowrap">{{ $ruolo }}</span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($orfani as $orfano)
                                <tr wire:key="orfano-{{ $orfano }}" data-orfano="{{ $orfano }}">
                                    <th scope="row" class="py-2 pl-4 pr-3 text-left font-normal">
                                        <span class="font-mono text-xs text-ink">{{ $orfano }}</span>
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

    {{-- ⚠️ **La conferma: due casi, e quello che conta è la CONCESSIONE.**

         L'istinto dice il contrario — concedere è additivo e reversibile,
         revocare toglie accesso a persone vive — e vale solo finché si guardano
         i permessi. Ma `EnsureTwoFactorIsEnabled` gata il secondo fattore **per
         nome di ruolo**, quindi dare un potere forte a un ruolo fuori da
         `Rbac::twoFactorRequiredRoles()` lo consegna a chi entra con la sola
         password, con un click e per tutti i clienti insieme.

         Le revoche chiedono sempre, non perché una singola revoca sia grave ma
         perché nessuna singola revoca *sembra* grave: quattordici click e il
         Tenant non fa più niente. Il numero di persone col ruolo è ciò che
         trasforma la conferma in una decisione invece che in un ostacolo.

         Resta un solo gesto che scrive senza fermarsi: la concessione a un ruolo
         che il secondo fattore ce l'ha già obbligatorio. --}}
    @if ($conferma)
        <x-ui.modal :title="($conferma['concede'] ? 'Concedere' : 'Revocare').' «'.$conferma['permesso'].'» a «'.$conferma['ruolo'].'»?'"
                    close="annulla">
            <div data-conferma="{{ $conferma['concede'] ? 'concessione' : 'revoca' }}">
                <p class="text-sm text-ink-2">
                    @if ($conferma['concede'])
                        <strong>{{ $conferma['ruolo'] }}</strong> otterrà <code class="text-xs">{{ $conferma['permesso'] }}</code>
                        in <strong>tutti gli Enti</strong> della piattaforma.
                    @else
                        <strong>{{ $conferma['ruolo'] }}</strong> perderà <code class="text-xs">{{ $conferma['permesso'] }}</code>
                        in <strong>tutti gli Enti</strong> della piattaforma.
                    @endif
                </p>

                @if ($conferma['concede'] && $conferma['senzaDueFattori'])
                    {{-- Il motivo per cui questa modale esiste anche sulle
                         concessioni. Non è un avviso generico: nomina il
                         meccanismo, perché chi legge deve poter verificare che
                         sia vero. --}}
                    <p class="mt-3 rounded-md bg-warn-soft p-3 text-sm text-warn-soft-ink" data-avviso-2fa="modale">
                        <span aria-hidden="true">⚠️</span>
                        <strong>{{ $conferma['ruolo'] }}</strong> non è fra i ruoli con secondo fattore
                        obbligatorio ({{ implode(', ', App\Support\Rbac::twoFactorRequiredRoles()) }}):
                        il permesso arriverà anche a chi accede con la sola password.
                    </p>
                @endif

                @if (! $conferma['concede'])
                    {{-- ⚠️ **Il numero dice «quanti hanno il ruolo», non «quanti
                         perdono l'accesso»**, e la frase in pagina dev'essere
                         quella vera: un utente con due ruoli conserva il permesso
                         dall'altro. Oggi l'app assegna un ruolo solo, quindi i due
                         numeri coincidono — ma è un fatto di *come sono i dati
                         adesso*, non una proprietà del sistema, e una modale che
                         lo presentasse come «N persone perderanno l'accesso»
                         mentirebbe il giorno in cui i ruoli multipli arrivano,
                         cioè senza che nessuno tocchi questo file. --}}
                    <p class="mt-3 text-sm text-ink-2" data-utenti-col-ruolo="{{ $conferma['utenti'] }}">
                        <strong>{{ $conferma['utenti'] }}</strong>
                        {{ $conferma['utenti'] === 1 ? 'utente ha' : 'utenti hanno' }} oggi il ruolo
                        <strong>{{ $conferma['ruolo'] }}</strong>.
                    </p>
                    <p class="mt-1 text-xs text-ink-3">
                        Non è detto che tutti perdano l'accesso: chi avesse anche un altro ruolo che porta
                        <code class="text-xs">{{ $conferma['permesso'] }}</code> lo conserva.
                    </p>
                @endif

                <p class="mt-3 text-xs text-ink-3">
                    Il gesto è reversibile con un altro click, e resta scritto nel registro di audit
                    col nome di chi l'ha fatto.
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="annulla"
                            class="rounded-md border border-border-strong px-3 py-1.5 text-sm font-medium text-ink hover:bg-surface-sunken">
                        Annulla
                    </button>
                    <button type="button" wire:click="procedi"
                            class="rounded-md px-3 py-1.5 text-sm font-medium {{ $conferma['concede'] ? 'bg-brand text-brand-ink hover:bg-brand-hover' : 'bg-bad-dot text-ink-inverse hover:brightness-90' }}">
                        {{ $conferma['concede'] ? 'Concedi' : 'Revoca' }}
                    </button>
                </div>
            </div>
        </x-ui.modal>
    @endif

</div>

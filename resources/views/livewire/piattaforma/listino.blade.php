{{--
    Il listino dei piani commerciali — 🔗 ADR-035, ADR-002, ADR-032.

    ⚠️ **La colonna «Stripe» è la ragione per cui questa pagina esiste come
    schermata e non come comando.** Il listino a database si potrebbe scrivere
    con tre `INSERT`; ciò che non si scrive a mano è il **confronto** fra quel
    listino e ciò che il cliente paga davvero. La divergenza si mostra coi DUE
    valori affiancati — la stessa forma del marcatore «personalizzato» di
    `/piattaforma/ruoli` — perché dire soltanto «diverge» manda ad aprire la
    dashboard di Stripe per sapere *di quanto*, cioè a rifare a mano metà del
    lavoro che questa pagina toglie.

    ⚠️ **Il confronto NON si fa al render**: è un bottone. Una pagina di
    piattaforma che muore perché un fornitore esterno è giù è la stessa forma di
    difetto per cui `MetrichePiattaforma` non lascia esplodere un piano fuori
    catalogo. Vedi il docblock del componente.

    ⛔ **`name` è la chiave dell'ERROR BAG, non il nome della property.** Il
    campo del codice si chiama `name="codice"` mentre il suo `wire:model` è
    `nuovo.codice`, e la differenza è l'intera ragione per cui gli errori per
    campo si vedono: `x-ui.input` rende il messaggio con `@error($name)`, e
    `GovernoListino` rifiuta con `withMessages(['codice' => …])`. Con
    `name="nuovo.codice"` quell'`@error` non trova **mai** nulla — il blocco
    d'errore del componente diventa codice morto su tutti i campi della
    schermata, e l'unico messaggio superstite resta la card in cima alla pagina,
    cioè **dietro il velo della modale**, su una pagina che nel frattempo è
    scrollata. Chi clicca vede «non è successo niente», che è precisamente il
    difetto che il punto qui sotto dichiara di aver evitato. Livewire lega il
    campo con `wire:model` e non guarda l'attributo `name`: cambiarlo non rompe
    nulla e fa comparire il rifiuto **dentro** la modale, accanto al campo che
    lo ha causato.

    ⚠️ **Gli errori si rendono in pagina e non solo dentro le modali.**
    `GovernoListino` rifiuta con `ValidationException` — fail-closed e rumorosa —
    e i gesti che rifiuta (cambiare il codice, ribaltare la gratuità, archiviare
    il predefinito) sono per costruzione quelli che questa pagina non offre: chi
    li produce non sta usando questa schermata. Se l'errore vivesse solo nella
    modale, una richiesta forgiata a mano tornerebbe **muta**, e il rifiuto si
    leggerebbe come «non è successo niente». È la disciplina di `EditorRuoli`.

    ⚠️ I colori vengono dai **token semantici** (DS §8.1): nessuna tonalità di
    scala e nessuna variante `dark:`, o `SuperficiTokenizzateGuardrailTest`
    diventa rosso.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Piani</h1>
        <p class="mt-1 text-sm text-ink-2">
            Da qui si crea un piano, se ne cambia il prezzo, e lo stesso gesto arriva su Stripe.
        </p>
    </div>

    {{-- Il limite del dato, in cima e non in fondo: chi cambia un prezzo deve
         sapere **prima** che non sta toccando i clienti già abbonati. --}}
    <x-ui.card class="mt-4 border-brand-line bg-brand-soft">
        <p class="text-sm text-ink-2">
            Cambiare prezzo crea un <strong>price nuovo</strong> su Stripe e archivia il vecchio:
            chi è già abbonato <strong>resta al suo</strong> e continua a fatturare la cifra di prima.
            Migrare i clienti esistenti è un gesto diverso, e non si fa da qui.
            Un piano non si cancella mai — si <strong>archivia</strong>, perché
            <code class="text-xs">accounts.piano</code> lo conserva per stringa e cancellarlo
            renderebbe «fuori catalogo» ogni cliente rimasto sopra.
        </p>
    </x-ui.card>

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

    {{-- 🔴 **La conciliazione con Stripe.**

         Tre stati, e sono tre frasi diverse perché mandano a fare tre cose
         diverse: «non confrontato» (il confronto costa chiamate di rete e non si
         fa da solo), «coincidono» (non c'è niente da fare), «N divergenze» (c'è
         una decisione da prendere, e i due valori sono lì per prenderla). Un
         unico messaggio che coprisse i primi due — la tentazione naturale —
         direbbe «tutto a posto» anche quando nessuno ha guardato. --}}
    <x-ui.card class="mt-4"
               data-riconciliazione-stripe="{{ $quanteDivergenze }}"
               data-confronto="{{ $confrontato ? 'fatto' : 'assente' }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                @if (! $confrontato)
                    <p class="text-sm text-ink-2">
                        Il listino <strong>non è stato confrontato</strong> con Stripe in questa pagina.
                        Il confronto è una chiamata di rete per ogni piano a pagamento, quindi non si fa
                        all&rsquo;apertura: così la pagina resta leggibile anche con Stripe irraggiungibile
                        o con le chiavi non configurate.
                    </p>
                @elseif ($quanteDivergenze === 0)
                    <p class="text-sm text-ink-2">
                        Il listino a database <strong>coincide</strong> con Stripe: nessuna divergenza
                        su prodotto, importo o valuta.
                    </p>
                @else
                    <p class="text-sm text-ink">
                        <strong>{{ $quanteDivergenze }}</strong>
                        {{ $quanteDivergenze === 1 ? 'divergenza' : 'divergenze' }} fra il listino e Stripe.
                    </p>
                    <p class="mt-1 text-xs text-ink-2">
                        <span aria-hidden="true">⚠️</span>
                        Nessuna si ripara da sola, in <strong>nessuna</strong> delle due direzioni:
                        il database è la verità per il dominio (etichetta, tetto di Enti, offribilità),
                        Stripe lo è per il denaro. Si risolvono con «Sincronizza» oppure agganciando
                        il price esistente.
                    </p>
                @endif
            </div>

            <x-ui.button variant="secondary" wire:click="confrontaConStripe" class="shrink-0" data-confronta>
                Confronta con Stripe
            </x-ui.button>
        </div>
    </x-ui.card>

    <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-ink">Il listino</h2>

        <x-ui.button wire:click="apriCreazione" data-apri-creazione>Nuovo piano</x-ui.button>
    </div>

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-surface-sunken text-left text-xs uppercase tracking-wide text-ink-3">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3">Piano</th>
                        <th scope="col" class="px-3 py-3">Prezzo di listino</th>
                        <th scope="col" class="px-3 py-3">Enti</th>
                        <th scope="col" class="px-3 py-3">Clienti</th>
                        <th scope="col" class="px-3 py-3">Stripe</th>
                        <th scope="col" class="px-3 py-3 text-right">Azioni</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    @foreach ($piani as $codice => $piano)
                        @php
                            $divergenze = $divergenzePerPiano[$codice] ?? [];
                            $corrente = $piano->prezzi->firstWhere('corrente', true);
                            $storici = $piano->prezzi->count() - ($corrente === null ? 0 : 1);
                        @endphp

                        <tr wire:key="piano-{{ $piano->id }}" class="align-top hover:bg-surface-sunken"
                            data-piano="{{ $codice }}">

                            <td class="py-3 pl-4 pr-3">
                                <span class="font-medium text-ink">{{ $piano->etichetta }}</span>
                                {{-- Il codice sotto, in monospazio: è la stringa
                                     che vive in `accounts.piano`, ed è quella che
                                     si cerca quando qualcosa non torna. --}}
                                <span class="mt-0.5 block font-mono text-xs text-ink-3">{{ $codice }}</span>

                                <span class="mt-1 flex flex-wrap gap-1">
                                    @if ($piano->gratuito)
                                        <x-ui.badge variant="neutral">gratuito</x-ui.badge>
                                    @endif
                                    @if ($codice === $predefinito)
                                        {{-- Detto **prima** del click: è il piano
                                             con cui nasce ogni account e quello a
                                             cui torna chi disdice, quindi
                                             archiviarlo è rifiutato. Scoprirlo
                                             dall'errore sarebbe scoprirlo tardi. --}}
                                        <x-ui.badge variant="info">predefinito</x-ui.badge>
                                    @endif
                                    @if (! $piano->attivo)
                                        <x-ui.badge variant="warning">archiviato</x-ui.badge>
                                    @endif
                                </span>
                            </td>

                            <td class="px-3 py-3 text-ink-2 tabular-nums">
                                {{ number_format($piano->prezzo_mensile_cent / 100, 2, ',', '.') }}
                                {{ mb_strtoupper($piano->valuta) }}
                                <span class="mt-0.5 block text-xs text-ink-3">al mese</span>
                            </td>

                            <td class="px-3 py-3 text-ink-2 tabular-nums">
                                {{-- `null` = **illimitato**, non «non lo so»: è la
                                     forma che un piano Enterprise avrebbe. --}}
                                {{ $piano->max_enti === null ? 'illimitati' : $piano->max_enti }}
                            </td>

                            <td class="px-3 py-3 text-ink-2 tabular-nums" data-clienti="{{ $clientiPerPiano[$codice] ?? 0 }}">
                                {{ $clientiPerPiano[$codice] ?? 0 }}
                            </td>

                            <td class="px-3 py-3 text-ink-2">
                                @if ($piano->gratuito)
                                    {{-- Non è un buco da riempire: un piano
                                         omaggiato non ha né customer né
                                         subscription (ADR-002), quindi su Stripe
                                         non c'è niente e non ci deve essere. --}}
                                    <span class="text-xs text-ink-3">niente, per definizione</span>
                                @else
                                    @if ($piano->stripe_sincronizzato_at)
                                        <x-ui.badge variant="success">sincronizzato</x-ui.badge>
                                        <span class="mt-0.5 block text-xs text-ink-3">
                                            {{ $piano->stripe_sincronizzato_at->format('d/m/Y H:i') }}
                                        </span>
                                    @else
                                        <x-ui.badge variant="warning">da sincronizzare</x-ui.badge>
                                    @endif

                                    <span class="mt-1 block font-mono text-xs text-ink-3">
                                        {{ $corrente?->stripe_price_id ?? 'nessun price' }}
                                    </span>

                                    @if ($storici > 0)
                                        {{-- Lo storico non è spazzatura: è ciò su
                                             cui i clienti già abbonati continuano
                                             a fatturare, e la sola strada con cui
                                             il webhook riconosce ancora il loro
                                             piano (`Piani::perPrice()`). --}}
                                        <span class="mt-0.5 block text-xs text-ink-3">
                                            + {{ $storici }} {{ $storici === 1 ? 'price storico' : 'price storici' }}
                                        </span>
                                    @endif

                                    @if ($piano->stripe_ultimo_errore)
                                        {{-- Per esteso, e non «errore»: il
                                             fallimento della sincronizzazione è
                                             visibile e non silenzioso — la riga
                                             locale resta valida, ed è il testo di
                                             Stripe a dire cosa fare. --}}
                                        <span class="mt-1 block text-xs text-bad-soft-ink" data-errore-stripe>
                                            {{ $piano->stripe_ultimo_errore }}
                                        </span>
                                    @endif

                                    @if (isset($linkDiPagamento[$codice]))
                                        {{-- 🔴 Il **Payment Link di Stripe**:
                                             chi lo apre paga E ottiene
                                             l'account, perché Stripe raccoglie
                                             ragione sociale e referente e il
                                             webhook li usa per provisionare.
                                             Vedi `LinkDiPagamento`. --}}
                                        <span class="mt-2 flex items-center gap-1.5"
                                              x-data="{ copiato: false }">
                                            <a href="{{ $linkDiPagamento[$codice] }}" target="_blank" rel="noopener"
                                               class="truncate text-xs text-brand underline underline-offset-2"
                                               data-link-pagamento="{{ $codice }}"
                                               title="{{ $linkDiPagamento[$codice] }}">
                                                link per pagare
                                            </a>
                                            <button type="button"
                                                    class="shrink-0 rounded px-1.5 py-0.5 text-xs text-ink-3 hover:bg-surface-sunken hover:text-ink-2"
                                                    x-on:click="navigator.clipboard.writeText(@js($linkDiPagamento[$codice])); copiato = true; setTimeout(() => copiato = false, 1500)">
                                                <span x-show="! copiato">copia</span>
                                                <span x-show="copiato" x-cloak>copiato</span>
                                            </button>
                                        </span>
                                    @elseif ($piano->prezzo_mensile_cent > 0)
                                        {{-- 🔴 **Detta, non taciuta.** Una cella
                                             vuota qui avrebbe significato tre
                                             cose diverse — link mai creato,
                                             Stripe irraggiungibile al momento
                                             della sincronizzazione, difetto del
                                             codice — con la risposta da cercare
                                             altrove. È costato mezz'ora il
                                             giorno del primo rilascio, e la
                                             lezione vale anche per questa
                                             versione. --}}
                                        <span class="mt-2 block text-xs text-warn-soft-ink" data-senza-link>
                                            Nessun link di pagamento: premere «Sincronizza».
                                        </span>
                                    @endif

                                    @foreach ($divergenze as $divergenza)
                                        {{-- 🔴 **I due valori affiancati**, non
                                             il solo fatto che divergano: vedi il
                                             commento in testa al file. --}}
                                        <span class="mt-1 block text-xs font-medium leading-tight text-brand"
                                              data-divergente="{{ $divergenza['campo'] }}"
                                              title="Il listino a database e Stripe non dicono la stessa cosa. Nessuno dei due vince da solo.">
                                            divergente su {{ $divergenza['campo'] }}:
                                            listino {{ $divergenza['locale'] }} &rarr;
                                            Stripe {{ $divergenza['remoto'] }}
                                        </span>
                                    @endforeach
                                @endif
                            </td>

                            <td class="px-3 py-3">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-ui.button variant="secondary" class="!px-3 !py-1.5"
                                                 wire:click="apriModifica({{ $piano->id }})">
                                        Modifica
                                    </x-ui.button>

                                    @if (! $piano->gratuito)
                                        <x-ui.button variant="secondary" class="!px-3 !py-1.5"
                                                     wire:click="sincronizza({{ $piano->id }})">
                                            Sincronizza
                                        </x-ui.button>

                                        <x-ui.button variant="ghost" class="!px-3 !py-1.5"
                                                     wire:click="apriAggancio({{ $piano->id }})">
                                            Aggancia un price
                                        </x-ui.button>
                                    @endif

                                    @if ($piano->attivo)
                                        {{-- Il predefinito non si archivia, e il
                                             bottone non c'è: offrire un gesto che
                                             verrà rifiutato è un invito a
                                             bussare. La guardia vera resta
                                             comunque in `GovernoListino`, perché
                                             questa è presentazione e la
                                             presentazione non protegge niente. --}}
                                        @if ($codice !== $predefinito)
                                            <x-ui.button variant="ghost" class="!px-3 !py-1.5"
                                                         wire:click="archivia({{ $piano->id }})">
                                                Archivia
                                            </x-ui.button>
                                        @endif
                                    @else
                                        <x-ui.button variant="ghost" class="!px-3 !py-1.5"
                                                     wire:click="riattiva({{ $piano->id }})">
                                            Riattiva
                                        </x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- ─── I piani fuori catalogo ─────────────────────────────────────────

         🔴 **La striscia in fondo, sulla forma di quella degli orfani di
         `/piattaforma/ruoli`.** Sono codici che vivono in `accounts.piano` e che
         il listino non conosce: `accounts.piano` è una stringa **senza FK e
         senza CHECK**, la scrivono il webhook di Stripe e i comandi di console,
         e un cliente può restare su un piano dismesso. Non è un caso teorico —
         ADR-035 lo dichiara già esistente e governato, `ElencaClienti` gli dedica
         un filtro e `MetrichePiattaforma` li conta a **0 € di MRR**.

         ⚠️ **Questa è l&rsquo;unica schermata da cui quel dato si ripara**, e
         senza la striscia non ne mostrerebbe traccia: chi apre il listino per
         capire perché l&rsquo;MRR non torna vedrebbe un catalogo perfettamente
         sano, e chi lo scopre dalla cabina non avrebbe da lì nessuna strada
         verso il posto in cui si aggiusta.

         Fuori dalla tabella e non fra le righe, per la ragione degli orfani dei
         permessi: mescolarli ai piani veri li legittimerebbe come piani. E il
         blocco **non compare affatto** quando non ce n&rsquo;è nessuno, che è il
         caso normale — una sezione vuota permanente insegna a non guardarla. --}}
    @if ($fuoriCatalogo !== [])
        <div class="mt-8" data-fuori-catalogo>
            <h2 class="text-sm font-semibold text-ink">
                <span aria-hidden="true">⚠️</span>
                Piani presenti sui clienti ma non a listino
            </h2>
            <p class="mt-1 text-xs text-ink-2">
                <code class="text-xs">accounts.piano</code> conserva il piano per <strong>stringa</strong>,
                senza vincolo: questi codici sono su dei clienti veri e non esistono qui.
                Valgono <strong>0 €</strong> nel ricavo della cabina, e nessuna delle due schermate
                può indovinare a quale piano vadano ricondotti.
            </p>
            <p class="mt-1 text-xs text-ink-2">
                Si riparano in un modo solo: creando qui il piano con <strong>quello stesso codice</strong>
                — che è immutabile apposta — oppure spostando quei clienti su un piano a listino.
            </p>

            <x-ui.card class="mt-3 !p-0 !border-warn-dot">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-border bg-warn-soft text-left text-xs uppercase tracking-wide text-warn-soft-ink">
                            <tr>
                                <th scope="col" class="py-3 pl-4 pr-3">Codice fuori catalogo</th>
                                <th scope="col" class="px-3 py-3">Clienti</th>
                                <th scope="col" class="px-3 py-3 text-right">In cabina</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($fuoriCatalogo as $codiceOrfano => $quanti)
                                <tr wire:key="fuori-{{ $codiceOrfano }}" data-fuori-catalogo-codice="{{ $codiceOrfano }}">
                                    <th scope="row" class="py-2 pl-4 pr-3 text-left font-normal">
                                        <span class="font-mono text-xs text-ink">{{ $codiceOrfano }}</span>
                                    </th>
                                    <td class="px-3 py-2 tabular-nums text-ink-2" data-fuori-catalogo-clienti="{{ $quanti }}">
                                        {{ $quanti }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        {{-- Il filtro della cabina, non una ricerca a mano: la
                                             sentinella `__fuori_catalogo` è una costante, e
                                             ribatterla a stringa qui sarebbe la seconda copia da
                                             tenere allineata. Gatato sul permesso della cabina,
                                             che è una pagina diversa da questa.

                                             ⚠️ Si legge da `Cabina` e NON dal trait che la
                                             dichiara: dal 8.2 una costante di trait non è
                                             raggiungibile per nome del trait, e il sintomo è
                                             un Error a runtime dentro la vista compilata. --}}
                                        @can(App\Support\Tenancy\VistaPiattaforma::PERMESSO)
                                            <a href="{{ route('piattaforma.index', ['piano' => App\Livewire\Piattaforma\Cabina::FUORI_CATALOGO]) }}"
                                               class="text-sm font-medium text-brand underline hover:no-underline">
                                                Mostra i clienti
                                            </a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- ─── Creazione ─────────────────────────────────────────────────────── --}}

    @if ($creazioneAperta)
        <x-ui.modal title="Nuovo piano" close="chiudiCreazione">
            <div class="space-y-4" data-form-creazione>
                <x-ui.input name="codice" label="Codice" wire:model="nuovo.codice"
                            placeholder="es. enterprise" />
                <p class="-mt-3 text-xs text-ink-3">
                    Minuscole, cifre e underscore. È la stringa che finisce in
                    <code class="text-xs">accounts.piano</code> di ogni cliente su questo piano, e
                    <strong>non si potrà più cambiare</strong>: rinominarla li renderebbe tutti fuori catalogo.
                </p>

                <x-ui.input name="etichetta" label="Etichetta" wire:model="nuovo.etichetta"
                            placeholder="es. Enterprise" />

                <x-ui.input name="max_enti" label="Tetto di Enti" type="number" wire:model="nuovo.max_enti"
                            placeholder="vuoto = illimitato" />

                <x-ui.input name="prezzo_mensile_cent" label="Prezzo mensile, in centesimi interi"
                            type="number" wire:model="nuovo.prezzo_mensile_cent" />
                <p class="-mt-3 text-xs text-ink-3">
                    Centesimi e mai euro con la virgola: un totale su decine di clienti in virgola mobile
                    accumula errore, ed è la riga che nessuno rilegge. 4900 = 49,00.
                </p>

                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" wire:model="nuovo.gratuito"
                           class="mt-0.5 rounded border border-border-strong bg-surface text-brand focus:ring-ring">
                    <span>
                        Piano <strong>gratuito</strong>: nessun customer e nessuna subscription su Stripe (ADR-002).
                        <span class="mt-0.5 block text-xs text-ink-3">
                            Non è «costa zero»: un piano a pagamento a 0 € — una promozione — ha una
                            subscription vera e va lasciato senza spunta. Anche questo non si potrà più cambiare.
                        </span>
                    </span>
                </label>

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="chiudiCreazione">Annulla</x-ui.button>
                    <x-ui.button wire:click="crea" data-crea>Crea, e sincronizza su Stripe</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    {{-- ─── Modifica ──────────────────────────────────────────────────────── --}}

    @if ($pianoInModifica && ! $conferma)
        <x-ui.modal title="Modifica il piano" close="chiudiModifica">
            <div class="space-y-4" data-form-modifica>
                <p class="text-sm text-ink-2">
                    Il <strong>codice</strong> e la <strong>gratuità</strong> non compaiono qui perché non si
                    cambiano dopo la creazione: il primo vive in <code class="text-xs">accounts.piano</code>,
                    la seconda marcherebbe come paganti dei clienti senza subscription. Per l&rsquo;altro
                    comportamento si crea un piano nuovo.
                </p>

                <x-ui.input name="etichetta" label="Etichetta" wire:model="modifica.etichetta" />

                <x-ui.input name="max_enti" label="Tetto di Enti" type="number"
                            wire:model="modifica.max_enti" placeholder="vuoto = illimitato" />

                <x-ui.input name="prezzo_mensile_cent" label="Prezzo mensile, in centesimi interi"
                            type="number" wire:model="modifica.prezzo_mensile_cent" />
                <p class="-mt-3 text-xs text-ink-3">
                    Cambiare la cifra marca il piano «da sincronizzare»: il price nuovo nasce alla
                    sincronizzazione, e chi è già abbonato resta sul suo.
                </p>

                <x-ui.input name="ordine" label="Ordine nel listino" type="number"
                            wire:model="modifica.ordine" />

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="chiudiModifica">Annulla</x-ui.button>
                    <x-ui.button wire:click="salva" data-salva>Salva</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    {{-- ─── La conferma del tetto abbassato ───────────────────────────────── --}}

    {{-- ⚠️ **Non è una guardia: è un&rsquo;informazione data prima del click.**
         Abbassare il tetto è permesso e non cestina niente — chi ci finisce
         sotto tiene tutte le sue sedi e semplicemente non ne apre altre
         (grandfathering, ADR-032). Ciò che mancherebbe senza questa modale è
         **sapere quanti** ci finiscono, che è l&rsquo;unica cosa che trasforma
         il gesto in una decisione. --}}
    @if ($conferma)
        <x-ui.modal :title="'Abbassare il tetto di Enti di «'.$conferma['codice'].'»?'" close="annulla">
            <div data-conferma-tetto="{{ $conferma['account'] }}">
                <p class="text-sm text-ink-2">
                    Il tetto passa da <strong>{{ $conferma['da'] }}</strong> a
                    <strong>{{ $conferma['a'] }}</strong> Enti.
                </p>

                <p class="mt-3 rounded-md bg-warn-soft p-3 text-sm text-warn-soft-ink">
                    <span aria-hidden="true">⚠️</span>
                    <strong>{{ $conferma['account'] }}</strong>
                    {{ $conferma['account'] === 1 ? 'cliente si troverà' : 'clienti si troveranno' }}
                    sopra il limite.
                </p>

                <p class="mt-3 text-sm text-ink-2">
                    Nessuna sede viene chiusa e nessun account viene bloccato: chi è sopra il tetto
                    tiene tutto ciò che ha e semplicemente <strong>non ne apre altre</strong> finché
                    non risale di piano.
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="annulla">Annulla</x-ui.button>
                    <x-ui.button wire:click="procedi" data-procedi>Abbassa il tetto</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    {{-- ─── Aggancia un price esistente ───────────────────────────────────── --}}

    @if ($pianoInAggancio)
        <x-ui.modal title="Aggancia un price esistente" close="chiudiAggancio">
            <div class="space-y-4" data-form-aggancio>
                <p class="text-sm text-ink-2">
                    La via d&rsquo;uscita per un piano nato <strong>senza</strong> price id — succede se
                    <code class="text-xs">STRIPE_PRICE_SAAS</code> mancava al momento del deploy, perché su
                    Cloud la config è cachata in build e la migration di backfill gira dopo.
                    Crearne uno nuovo duplicherebbe il prodotto su Stripe.
                </p>

                <x-ui.input name="price_id" label="Price id su Stripe" wire:model="priceId"
                            placeholder="price_..." />

                <p class="-mt-3 text-xs text-ink-3">
                    Importo e valuta devono combaciare col listino: agganciare un price da 99 € a un piano
                    che questa pagina dichiara da 49 € fatturerebbe una cifra che nessuna schermata mostra.
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="chiudiAggancio">Annulla</x-ui.button>
                    <x-ui.button wire:click="aggancia" data-aggancia>Aggancia</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

</div>

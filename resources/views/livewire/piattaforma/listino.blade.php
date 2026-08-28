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

    {{-- ─── Creazione ─────────────────────────────────────────────────────── --}}

    @if ($creazioneAperta)
        <x-ui.modal title="Nuovo piano" close="chiudiCreazione">
            <div class="space-y-4" data-form-creazione>
                <x-ui.input name="nuovo.codice" label="Codice" wire:model="nuovo.codice"
                            placeholder="es. enterprise" />
                <p class="-mt-3 text-xs text-ink-3">
                    Minuscole, cifre e underscore. È la stringa che finisce in
                    <code class="text-xs">accounts.piano</code> di ogni cliente su questo piano, e
                    <strong>non si potrà più cambiare</strong>: rinominarla li renderebbe tutti fuori catalogo.
                </p>

                <x-ui.input name="nuovo.etichetta" label="Etichetta" wire:model="nuovo.etichetta"
                            placeholder="es. Enterprise" />

                <x-ui.input name="nuovo.max_enti" label="Tetto di Enti" type="number" wire:model="nuovo.max_enti"
                            placeholder="vuoto = illimitato" />

                <x-ui.input name="nuovo.prezzo_mensile_cent" label="Prezzo mensile, in centesimi interi"
                            type="number" wire:model="nuovo.prezzo_mensile_cent" />
                <p class="-mt-3 text-xs text-ink-3">
                    Centesimi e mai euro con la virgola: un totale su decine di clienti in virgola mobile
                    accumula errore, ed è la riga che nessuno rilegge. 4900 = 49,00.
                </p>

                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" wire:model="nuovo.gratuito"
                           class="mt-0.5 rounded border-border-strong bg-surface text-brand focus:ring-ring">
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

                <x-ui.input name="modifica.etichetta" label="Etichetta" wire:model="modifica.etichetta" />

                <x-ui.input name="modifica.max_enti" label="Tetto di Enti" type="number"
                            wire:model="modifica.max_enti" placeholder="vuoto = illimitato" />

                <x-ui.input name="modifica.prezzo_mensile_cent" label="Prezzo mensile, in centesimi interi"
                            type="number" wire:model="modifica.prezzo_mensile_cent" />
                <p class="-mt-3 text-xs text-ink-3">
                    Cambiare la cifra marca il piano «da sincronizzare»: il price nuovo nasce alla
                    sincronizzazione, e chi è già abbonato resta sul suo.
                </p>

                <x-ui.input name="modifica.ordine" label="Ordine nel listino" type="number"
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

                <x-ui.input name="priceId" label="Price id su Stripe" wire:model="priceId"
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

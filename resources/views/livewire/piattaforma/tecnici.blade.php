{{--
    I tecnici di EasyLab e i clienti su cui lavorano (🔗 ADR-038, ADR-007/030).

    ⚠️ **Le due modali stanno DENTRO il `<div>` di radice.** Livewire ritrova il
    proprio frammento dalla radice: tutto ciò che sta dopo la sua chiusura viene
    scartato **in silenzio** dal browser, mentre un `Livewire::test()` resta
    verde perché il renderer di prova restituisce tutto l'output.
    `RadiceLivewireGuardrailTest` è la rete, e nasce da una modale scritta fuori.

    ⚠️ **Solo token semantici** (🔗 ADR-034, DS §8): `bg-surface` e non
    `bg-white`, `text-ink-2` e non `text-gray-500`. Una classe di scala fa
    diventare rosso `SuperficiTokenizzateGuardrailTest` — e in tema scuro darebbe
    una card bianca sul fondo blu notte, senza che niente sia «rotto».
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
    <x-piattaforma.nav />

    <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Tecnici e gestori</h1>
            <p class="mt-1 text-sm text-ink-2">
                Le persone di EasyLab che lavorano sulle macchine dei clienti, e su quali clienti lavora ciascuna.
                Il tecnico esegue gli interventi; il gestore tiene anche l'anagrafica e la pianificazione.
            </p>
        </div>

        <x-ui.button wire:click="apriInvito">+ Invita una persona</x-ui.button>
    </div>

    @if (session('tecnici'))
        {{-- L'esito del gesto appena fatto. La tinta segue `tecniciFallito`: un
             invito che non è partito non deve leggersi come un successo. --}}
        <div @class([
            'mt-4 rounded-md border px-3 py-2 text-sm',
            'border-bad-dot bg-bad-soft text-bad-soft-ink' => session('tecniciFallito'),
            'border-ok-dot bg-ok-soft text-ok-soft-ink' => ! session('tecniciFallito'),
        ])>{{ session('tecnici') }}</div>
    @endif

    {{-- 🔴 Cosa significa davvero questa pagina, prima della tabella. Il
         portafoglio non è una nota organizzativa: apre accessi. Lo dice anche la
         modale, dov'è il gesto — qui serve a chi sta solo leggendo l'elenco. --}}
    <x-ui.card class="mt-4 !border-brand-line !bg-brand-soft">
        <p class="text-sm text-ink-2">
            Un tecnico di EasyLab non appartiene a nessun cliente: raggiunge le macchine
            <strong>solo</strong> dei clienti che gli vengono assegnati qui. Aggiungere una sede al suo
            portafoglio gli apre <strong>tutte le macchine di quella sede</strong> e lo fa comparire nella
            tendina «Assegnatario» di quel cliente; toglierla richiude entrambe le porte, subito.
        </p>
    </x-ui.card>

    <div class="mt-8 flex flex-wrap items-end gap-4">
        <div class="min-w-56 grow sm:grow-0">
            <label for="tecnici-search" class="block text-sm font-medium text-ink-2">Cerca</label>
            <input id="tecnici-search" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Nome o email"
                   class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
        </div>

        <label class="flex items-center gap-2 pb-2 text-sm text-ink-2">
            <input type="checkbox" wire:model.live="conCestinati"
                   class="h-4 w-4 rounded border border-border-strong bg-surface text-brand focus:ring-2 focus:ring-ring">
            Mostra anche i cestinati
        </label>
    </div>

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            {{-- `tabella-a-card` — 🔗 DS §7, `@utility` in `app.css`, la stessa
                 forma di `/utenti`. Le viste da scrivania possono deviare
                 **dichiarandolo** (lo fa la cabina di regia), ma la deviazione è
                 pensata per le tabelle a riga espandibile: qui le colonne sono
                 quattro e nessuna si apre, quindi non c'è niente da comprare in
                 cambio di una striscia da scorrere di lato. --}}
            <table class="tabella-a-card w-full text-sm">
                <thead class="border-b border-border bg-surface-sunken text-left text-xs uppercase tracking-wide text-ink-3">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3">Persona</th>
                        <th scope="col" class="px-3 py-3">Stato</th>
                        <th scope="col" class="px-3 py-3">Sedi in portafoglio</th>
                        <th scope="col" class="px-3 py-3 text-right">Azioni</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    @forelse ($tecnici as $tecnico)
                        <tr wire:key="tecnico-{{ $tecnico->id }}" class="align-top hover:bg-surface-sunken">
                            {{-- ⚠️ Una figlia diretta sola (DS §7): in modalità card il
                                 `<td>` diventa flex, e nome ed email finirebbero
                                 affiancati invece che uno sotto l'altro. --}}
                            <td class="py-3 pl-4 pr-3" data-etichetta="Persona">
                                <div>
                                    <span class="font-medium text-ink">{{ $tecnico->name }}</span>
                                    {{-- Il ruolo accanto al nome (🔗 ADR-046): le
                                         due figure hanno lo stesso portafoglio e
                                         poteri diversi, e chi assegna una sede
                                         deve vedere a chi la sta aprendo. --}}
                                    <span class="ml-1 text-xs text-ink-2" data-ruolo-persona="{{ $tecnico->id }}">{{ $tecnico->getRoleNames()->implode(', ') }}</span>
                                    <span class="mt-0.5 block text-xs text-ink-3">{{ $tecnico->email }}</span>
                                </div>
                            </td>

                            <td class="px-3 py-3" data-etichetta="Stato">
                                <div>
                                {{-- «Invitato» non è una colonna: è
                                     `email_verified_at IS NULL` (🔗 ADR-012),
                                     cioè la password tappo mai sostituita. --}}
                                @if ($tecnico->trashed())
                                    <x-ui.badge variant="danger">Cestinato</x-ui.badge>
                                    <span class="mt-0.5 block text-xs text-ink-3">non entra e non è assegnabile</span>
                                @elseif ($tecnico->email_verified_at === null)
                                    <x-ui.badge variant="warning">Invitato, mai entrato</x-ui.badge>
                                @else
                                    <x-ui.badge variant="success">Attivo</x-ui.badge>
                                @endif
                                </div>
                            </td>

                            <td class="px-3 py-3 text-ink-2 tabular-nums" data-etichetta="Sedi in portafoglio">
                                @php($quanti = $clientiPerTecnico[$tecnico->id] ?? 0)
                                @if ($quanti === 0)
                                    {{-- Il vuoto dice la conseguenza, non solo l'assenza:
                                         un tecnico senza portafoglio esiste, entra, e non
                                         trova niente. --}}
                                    <span class="text-ink-3">Nessuna — non vede nessuna macchina</span>
                                @else
                                    <span>{{ $quanti }} {{ $quanti === 1 ? 'sede' : 'sedi' }}</span>
                                @endif
                            </td>

                            <td class="px-3 py-3" data-azioni>
                                <div class="flex flex-wrap justify-end gap-2 max-md:justify-start">
                                    @if ($tecnico->trashed())
                                        <x-ui.button variant="secondary" wire:click="ripristina({{ $tecnico->id }})">
                                            Ripristina
                                        </x-ui.button>
                                    @else
                                        <x-ui.button variant="secondary" wire:click="apriPortafoglio({{ $tecnico->id }})">
                                            Clienti e accessi
                                        </x-ui.button>
                                        <x-ui.button variant="ghost" wire:click="cestina({{ $tecnico->id }})">
                                            Cestina
                                        </x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-ink-3">
                                @if (trim($search) !== '')
                                    Nessun tecnico con questa ricerca.
                                @else
                                    Nessun tecnico o gestore di EasyLab. Il primo si crea da «+ Invita una persona».
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4">
        {{ $tecnici->links() }}
    </div>

    {{-- ─── Modale: invita un tecnico ──────────────────────────────────── --}}
    @if ($invitoAperto)
        <x-ui.modal title="Invita una persona di EasyLab" close="chiudiInvito">
            {{-- Stesso ordine della modale d'invito di `/utenti`: cosa succede,
                 poi i campi, poi la conferma. --}}
            <p class="text-sm text-ink-2">
                Riceverà un'email con un link valido sette giorni, da cui sceglie la propria password.
                Nasce con <strong class="text-ink">nessun Ente</strong>: è la forma che le permette di lavorare
                su più clienti. Non ha ancora accesso a nulla — i clienti si scelgono dopo, con «Clienti e accessi».
            </p>

            <form wire:submit="invitaTecnico" class="mt-4 space-y-4">
                <x-ui.input label="Nome e cognome" name="nuovo.nome" wire:model="nuovo.nome"
                            placeholder="Giulia Verdi" />
                <x-ui.input label="Indirizzo email" name="nuovo.email" type="email" wire:model="nuovo.email"
                            placeholder="giulia.verdi@easylab.it" />

                <div>
                    <label for="figura" class="block text-sm font-medium text-ink">Figura</label>
                    <select id="figura" wire:model.live="nuovoRuolo"
                            class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                        @foreach ($this->ruoliConferibili() as $conferibile)
                            <option value="{{ $conferibile }}">{{ $conferibile }}</option>
                        @endforeach
                    </select>
                    {{-- Cosa cambia fra le due, detto **prima** del gesto: hanno lo
                         stesso portafoglio, e la differenza non si vede dal nome. --}}
                    <p class="mt-2 text-sm text-ink-2" data-figura="{{ $nuovoRuolo }}">
                        @if ($nuovoRuolo === \App\Models\User::GESTORE_ROLE)
                            Il <strong class="text-ink">Gestore</strong> lavora sui clienti che gli assegni come farebbe il loro
                            responsabile: registra macchine, crea reparti, pianifica, assegna e chiude interventi.
                            Non elimina reparti e non vede utenti, abbonamento e piattaforma.
                        @else
                            Il <strong class="text-ink">Tecnico</strong> vede le macchine dei clienti che gli assegni e chiude
                            gli interventi: non registra macchine e non pianifica.
                        @endif
                    </p>
                    @if ($this->imponeSecondoFattore($nuovoRuolo))
                        <p class="mt-2 rounded-md border border-warn-dot bg-warn-soft px-3 py-2 text-sm text-warn-soft-ink">
                            Questo ruolo richiede il secondo fattore. Al primo accesso le sarà chiesto di
                            configurarlo e non potrà fare altro finché non l'avrà fatto: avvisala, e assicurati
                            che abbia con sé il telefono con cui lo userà.
                        </p>
                    @endif
                </div>

                {{-- L'errore su un ruolo forzato dal browser (🔗 ADR-038) ha un
                     posto in cui leggersi, invece di sembrare un invio riuscito. --}}
                @error('nuovoRuolo')
                    <p class="text-sm text-bad-soft-ink">{{ $message }}</p>
                @enderror

                @if ($ripristinabile)
                    {{-- Il codice `UTENTE_CESTINATO` non è un errore secco:
                         quell'indirizzo è occupato da una persona nel cestino
                         (l'unique su `users.email` è senza condizione), e la
                         cosa giusta da offrire è rimetterla in servizio. --}}
                    <div class="rounded-md border border-warn-dot bg-warn-soft p-3">
                        <p class="text-sm text-warn-soft-ink">
                            Quella persona è nel cestino. Ripristinarla le ridà l'accesso e il portafoglio che aveva.
                        </p>
                        <x-ui.button variant="secondary" class="mt-3" wire:click="ripristina({{ $ripristinabile }})">
                            Ripristina questa persona
                        </x-ui.button>
                    </div>
                @endif

                <div class="flex justify-end gap-3 pt-2">
                    <x-ui.button variant="secondary" wire:click="chiudiInvito">Annulla</x-ui.button>
                    <x-ui.button type="submit">Invita</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- ─── Modale: il portafoglio clienti ─────────────────────────────── --}}
    @if ($tecnicoDelPortafoglio)
        <x-ui.modal :title="'Portafoglio di '.$tecnicoDelPortafoglio->name" close="chiudiPortafoglio">
            {{-- 🔴 L'avviso sta QUI, accanto alle caselle: è il punto in cui
                 qualcuno sta conferendo un accesso, e ADR-038 chiede che lo
                 sappia mentre lo fa — non da una guida. --}}
            <div class="rounded-md border border-warn-dot bg-warn-soft p-3">
                <p class="text-sm text-warn-soft-ink">
                    Spuntare una sede apre a {{ $tecnicoDelPortafoglio->name }} <strong>tutte le macchine di
                    quella sede</strong> e lo fa comparire nella tendina «Assegnatario» di quel cliente.
                    Togliere la spunta richiude tutto, <strong>subito</strong>.
                </p>
            </div>

            <p class="mt-4 text-sm text-ink-2">
                Si sceglie <strong class="text-ink">sede per sede</strong>, non per contratto: un cliente con
                tre sedi va spuntato tre volte.
            </p>

            <div class="mt-4 max-h-96 space-y-4 overflow-y-auto pr-1">
                @forelse ($sediPerCliente as $cliente => $sedi)
                    <div wire:key="cliente-{{ $loop->index }}">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-3">{{ $cliente }}</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($sedi as $sede)
                                <label wire:key="sede-{{ $sede->id }}"
                                       class="flex items-start gap-3 rounded-md border border-border bg-surface-sunken px-3 py-2">
                                    <input type="checkbox" value="{{ $sede->id }}" wire:model="portafoglioSedi"
                                           class="mt-0.5 h-4 w-4 rounded border border-border-strong bg-surface text-brand focus:ring-2 focus:ring-ring">
                                    <span class="text-sm text-ink">{{ $sede->nome }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-3">
                        Nessuna sede cliente sulla piattaforma: non c'è ancora niente su cui mettere un tecnico.
                    </p>
                @endforelse
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="chiudiPortafoglio">Annulla</x-ui.button>
                <x-ui.button wire:click="salvaPortafoglio">Salva il portafoglio</x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</div>

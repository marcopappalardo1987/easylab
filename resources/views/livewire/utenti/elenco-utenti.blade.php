{{-- Persone dell'Ente (🔗 ADR-038).

     ⚠️ Ogni modale di questa vista sta DENTRO il `<div>` di radice: ciò che sta
     dopo la radice di un componente Livewire viene scartato in silenzio dal
     browser, e un `Livewire::test()` resterebbe verde. `RadiceLivewireGuardrailTest`
     lo rende rosso, e il file deve finire con un tag di chiusura a colonna zero.

     Solo token semantici, nessuna variante `dark:`: il tema si scambia sotto
     (🔗 DS §8.1) e `SuperficiTokenizzateGuardrailTest` sorveglia le classi di
     scala. --}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Persone di questo Ente</h1>
            <p class="mt-1 text-sm text-ink-2">
                Chi ha accesso a questo Ente, con quale ruolo, e chi è stato invitato ma non è ancora entrato.
            </p>
        </div>

        @can('utenti.create')
            <x-ui.button wire:click="apriInvito">+ Invita una persona</x-ui.button>
        @endcan
    </div>

    {{-- ⚠️ Il bordo porta un colore vero e non `transparent`: la superficie tenue
         dell'esito, in tema chiaro, sta a un soffio dal fondo della pagina, e una
         striscia senza contorno smette di leggersi come un blocco a sé. È la
         stessa forma usata su `/piattaforma/tecnici`. --}}
    @if ($notice)
        <div class="mt-4 rounded-md border border-ok-dot bg-ok-soft px-3 py-2 text-sm text-ok-soft-ink">
            {{ $notice }}
        </div>
    @endif

    {{-- 🔴 L'errore in pagina e non solo nel campo: i rifiuti di questa
         schermata («è l'unico Admin», «è nel cestino») non appartengono a un
         input — sono lo stato dell'Ente — e chi li legge deve poter fare il
         gesto che li scioglie, che qui è il pulsante di ripristino. --}}
    {{-- ⚠️ `! $showInvito`: lo stesso errore ha già il suo posto DENTRO la
         modale, accanto ai campi da cui nasce. Senza questa condizione la
         pagina mostrerebbe due volte lo stesso testo e — peggio — due pulsanti
         «Ripristinala», uno dei quali sepolto sotto il velo della modale. --}}
    @if ($errore && ! $showInvito)
        <div class="mt-4 rounded-md border border-bad-dot bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
            {{ $errore }}

            @if ($ripristinabile)
                <button type="button" wire:click="ripristina({{ $ripristinabile }})"
                        class="ml-1 inline-flex min-h-11 items-center font-semibold underline underline-offset-2 hover:no-underline">
                    Ripristinala
                </button>
            @endif
        </div>
    @endif

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            {{-- `tabella-a-card` — 🔗 DS §7, `@utility` in `app.css`. Questa è una
                 tabella dell'**area cliente**, cioè quella che il DS non lascia
                 deviare: sotto i 768px la testata sparisce e ogni `<td>` rimette
                 la propria intestazione da `data-etichetta`. Senza, a 360px
                 «Stato» e le azioni restavano fuori dallo schermo, raggiungibili
                 solo scorrendo di lato una striscia larga 526px — misurato. --}}
            <table class="tabella-a-card w-full text-left text-sm">
                <thead class="border-b border-border bg-surface-sunken text-xs uppercase tracking-wide text-ink-3">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3">Persona</th>
                        <th scope="col" class="px-3 py-3">Ruolo</th>
                        <th scope="col" class="px-3 py-3">Stato</th>
                        <th scope="col" class="px-3 py-3 text-right">Azioni</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    @forelse ($persone as $persona)
                        @php
                            $stato = $stati[$persona->id];
                            $ruoli = $persona->getRoleNames();
                            // 🔴 Sola questione di **cosa mostrare**: le guardie vere
                            // rileggono dal database dentro l'azione. Qui si evita di
                            // offrire un gesto che verrebbe rifiutato, non si autorizza.
                            $eSeStesso = $persona->id === auth()->id();
                            $ultimoAdmin = $persona->hasRole('Admin') && $adminAttivi <= 1;
                            // Chi porta un ruolo che questa schermata non sa conferire
                            // (Superadmin, Developer) non si amministra da qui, in
                            // nessuna delle due direzioni: l'azione risponde 403.
                            $amministrabile = $amministrabili[$persona->id];
                        @endphp

                        <tr wire:key="persona-{{ $persona->id }}" class="align-top hover:bg-surface-sunken">
                            {{-- ⚠️ Un solo figlio diretto per cella (DS §7): in modalità
                                 card il `<td>` diventa flex, e nome ed email affiancati
                                 finirebbero uno accanto all'altro invece che uno sotto. --}}
                            <td class="py-3 pl-4 pr-3" data-etichetta="Persona">
                                <div>
                                    <div class="font-medium text-ink">
                                        {{ $persona->name }}
                                        @if ($eSeStesso)
                                            <span class="text-xs font-normal text-ink-3">(tu)</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-ink-2">{{ $persona->email }}</div>
                                </div>
                            </td>

                        <td class="px-3 py-3" data-etichetta="Ruolo">
                            <div class="flex flex-wrap gap-1">
                                {{-- ⛔ La variabile del ciclo NON si chiama `$ruolo`. La
                                     property pubblica del componente si chiama così, e un
                                     `foreach` di Blade **la sovrascrive per il resto del
                                     template**: l'avviso sul secondo fattore, nella modale
                                     d'invito più in basso, leggeva il ruolo dell'ultima
                                     riga dell'elenco invece di quello scelto nella tendina
                                     — quindi non compariva mai (o compariva sempre, a
                                     seconda di chi fosse l'ultimo in tabella). --}}
                                @forelse ($ruoli as $nomeRuolo)
                                    <x-ui.badge variant="neutral">{{ $nomeRuolo }}</x-ui.badge>
                                @empty
                                    {{-- Non «nessuno»: una persona senza ruolo entra e non
                                         vede niente, ed è uno stato da correggere, non da
                                         descrivere con un trattino. --}}
                                    <x-ui.badge variant="warning">Senza ruolo</x-ui.badge>
                            @endforelse
                            </div>
                            </td>

                            <td class="px-3 py-3" data-etichetta="Stato">
                                <div>
                                    <x-ui.badge :variant="$stato['variante']">{{ $stato['testo'] }}</x-ui.badge>

                                    {{-- Lo stato si legge senza colore (DS §1.3): la parola
                                         dice *quale* stato, questa riga dice cosa comporta.
                                         Solo per il cestino, che è l'unico dei tre a
                                         togliere qualcosa a qualcuno. --}}
                                    @if ($persona->trashed())
                                        <span class="mt-0.5 block text-xs text-ink-3">non entra e non è assegnabile</span>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-3 text-right" data-azioni>
                                {{-- Stessa grammatica di `/piattaforma/tecnici`: l'azione
                                     ordinaria porta il bordo (`secondary`), quella che
                                     toglie resta `ghost`. Due bottoni identici, uno dei
                                     quali cestina, sono due bottoni uguali. --}}
                                <div class="flex flex-wrap justify-end gap-2 max-md:justify-start">
                                    @if (in_array($persona->id, $condivise, true))
                                        <span class="text-xs text-ink-3">Anche di un altro cliente: si amministra da lì</span>
                                    @elseif (! $amministrabile)
                                        <span class="text-xs text-ink-3">Ruolo di piattaforma: si amministra da console</span>
                                    @elseif ($persona->trashed())
                                        @can('utenti.delete')
                                            <x-ui.button variant="secondary" wire:click="ripristina({{ $persona->id }})">
                                                Ripristina
                                            </x-ui.button>
                                        @endcan
                                    @else
                                        @if ($persona->email_verified_at === null)
                                            @can('utenti.create')
                                                <x-ui.button variant="ghost" wire:click="reinvia({{ $persona->id }})">
                                                    Reinvia l'invito
                                                </x-ui.button>
                                            @endcan
                                        @endif

                                        @can('utenti.update')
                                            <x-ui.button variant="secondary" wire:click="apriRuolo({{ $persona->id }})">
                                                Cambia ruolo
                                            </x-ui.button>
                                        @endcan

                                        @can('utenti.delete')
                                            @if (! $eSeStesso && ! $ultimoAdmin)
                                                <x-ui.button variant="ghost" wire:click="confermaCestino({{ $persona->id }})">
                                                    Cestina
                                                </x-ui.button>
                                            {{-- 🔴 Un bottone che sparisce senza dire perché si
                                                 legge come un difetto dell'applicazione. Le due
                                                 ragioni per cui «Cestina» non c'è sono diverse e
                                                 vanno dette entrambe: non ci si cestina da soli,
                                                 e l'ultimo Admin porterebbe via con sé la
                                                 chiave dell'Ente. --}}
                                            @elseif ($ultimoAdmin)
                                                <span class="self-center text-xs text-ink-3">
                                                    Unico Admin: nominane un altro per poterla cestinare
                                                </span>
                                            @elseif ($eSeStesso)
                                                <span class="self-center text-xs text-ink-3">Non puoi cestinare te stesso</span>
                                            @endif
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-ink-3">
                                {{-- Empty state = frase guida **+ la via d'uscita** (DS §5.9):
                                     senza il rimando al bottone resta la constatazione di un
                                     vuoto, che è la metà meno utile. --}}
                                Non c'è ancora nessuno oltre a te. La prima persona si aggiunge da «+ Invita una persona».
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4">
        {{ $persone->links() }}
    </div>

    <p class="mt-3 text-xs text-ink-3">
        Una persona cestinata non entra più e sparisce dalle tendine, ma lo storico continua a nominarla:
        chi ha fatto un intervento resta chi l'ha fatto.
    </p>

    {{-- Modale «invita una persona» --}}
    @if ($showInvito)
        <x-ui.modal title="Invita una persona" close="chiudiInvito">
            <p class="text-sm text-ink-2">
                Riceverà un'email con un link valido sette giorni, da cui sceglie la propria password.
                Nasce dentro questo Ente e vede soltanto ciò che il ruolo le consente.
            </p>

            <form wire:submit="invita" class="mt-4 space-y-4">
                <x-ui.input label="Nome e cognome" name="nome" wire:model="nome" placeholder="Giulia Verdi" />

                <x-ui.input label="Indirizzo email" name="email" type="email" wire:model="email"
                            placeholder="giulia.verdi@example.it" />

                <div>
                    <label for="ruolo" class="block text-sm font-medium text-ink">Ruolo</label>
                    <select id="ruolo" wire:model.live="ruolo"
                            class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">Scegli un ruolo…</option>
                        @foreach ($this->ruoliConferibili() as $conferibile)
                            <option value="{{ $conferibile }}">{{ $conferibile }}</option>
                        @endforeach
                    </select>
                    @error('ruolo')
                        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                    @enderror

                    {{-- 🔴 L'avviso sul secondo fattore sta ACCANTO al campo e
                         **prima** del gesto (🔗 ADR-016/038): chi riceve un ruolo
                         della lista `two_factor_required_roles` al primo accesso
                         finisce su `/settings/security` e non può andare altrove.
                         È il comportamento voluto — scoprirlo dalla telefonata di
                         chi non riesce a entrare è un'altra cosa. --}}
                    @if ($this->imponeSecondoFattore($ruolo))
                        <p class="mt-2 rounded-md border border-warn-dot bg-warn-soft px-3 py-2 text-sm text-warn-soft-ink">
                            Questo ruolo richiede il secondo fattore. Al primo accesso le sarà chiesto di
                            configurarlo e non potrà fare altro finché non l'avrà fatto: avvisala, e assicurati
                            che abbia con sé il telefono con cui lo userà.
                        </p>
                    @endif
                </div>

                @if ($errore)
                    <div class="rounded-md bg-bad-soft px-3 py-2 text-sm text-bad-soft-ink">
                        {{ $errore }}

                        @if ($ripristinabile)
                            <button type="button" wire:click="ripristina({{ $ripristinabile }})"
                                    class="ml-1 inline-flex min-h-11 items-center font-semibold underline underline-offset-2 hover:no-underline">
                                Ripristinala
                            </button>
                        @endif
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" wire:click="chiudiInvito">Annulla</x-ui.button>
                    <x-ui.button type="submit">Invita</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Modale «cambia ruolo» --}}
    @if ($inModifica)
        <x-ui.modal title="Cambia ruolo" close="chiudiRuolo">
            <p class="text-sm text-ink-2">
                Il nuovo ruolo di <strong class="text-ink">{{ $inModifica->name }}</strong> vale subito,
                anche se in questo momento è collegata.
            </p>

            <form wire:submit="cambiaRuolo" class="mt-4 space-y-4">
                <div>
                    <label for="nuovo-ruolo" class="block text-sm font-medium text-ink">Ruolo</label>
                    <select id="nuovo-ruolo" wire:model.live="nuovoRuolo"
                            class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">Scegli un ruolo…</option>
                        @foreach ($this->ruoliConferibili() as $conferibile)
                            <option value="{{ $conferibile }}">{{ $conferibile }}</option>
                        @endforeach
                    </select>
                    @error('nuovoRuolo')
                        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                    @enderror

                    @if ($this->imponeSecondoFattore($nuovoRuolo))
                        <p class="mt-2 rounded-md border border-warn-dot bg-warn-soft px-3 py-2 text-sm text-warn-soft-ink">
                            Questo ruolo richiede il secondo fattore: al prossimo accesso le sarà chiesto di
                            configurarlo, e non potrà fare altro finché non l'avrà fatto.
                        </p>
                    @endif
                </div>

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" type="button" wire:click="chiudiRuolo">Annulla</x-ui.button>
                    <x-ui.button type="submit">Salva il ruolo</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Conferma «cestina» --}}
    @if ($inCestino)
        <x-ui.modal title="Cestinare {{ $inCestino->name }}?" close="annullaCestino">
            <p class="text-sm text-ink-2">
                Non entrerà più e sparirà dalle tendine e dalle email di scadenza. Non viene cancellata:
                lo storico continua a nominarla, e puoi ripristinarla da questa stessa pagina.
            </p>

            <div class="mt-4 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="annullaCestino">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="cestina">Cestina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</div>

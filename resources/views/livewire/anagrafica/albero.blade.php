@php
    use App\Enums\TipoUnitaOrganizzativa;

    $isEnte = $current && $current->tipo === TipoUnitaOrganizzativa::Ente;
    // 🔗 ADR-053: i nomi dei livelli li dà l'enum. Sotto la sede ci sono i
    // laboratori (fino al 10 Ott 2026 «dipartimenti»), e sotto quelli i
    // sotto-laboratori.
    $figli = $current ? ($isEnte ? TipoUnitaOrganizzativa::Dipartimento : TipoUnitaOrganizzativa::Sottolaboratorio) : TipoUnitaOrganizzativa::Ente;
    $childLabel = $figli->plurale();
    $addLabel = 'Aggiungi '.mb_strtolower($figli->etichetta());
@endphp

<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

    {{-- Intestazione + breadcrumb --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-ink">{{ TipoUnitaOrganizzativa::SEZIONE }}</h1>

            @if ($breadcrumb->isNotEmpty())
                <nav aria-label="Percorso" class="mt-1 flex flex-wrap items-center gap-1 text-sm text-ink-3">
                    @foreach ($breadcrumb as $crumb)
                        @if (! $loop->last)
                            <button type="button" wire:click="goTo({{ $crumb->id }})"
                                class="rounded px-1 py-0.5 hover:bg-surface-sunken hover:text-ink">{{ $crumb->nome }}</button>
                            <span class="text-ink-3">›</span>
                        @else
                            <span class="font-medium text-ink">{{ $crumb->nome }}</span>
                        @endif
                    @endforeach
                </nav>
            @endif
        </div>

        @if ($current)
            <div class="flex shrink-0 items-center gap-2">
                @can('unita_organizzativa.update')
                    {{-- 🔗 ADR-046: il nodo Ente di un cliente non lo rinomina
                         chi lo segue per portafoglio. Il bottone sparisce qui e
                         l'azione rifiuta comunque (`rifiutaEnteAltrui`). --}}
                    @if (! $isEnte || $this->puoModificareEnte())
                        <x-ui.button variant="ghost" wire:click="edit({{ $current->id }})">Rinomina</x-ui.button>
                    @endif
                    {{-- Il marchio email vive solo sull'Ente (ADR-011): su un
                         dipartimento il pulsante porterebbe a una pagina che
                         parla di un altro nodo. Nessuna voce di menù per
                         questa impostazione — si tocca una volta l'anno, e il
                         menù di primo livello è la superficie più contesa
                         dell'applicazione. --}}
                    {{-- ⚠️ Solo sulla **propria** sede (ADR-046): la pagina del
                         marchio lavora sull'Ente di chi guarda, e offerta sul
                         nodo di un cliente gestito aprirebbe quello di EasyLab. --}}
                    @if ($isEnte && $current->id === $enteProprio)
                        <x-ui.button variant="secondary" href="{{ route('anagrafica.marchio') }}" wire:navigate>Marchio email</x-ui.button>
                    @endif
                @endcan
                @can('unita_organizzativa.delete')
                    @unless ($isEnte)
                        <x-ui.button variant="ghost" wire:click="confirmDelete({{ $current->id }})">Elimina</x-ui.button>
                    @endunless
                @endcan
                {{-- 🔴 Il gesto del CLIENTE su sé stesso: aggiungere una sede al
                     proprio contratto, entro il tetto del piano (🔗 ADR-032).
                     Sta qui e non alla radice perché un Admin con un Ente solo
                     alla radice non ci arriva mai — `mount()` lo porta dentro.

                     ⚠️ Non chiede `tenants.provision`, che è cross-tenant e nel
                     set bloccato: chiede `manage` sul PROPRIO account, perché
                     una sede in più consuma uno slot del piano, cioè tocca il
                     contratto. --}}
                @if ($isEnte && $this->puoAggiungereSede())
                    <x-ui.button variant="secondary" wire:click="apriNuovaSede">+ Aggiungi una sede</x-ui.button>
                @elseif ($isEnte && $this->tettoPieno())
                    {{-- ⛔ Il tetto pieno si DICE, e si dice CON I NUMERI.
                         La prima stesura scriveva «Sedi incluse nel piano:
                         esaurite» e basta: chi la leggeva non sapeva né quante
                         ne avesse diritto, né cosa fare per averne di più —
                         cioè vedeva sparire un bottone e non sapeva perché.
                         Segnalato da Marco il 28 Ago 2026, guardando proprio
                         questa riga. --}}
                    @php
                        $tetto = $this->tettoDelPiano();
                    @endphp
                    <span class="text-xs text-ink-3">
                        Il piano <strong>{{ $tetto['piano'] }}</strong> include
                        {{ $tetto['max'] }} {{ $tetto['max'] === 1 ? 'sede' : 'sedi' }}, e
                        {{ $tetto['max'] === 1 ? 'la stai usando' : 'le stai usando tutte' }}.
                        @if ($tetto['puoVedereAbbonamento'])
                            <a href="{{ route('abbonamento.index') }}" class="font-medium underline hover:no-underline">Vedi l'abbonamento</a>
                        @endif
                    </span>
                @endif
                @can('unita_organizzativa.create')
                    <x-ui.button wire:click="addChild({{ $current->id }})">+ {{ $addLabel }}</x-ui.button>
                @endcan
            </div>
        @endif
    </div>

    {{-- 🧭 Al livello radice l'elenco si chiama «Enti», ma qui un Ente non si
         crea: l'albero organizza l'interno di una sede (dipartimenti e
         sotto-laboratori), mentre un Ente nasce dal provisioning, insieme al
         cliente che lo intesta e al suo amministratore. Senza questa riga la
         pagina è un vicolo cieco muto — chi cerca «crea Ente» qui non trova
         nulla e non sa dove guardare. Segnalato dal Developer, che è il ruolo
         che vede più spesso questo livello: con più Enti visibili (o nessuno)
         `mount()` non entra in automatico in nessuno.

         Il riquadro è chiuso dietro `tenants.provision` di proposito: a un
         Admin, che un Ente non può crearlo, indicare una pagina che non può
         aprire sarebbe una seconda strada senza uscita. --}}
    @if ($current === null)
        @can('tenants.provision')
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface-sunken px-4 py-3">
                <p class="text-sm text-ink-2">
                    Qui si organizza l'<strong>interno</strong> di un Ente. Un Ente nuovo nasce dalla
                    Piattaforma, insieme al cliente che lo intesta — o come sede in più di un cliente che c'è già.
                </p>
                <x-ui.button variant="secondary" href="{{ route('piattaforma.index') }}">
                    Crea un Ente dalla Piattaforma
                </x-ui.button>
            </div>
        @endcan
    @endif

    @if ($notice)
        {{-- ⚠️ Il bordo era `border-warning-500/30`: come nel registro di audit, il
             campione non porta un bordo colorato sull'alert — lo fa solo il fondo. --}}
        <div class="mt-4 rounded-md border border-transparent bg-warn-soft px-3 py-2 text-sm text-warn-soft-ink">
            {{ $notice }}
        </div>
    @endif

    {{-- 🔴 Le altre sedi dello STESSO contratto. Senza questa riga una sede
         appena creata non si vede da nessuna parte: l'albero è scopato al
         proprio Ente (ADR-018) e le altre vivono solo nella tendina dello
         switcher — che durante un'impersonazione è soppressa apposta.

         ⚠️ Nomi e non link: raggiungerle è un gesto dello switcher, che ha le
         proprie guardie. Qui si risponde a «esiste?», non a «portami».

         ⛔ **Il testo parla al CLIENTE e a nessun altro.** Fino al 28 Ago 2026
         questa riga aveva una seconda versione rivolta a chi impersona («il
         cliente le raggiunge, tu no»): è l'area del cliente, e stamparci un
         avviso di servizio per l'operatore è un errore di destinatario prima
         che di stile. Marco l'ha tolta per quello — ed era anche diventata
         falsa, perché lo switcher da ieri funziona durante l'impersonazione. --}}
    @php
        $altreSedi = $this->altreSediDelContratto();
    @endphp
    @if ($isEnte && $altreSedi->isNotEmpty())
        <p class="mt-4 text-xs text-ink-3">
            Altre sedi del tuo contratto:
            <span class="text-ink-2">{{ $altreSedi->join(', ', ' e ') }}</span>.
            Le raggiungi dallo switcher in alto.
        </p>
    @endif

    {{-- Sotto-nodi --}}
    <section class="mt-6">
        <h2 class="text-xs font-semibold tracking-wide text-ink-3 uppercase">{{ $childLabel }}</h2>

        @if ($children->isEmpty())
            <div class="mt-3 rounded-lg border border-dashed border-border px-4 py-8 text-center">
                {{-- 🔗 ADR-057: un Responsabile Reparto senza laboratori non
                     vede nulla (ADR-006, fail-closed), e «Nessun ente qui» non
                     glielo spiegherebbe. Gli si dice che cosa manca, e a chi
                     chiederlo. --}}
                @if (! $current && auth()->user()?->isDepartmentScoped())
                    <p class="text-sm text-ink-2" data-senza-laboratori>
                        Non ti è stato ancora assegnato nessun laboratorio, quindi qui non c'è niente da vedere.
                        Chiedi a chi amministra la sede di assegnartene uno dalla pagina «Persone».
                    </p>
                @else
                    <p class="text-sm text-ink-3">Nessun {{ mb_strtolower($figli->etichetta()) }} qui.</p>
                @endif
                @if ($current)
                    @can('unita_organizzativa.create')
                        <x-ui.button variant="secondary" class="mt-3" wire:click="addChild({{ $current->id }})">+ {{ $addLabel }}</x-ui.button>
                    @endcan
                @endif
            </div>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($children as $node)
                    <div class="group relative rounded-lg border border-border bg-surface p-4 transition hover:border-brand-line hover:shadow-sm"
                        wire:key="node-{{ $node->id }}">
                        <button type="button" wire:click="open({{ $node->id }})" class="flex w-full items-start gap-3 text-left">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-brand-soft text-brand-soft-ink">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink group-hover:text-brand">{{ $node->nome }}</span>
                                <span class="mt-0.5 block text-xs text-ink-3">
                                    {{ ($childCounts[$node->id] ?? 0) }} sotto-unità · {{ ($strumentiCounts[$node->id] ?? 0) }} strumenti
                                </span>
                            </span>
                        </button>

                        <div class="absolute top-2 right-2 flex items-center gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                            @can('unita_organizzativa.update')
                                @if ($node->tipo !== \App\Enums\TipoUnitaOrganizzativa::Ente || $this->puoModificareEnte())
                                    <button type="button" wire:click="edit({{ $node->id }})" title="Rinomina"
                                        class="flex h-8 w-8 items-center justify-center rounded text-ink-2 hover:bg-surface-sunken hover:text-ink">✎</button>
                                @endif
                            @endcan
                            @can('unita_organizzativa.delete')
                                <button type="button" wire:click="confirmDelete({{ $node->id }})" title="Elimina"
                                    class="flex h-8 w-8 items-center justify-center rounded text-bad-dot hover:bg-bad-soft">🗑</button>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- 🔗 ADR-057: chi segue questo laboratorio. `$responsabili` è `null` alla
         radice, sulla sede, e per chi non può vedere le persone.

         Chi ha il ruolo Responsabile Reparto vede solo i laboratori che gli sono
         assegnati (ADR-006): qui si legge chi sono, e chi può cambiare i ruoli
         (`utenti.update`) ne aggiunge e ne toglie. È la stessa scrittura della
         finestra «Laboratori» di «Persone», vista dal laboratorio.

         ⚠️ Sta PRIMA degli strumenti: in un laboratorio con quaranta macchine,
         in fondo all'elenco non lo troverebbe nessuno. --}}
    @if ($responsabili !== null)
        <section class="mt-8" data-responsabili>
            <h2 class="text-xs font-semibold tracking-wide text-ink-3 uppercase">Responsabili ({{ count($responsabili['seguono']) }})</h2>
            <p class="mt-1 text-sm text-ink-2">
                Chi ha il ruolo Responsabile Reparto vede solo i laboratori che gli sono assegnati, con ciò che contengono.
            </p>

            @if ($responsabili['seguono'] === [])
                <p class="mt-3 rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-ink-3">
                    Nessun responsabile segue questo laboratorio.
                </p>
            @else
                <ul class="mt-3 divide-y divide-border rounded-lg border border-border bg-surface">
                    @foreach ($responsabili['seguono'] as $voce)
                        <li wire:key="responsabile-{{ $voce['persona']->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink">{{ $voce['persona']->name }}</span>
                                <span class="block truncate text-xs text-ink-3">{{ $voce['persona']->email }}</span>
                            </span>

                            @if ($voce['tramite'] !== null)
                                {{-- Lo riceve da un laboratorio che contiene questo:
                                     si toglie da lì, non da qui. --}}
                                <span class="shrink-0 text-xs text-ink-3" data-tramite>tramite {{ $voce['tramite'] }}</span>
                            @else
                                @can('utenti.update')
                                    <x-ui.button variant="ghost" wire:click="togliResponsabile({{ $voce['persona']->id }})">
                                        Togli
                                    </x-ui.button>
                                @endcan
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @can('utenti.update')
                @if ($responsabili['altri'] !== [])
                    <form wire:submit="assegnaResponsabile" class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="min-w-56 flex-1">
                            <label for="nuovoResponsabile" class="block text-sm font-medium text-ink">Assegna questo laboratorio a</label>
                            <select id="nuovoResponsabile" wire:model="nuovoResponsabile"
                                    class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                                <option value="">Scegli una persona…</option>
                                @foreach ($responsabili['altri'] as $candidato)
                                    <option value="{{ $candidato->id }}">{{ $candidato->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-ui.button type="submit" variant="secondary">Assegna</x-ui.button>
                    </form>
                    @error('nuovoResponsabile')
                        <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                    @enderror
                @elseif ($responsabili['seguono'] === [])
                    <p class="mt-3 text-sm text-ink-3" data-nessun-responsabile-in-sede>
                        In questa sede nessuno ha il ruolo Responsabile Reparto: si dà dalla pagina «Persone».
                    </p>
                @endif
            @endcan
        </section>
    @endif

    {{-- Strumenti del nodo corrente --}}
    @if ($current && ! $isEnte)
        <section class="mt-8">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-semibold tracking-wide text-ink-3 uppercase">Strumenti ({{ $strumenti->count() }})</h2>
                @can('strumenti.create')
                    <x-ui.button variant="secondary" wire:click="addStrumento">+ Aggiungi strumento</x-ui.button>
                @endcan
            </div>

            @if ($strumenti->isEmpty())
                <p class="mt-3 rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-ink-3">
                    Nessuno strumento in questo nodo.
                </p>
            @else
                <ul class="mt-3 divide-y divide-border rounded-lg border border-border bg-surface">
                    @foreach ($strumenti as $s)
                        <li>
                            <a href="{{ route('strumenti.show', $s) }}" wire:navigate
                                class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-surface-sunken">
                                <span class="truncate text-sm font-medium text-ink">{{ $s->nome }}</span>
                                <span class="flex items-center gap-2 text-xs text-ink-3">
                                    <span>{{ $s->modello }}</span>
                                    <span class="text-ink-3">›</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- Modale create/edit unità --}}
    @if ($showForm)
        @php $tipoLabel = TipoUnitaOrganizzativa::tryFrom((string) $tipo)?->etichetta() ?? ucfirst((string) $tipo); @endphp
        <x-ui.modal :title="$editingId ? 'Rinomina' : 'Nuovo '.strtolower($tipoLabel)" close="closeForm">
            <form wire:submit="save" class="space-y-5">
                @unless ($editingId)
                    <p class="text-sm text-ink-2">Tipo: <span class="font-medium text-ink">{{ $tipoLabel }}</span></p>
                @endunless

                <x-ui.input name="nome" label="Nome" wire:model="nome" placeholder="Es. Reparto di Cardiologia" autofocus />
                <x-ui.textarea name="note" label="Note (opzionale)" wire:model="note" />

                {{-- Soglia obsolescenza: campo del solo nodo Ente (ADR-014). --}}
                @if ($editingId !== null && $tipo === App\Enums\TipoUnitaOrganizzativa::Ente->value)
                    <div>
                        <x-ui.input name="sogliaObsolescenzaAnni" label="Soglia obsolescenza (anni)"
                            type="number" min="1" max="50" wire:model="sogliaObsolescenzaAnni" />
                        <p class="mt-1 text-xs text-ink-3">
                            Oltre questa età uno strumento è segnalato fra gli ⏳ {{ mb_strtolower(\App\Models\Strumento::ETICHETTA_OBSOLETI) }}.
                            È solo una segnalazione: non blocca la manutenzione.
                        </p>
                    </div>

                    {{-- Visibilità garanzie ricambio (ADR-029): clausola del
                         rapporto commerciale, quindi solo EasyLab la decide —
                         l'Admin dell'Ente ha `unita_organizzativa.update` e
                         governa il resto di questo form, ma non questo campo. --}}
                    @if ($this->puoGestireVisibilitaGaranzie())
                        <div>
                            <label for="visibilitaGaranzieRicambio" class="block text-sm font-medium text-ink-2">
                                Garanzie dei ricambi per il Tenant
                            </label>
                            {{-- ⚠️ `<select>` nudo: la preflight rende trasparenti i controlli di
                                 form, quindi `bg-surface` va dichiarato esplicitamente (DS §8.5). --}}
                            <select id="visibilitaGaranzieRicambio" wire:model.live="visibilitaGaranzieRicambio"
                                class="mt-1 w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                                @foreach (App\Enums\VisibilitaGaranzieRicambio::cases() as $caso)
                                    <option value="{{ $caso->value }}">{{ $caso->label() }}</option>
                                @endforeach
                            </select>
                            @error('visibilitaGaranzieRicambio')
                                <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-ink-3">
                                {{ App\Enums\VisibilitaGaranzieRicambio::tryFrom($visibilitaGaranzieRicambio ?? '')?->descrizione() }}
                            </p>
                        </div>
                    @endif
                @endif

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeForm">Annulla</x-ui.button>
                    <x-ui.button type="submit">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Conferma eliminazione nodo --}}
    @if ($deletingId)
        <x-ui.modal title="Conferma eliminazione">
            <p class="text-sm text-ink-2">Eliminare questa unità? L'operazione è reversibile (soft delete).</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete">Elimina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif

    {{-- Modale nuovo strumento --}}
    @if ($showStrumentoForm)
        <x-ui.modal title="Nuovo strumento" close="closeStrumentoForm">
            <form wire:submit="saveStrumento" class="space-y-5">
                @include('livewire.strumenti._form-fields')

                {{-- 🔗 ADR-059: la garanzia si scrive qui, mentre si registra la
                     macchina, e non in un secondo momento. Dopo si modifica dal
                     tab Garanzie della scheda. Dietro lo stesso permesso di quel
                     tab: chi non la gestisce non la vede nemmeno qui. --}}
                @can('garanzie.macchina.manage')
                    <fieldset class="space-y-3" data-garanzia-nuova>
                        <legend class="block text-sm font-medium text-ink">Garanzia della macchina <span class="font-normal text-ink-3">(facoltativa)</span></legend>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.input name="garanziaNuova.data_inizio" label="Inizio" type="date" wire:model="garanziaNuova.data_inizio" />
                            <x-ui.input name="garanziaNuova.durata_mesi" label="Durata (mesi)" type="number" min="1" max="600" wire:model="garanziaNuova.durata_mesi" placeholder="Es. 24" />
                        </div>

                        <p class="text-xs text-ink-3">Basta la durata: senza una data di inizio vale quella di installazione. Poi si modifica dalla scheda, nel tab Garanzie.</p>
                    </fieldset>
                @endcan

                <x-ui.input name="provenienza" label="Provenienza (ente esterno, opzionale)" wire:model="provenienza"
                    placeholder="Es. Ospedale San Paolo (esterno)" />

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeStrumentoForm">Annulla</x-ui.button>
                    <x-ui.button type="submit">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- ⛔ Questa modale stava FUORI dal `</div>` di radice, e per Livewire
         era come non esistere: un componente ha UN solo elemento radice, e
         tutto ciò che lo segue viene scartato in silenzio. Il bottone
         «Aggiungi una sede» chiamava l'azione, l'azione metteva
         `showSedeForm` a true, e non si apriva niente — nessun errore, né
         in pagina né in console. Segnalato da Marco il 28 Ago 2026. --}}
    {{-- Modale «aggiungi una sede» --}}
    @if ($showSedeForm)
        <x-ui.modal title="Aggiungi una sede" close="chiudiNuovaSede">
            <p class="text-sm text-ink-2">
                La sede nasce dentro il tuo contratto e la raggiungi dallo switcher in alto.
                @php($residui = $this->slotResidui())
                @if ($residui !== null)
                    Te ne {{ $residui === 1 ? 'resta' : 'restano' }} <strong>{{ $residui }}</strong>.
                @endif
            </p>

            <div class="mt-4">
                <label for="nome-sede" class="block text-sm font-medium text-ink">Nome della sede</label>
                <input id="nome-sede" type="text" wire:model="nomeSede"
                       placeholder="Sede di Bergamo"
                       class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                @error('nomeSede')
                    <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-4 flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="chiudiNuovaSede">Annulla</x-ui.button>
                <x-ui.button wire:click="creaSede">Crea la sede</x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</div>

@php
    use App\Enums\TipoUnitaOrganizzativa;

    $isEnte = $current && $current->tipo === TipoUnitaOrganizzativa::Ente;
    $childLabel = $current ? ($isEnte ? 'Dipartimenti' : 'Sotto-laboratori') : 'Enti';
    $addLabel = $isEnte ? 'Aggiungi dipartimento' : 'Aggiungi sotto-laboratorio';
@endphp

<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

    {{-- Intestazione + breadcrumb --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-ink">Anagrafica</h1>

            @if ($breadcrumb->isNotEmpty())
                <nav class="mt-1 flex flex-wrap items-center gap-1 text-sm text-ink-3">
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
                    <x-ui.button variant="ghost" wire:click="edit({{ $current->id }})">Rinomina</x-ui.button>
                    {{-- Il marchio email vive solo sull'Ente (ADR-011): su un
                         dipartimento il pulsante porterebbe a una pagina che
                         parla di un altro nodo. Nessuna voce di menù per
                         questa impostazione — si tocca una volta l'anno, e il
                         menù di primo livello è la superficie più contesa
                         dell'applicazione. --}}
                    @if ($isEnte)
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
                <p class="text-sm text-ink-3">Nessun {{ $isEnte ? 'dipartimento' : ($current ? 'sotto-laboratorio' : 'ente') }} qui.</p>
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
                                <button type="button" wire:click="edit({{ $node->id }})" title="Rinomina"
                                    class="flex h-8 w-8 items-center justify-center rounded text-ink-2 hover:bg-surface-sunken hover:text-ink">✎</button>
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
        @php $tipoLabel = ['dipartimento' => 'Dipartimento', 'sottolaboratorio' => 'Sotto-laboratorio'][$tipo] ?? ucfirst($tipo); @endphp
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
                            Oltre questa età uno strumento è segnalato ⏳ Obsoleto.
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

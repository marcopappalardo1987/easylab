@php
    $parametri = $strumento->parametri_tecnici ?? [];
@endphp

<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6" x-data="{ tab: 'anagrafica' }">

    <a href="{{ route('anagrafica.index') }}" wire:navigate class="text-sm text-neutral-500 hover:text-neutral-800">‹ Torna all'anagrafica</a>

    {{-- Header --}}
    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            {{-- Semaforo (ADR-005): segnale di sintesi, la fonte di verità resta il tab Interventi. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h1 class="text-2xl font-bold tracking-tight text-neutral-900">{{ $strumento->nome }}</h1>
                <x-ui.semaforo :stato="$semaforo" size="md" :label="true" />
                <x-ui.semaforo-forzato :strumento="$strumento" />
            </div>
            <p class="mt-1 text-sm text-neutral-600">
                @if ($strumento->modello)<span class="font-medium text-neutral-800">{{ $strumento->modello }}</span> · @endif
                {{ $percorso }}
                @if ($strumento->data_installazione) · Installato {{ $strumento->data_installazione->format('m/Y') }} <x-ui.obsoleto :strumento="$strumento" /> @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            @can('semaforo.force')
                <x-ui.button variant="secondary" wire:click="openForza">Forza semaforo</x-ui.button>
            @endcan
            @can('strumenti.move')
                <x-ui.button variant="secondary" wire:click="openMove">Sposta</x-ui.button>
            @endcan
            @can('strumenti.update')
                <x-ui.button variant="secondary" wire:click="edit">Modifica</x-ui.button>
            @endcan
            @can('strumenti.delete')
                <x-ui.button variant="danger" wire:click="$set('confirmingDelete', true)">Elimina</x-ui.button>
            @endcan
        </div>
    </div>

    {{-- Tab --}}
    <div class="mt-6 border-b border-neutral-200">
        <nav class="-mb-px flex flex-wrap gap-1 text-sm">
            <button type="button" x-on:click="tab = 'anagrafica'"
                :class="tab === 'anagrafica' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'"
                class="border-b-2 px-3 py-2 font-medium">Anagrafica</button>
            @can('interventi.view')
                <button type="button" x-on:click="tab = 'interventi'"
                    :class="tab === 'interventi' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'"
                    class="border-b-2 px-3 py-2 font-medium">Interventi</button>
            @endcan
            @foreach (['Ricambi', 'Documenti'] as $t)
                <span class="cursor-not-allowed border-b-2 border-transparent px-3 py-2 text-neutral-300" title="In arrivo (S3/S4)">{{ $t }}</span>
            @endforeach
            @can('garanzie.macchina.view')
                <button type="button" x-on:click="tab = 'garanzie'"
                    :class="tab === 'garanzie' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'"
                    class="border-b-2 px-3 py-2 font-medium">Garanzie</button>
            @endcan
        </nav>
    </div>

    {{-- Tab Anagrafica --}}
    <div x-show="tab === 'anagrafica'" class="mt-6">
        <x-ui.card>
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium tracking-wide text-neutral-400 uppercase">Modello</dt>
                    <dd class="mt-0.5 text-sm text-neutral-800">
                        {{ $strumento->modello ?: '—' }}
                        @if ($strumento->modello)
                            <a href="{{ route('strumenti.modelli', ['search' => $strumento->modello]) }}" wire:navigate
                                class="ml-2 text-xs text-primary-600 hover:text-primary-700">dove altro è installato →</a>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-neutral-400 uppercase">Matricola</dt>
                    <dd class="mt-0.5 text-sm text-neutral-800">{{ $strumento->matricola ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-neutral-400 uppercase">Data installazione</dt>
                    <dd class="mt-0.5 text-sm text-neutral-800">{{ $strumento->data_installazione?->format('d/m/Y') ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium tracking-wide text-neutral-400 uppercase">Ubicazione</dt>
                    <dd class="mt-0.5 text-sm text-neutral-800">{{ $percorso }}</dd>
                </div>
            </dl>

            <hr class="my-6 border-neutral-200">
            <p class="text-sm font-medium text-neutral-600">Parametri tecnici</p>
            @if (count($parametri) === 0)
                <p class="mt-1 text-sm text-neutral-400">Nessun parametro tecnico.</p>
            @else
                <dl class="mt-3 divide-y divide-neutral-100">
                    @foreach ($parametri as $chiave => $valore)
                        <div class="flex justify-between gap-4 py-2 text-sm">
                            <dt class="text-neutral-500">{{ $chiave }}</dt>
                            <dd class="text-right font-medium text-neutral-800">{{ $valore }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.card>

        {{-- Storico spostamenti --}}
        @can('spostamenti.view')
            <x-ui.card class="mt-4">
                <p class="text-sm font-medium text-neutral-600">Spostamenti</p>
                @if ($spostamenti->isEmpty())
                    <p class="mt-1 text-sm text-neutral-400">Nessuno spostamento registrato.</p>
                @else
                    <ul class="mt-3 divide-y divide-neutral-100">
                        @foreach ($spostamenti as $sp)
                            <li class="py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-neutral-800">
                                        {{ $sp->origineLabel() }} <span class="text-neutral-300">→</span> {{ $sp->destinazioneLabel() }}
                                    </span>
                                    <span class="shrink-0 text-xs text-neutral-400">{{ $sp->data->format('d/m/Y') }}</span>
                                </div>
                                <div class="mt-0.5 text-xs text-neutral-400">
                                    {{ ucfirst($sp->tipo_spostamento->value) }}@if ($sp->eseguitoBy) · {{ $sp->eseguitoBy->name }}@endif
                                    @if ($sp->nota) · {{ $sp->nota }}@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endcan
    </div>

    {{-- Tab Interventi (S3 punto 2): lista attività, fonte di verità del semaforo (ADR-005).
         x-cloak: senza, il pannello sarebbe visibile sotto l'Anagrafica finché Alpine non
         monta. Nasconde in CSS senza togliere il markup, quindi i test lo vedono comunque. --}}
    @can('interventi.view')
        <div x-show="tab === 'interventi'" x-cloak class="mt-6">
            @include('livewire.strumenti._interventi')
        </div>
    @endcan

    {{-- Tab Garanzie (S3 punti 7-8, ADR-004). Visibile anche a Tenant e Tecnico
         in sola lettura: hanno `garanzie.macchina.view`. Le righe sui ricambi
         restano invisibili a chi non ha `garanzie.ricambio.view` — filtro di
         scope, non di vista. --}}
    @can('garanzie.macchina.view')
        <div x-show="tab === 'garanzie'" x-cloak class="mt-6">
            @include('livewire.strumenti._garanzie')
        </div>
    @endcan

    {{-- Modale modifica --}}
    @if ($showForm)
        <x-ui.modal title="Modifica strumento" close="closeForm">
            <form wire:submit="save" class="space-y-5">
                @include('livewire.strumenti._form-fields')

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeForm">Annulla</x-ui.button>
                    <x-ui.button type="submit">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Conferma eliminazione --}}
    @if ($confirmingDelete)
        <x-ui.modal title="Conferma eliminazione">
            <p class="text-sm text-neutral-600">Eliminare lo strumento «{{ $strumento->nome }}»? L'operazione è reversibile (soft delete).</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('confirmingDelete', false)">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete">Elimina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif

    {{-- Modale nuovo/modifica intervento (S3 punto 3) --}}
    @if ($showInterventoForm)
        <x-ui.modal :title="$editingInterventoId ? 'Modifica intervento' : 'Nuovo intervento'" close="closeInterventoForm">
            <form wire:submit="saveIntervento" class="space-y-5">
                <x-ui.textarea name="interventoForm.descrizione" label="Descrizione" wire:model="interventoForm.descrizione" />

                <div>
                    <label for="interventoTipo" class="block text-sm font-medium text-neutral-800">Tipo</label>
                    <select id="interventoTipo" wire:model="interventoForm.tipo"
                        class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                        @foreach (App\Enums\TipoIntervento::cases() as $tipo)
                            <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                        @endforeach
                    </select>
                    @error('interventoForm.tipo') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <x-ui.input name="interventoForm.data_scadenza" label="Data scadenza" type="date" wire:model="interventoForm.data_scadenza" />

                @can('interventi.assign')
                    <div>
                        <label for="interventoTecnico" class="block text-sm font-medium text-neutral-800">Assegnatario</label>
                        <select id="interventoTecnico" wire:model="interventoForm.tecnico_id"
                            class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                            <option value="">— Nessun assegnatario —</option>
                            @foreach ($assegnatari as $tecnico)
                                <option value="{{ $tecnico->id }}">{{ $tecnico->name }}</option>
                            @endforeach
                        </select>
                        @error('interventoForm.tecnico_id') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                @endcan

                @if ($editingInterventoId === null)
                    {{-- Inserimento storico (backlog punto 3): un intervento già eseguito
                         si registra in un passo, senza transitare da scaduto-non-fatto. --}}
                    <label class="flex items-center gap-2 text-sm text-neutral-800">
                        <input type="checkbox" wire:model.live="interventoForm.gia_eseguito"
                            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-600">
                        Già eseguito
                    </label>
                    @if ($interventoForm['gia_eseguito'])
                        <x-ui.input name="interventoForm.data_esecuzione" label="Data esecuzione" type="date" wire:model="interventoForm.data_esecuzione" />
                    @endif
                @endif

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeInterventoForm">Annulla</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Modale spunta "Fatto" --}}
    @if ($showCompletaForm)
        <x-ui.modal title="Segna come fatto" close="closeCompleta">
            <form wire:submit="completa" class="space-y-5">
                <p class="text-sm text-neutral-600">
                    «{{ $interventi->firstWhere('id', $completingInterventoId)?->descrizione }}»
                </p>
                <x-ui.input name="dataEsecuzione" label="Data esecuzione" type="date" wire:model="dataEsecuzione" />
                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeCompleta">Annulla</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled">Conferma</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Conferma eliminazione intervento --}}
    @if ($deletingInterventoId)
        <x-ui.modal title="Conferma eliminazione">
            <p class="text-sm text-neutral-600">
                Eliminare l'intervento «{{ $interventi->firstWhere('id', $deletingInterventoId)?->descrizione }}»?
                L'operazione è reversibile (soft delete).
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('deletingInterventoId', null)">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="eliminaIntervento" wire:loading.attr="disabled">Elimina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif

    {{-- Modale sposta --}}
    @if ($showMoveForm)
        <x-ui.modal title="Sposta strumento" close="closeMove">
            <form wire:submit="move" class="space-y-5">
                <p class="text-sm text-neutral-600">Da: <span class="font-medium text-neutral-800">{{ $percorso }}</span></p>

                <div>
                    <label for="destinazioneId" class="block text-sm font-medium text-neutral-800">Destinazione</label>
                    <select id="destinazioneId" wire:model="destinazioneId"
                        class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                        <option value="">— Scegli dipartimento o laboratorio —</option>
                        @foreach ($nodiDestinazione as $nodo)
                            <option value="{{ $nodo->id }}">{{ $nodo->nome }}</option>
                        @endforeach
                    </select>
                    @error('destinazioneId') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <x-ui.input name="dataSpostamento" label="Data" type="date" wire:model="dataSpostamento" />
                <x-ui.textarea name="notaSpostamento" label="Nota (opzionale)" wire:model="notaSpostamento" />

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeMove">Annulla</x-ui.button>
                    <x-ui.button type="submit">Sposta</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Modale garanzia (S3 punto 7, ADR-004/019) --}}
    @if ($showGaranziaForm)
        <x-ui.modal :title="$editingGaranziaId ? 'Modifica garanzia' : 'Nuova garanzia'" close="closeGaranziaForm">
            <form wire:submit="saveGaranzia" class="space-y-5">
                <x-ui.input name="garanziaForm.data_inizio" label="Data inizio" type="date" wire:model="garanziaForm.data_inizio" />
                <x-ui.input name="garanziaForm.durata_mesi" label="Durata (mesi)" type="number" min="1" wire:model="garanziaForm.durata_mesi" />
                <p class="text-xs text-neutral-400">La scadenza effettiva è calcolata: inizio + durata.</p>

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeGaranziaForm">Annulla</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- Conferma eliminazione garanzia --}}
    @if ($deletingGaranziaId)
        <x-ui.modal title="Conferma eliminazione">
            <p class="text-sm text-neutral-600">Eliminare questa garanzia? L'operazione è reversibile (soft delete).</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('deletingGaranziaId', null)">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="eliminaGaranzia" wire:loading.attr="disabled">Elimina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif

    {{-- Modale forzatura semaforo (S3 punto 5, ADR-005) --}}
    @if ($showForzaForm)
        <x-ui.modal title="Forza semaforo" close="closeForza">
            <form wire:submit="forza" class="space-y-5">
                <p class="text-sm text-neutral-600">
                    Lo stato forzato vince su quello calcolato finché non viene rimosso.
                    Gli interventi restano visibili nel tab: la forzatura non nasconde nulla.
                </p>

                <div>
                    <label for="forzaStato" class="block text-sm font-medium text-neutral-800">Stato</label>
                    {{-- .live: cambiando stato, il campo motivo diventa obbligatorio sul rosso --}}
                    <select id="forzaStato" wire:model.live="forzaForm.stato"
                        class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                        <option value="{{ App\Enums\StatoSemaforo::Verde->value }}">● In regola</option>
                        <option value="{{ App\Enums\StatoSemaforo::Arancione->value }}">◐ Azione richiesta</option>
                        <option value="{{ App\Enums\StatoSemaforo::Rosso->value }}">■ Non idoneo</option>
                    </select>
                    @error('forzaForm.stato') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                @php $richiedeMotivo = $forzaForm['stato'] === App\Enums\StatoSemaforo::Rosso->value; @endphp
                <x-ui.textarea name="forzaForm.motivo" wire:model="forzaForm.motivo"
                    :label="$richiedeMotivo ? 'Motivo (obbligatorio per «non idoneo»)' : 'Motivo (opzionale)'" />

                <div class="flex items-center justify-between gap-3">
                    <div>
                        @if ($strumento->forced_state !== null)
                            <x-ui.button variant="ghost" wire:click="rimuoviForzatura" wire:loading.attr="disabled">
                                Rimuovi forzatura
                            </x-ui.button>
                        @endif
                    </div>
                    <div class="flex gap-3">
                        <x-ui.button variant="secondary" wire:click="closeForza">Annulla</x-ui.button>
                        <x-ui.button type="submit" wire:loading.attr="disabled">Forza</x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>

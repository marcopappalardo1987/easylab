@php
    $parametri = $strumento->parametri_tecnici ?? [];
@endphp

<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6" x-data="{ tab: 'anagrafica' }">

    <a href="{{ route('anagrafica.index') }}" wire:navigate class="text-sm text-neutral-500 hover:text-neutral-800">‹ Torna all'anagrafica</a>

    {{-- Header --}}
    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900">{{ $strumento->nome }}</h1>
            <p class="mt-1 text-sm text-neutral-600">
                @if ($strumento->modello)<span class="font-medium text-neutral-800">{{ $strumento->modello }}</span> · @endif
                {{ $percorso }}
                @if ($strumento->data_installazione) · Installato {{ $strumento->data_installazione->format('m/Y') }} @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
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
            @foreach (['Interventi', 'Ricambi', 'Documenti'] as $t)
                <span class="cursor-not-allowed border-b-2 border-transparent px-3 py-2 text-neutral-300" title="In arrivo (S3/S4)">{{ $t }}</span>
            @endforeach
            @can('garanzie.macchina.view')
                <span class="cursor-not-allowed border-b-2 border-transparent px-3 py-2 text-neutral-300" title="In arrivo (S3)">Garanzie</span>
            @endcan
        </nav>
    </div>

    {{-- Tab Anagrafica --}}
    <div x-show="tab === 'anagrafica'" class="mt-6">
        <x-ui.card>
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium tracking-wide text-neutral-400 uppercase">Modello</dt>
                    <dd class="mt-0.5 text-sm text-neutral-800">{{ $strumento->modello ?: '—' }}</dd>
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
</div>

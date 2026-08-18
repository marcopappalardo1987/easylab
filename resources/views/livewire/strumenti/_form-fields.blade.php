<div class="space-y-5">
    <x-ui.input name="strumentoForm.nome" label="Nome" wire:model="strumentoForm.nome" placeholder="Es. Autoclave AC-200" autofocus />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.input name="strumentoForm.modello" label="Modello" wire:model="strumentoForm.modello" />
        <x-ui.input name="strumentoForm.matricola" label="Matricola" wire:model="strumentoForm.matricola" />
    </div>

    <x-ui.input name="strumentoForm.data_installazione" label="Data installazione" type="date" wire:model="strumentoForm.data_installazione" />

    {{-- Fornitore (ADR-023): obbligatorio nel form, nullable in schema. Gated,
         perché un ruolo che non lo vede non deve nemmeno esserne bloccato. --}}
    @can('fornitori.view')
        <div>
            <label for="strumentoForm.fornitore_id" class="block text-sm font-medium text-neutral-800">Fornitore</label>
            <select id="strumentoForm.fornitore_id" wire:model="strumentoForm.fornitore_id"
                class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                {{-- `disabled` sul segnaposto: aprendo una riga storica senza
                     fornitore deve dire «non ancora scelto», non proporre il
                     primo dell'elenco — che sarebbe una scelta fatta da un default. --}}
                <option value="" disabled>— Scegli un fornitore —</option>
                @foreach ($fornitori as $f)
                    <option value="{{ $f->id }}">
                        {{ $f->ragione_sociale }}{{ $f->trashed() ? ' (cestinato)' : '' }}
                    </option>
                @endforeach
            </select>
            @error('strumentoForm.fornitore_id')
                <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
            @enderror
            @if ($fornitori->isEmpty())
                <p class="mt-1 text-xs text-neutral-400">
                    Nessun fornitore in anagrafica.
                    @can('fornitori.create')
                        <a href="{{ route('fornitori.index') }}" wire:navigate class="text-primary-600 hover:text-primary-700">Aggiungine uno</a>
                        prima di registrare la macchina.
                    @endcan
                </p>
            @endif
        </div>
    @endcan

    {{-- Parametri tecnici: repeater chiave → valore --}}
    <div>
        <div class="flex items-center justify-between">
            <span class="block text-sm font-medium text-neutral-800">Parametri tecnici</span>
            <x-ui.button variant="ghost" wire:click="addParametro" class="!px-2 !py-1 text-sm">+ Aggiungi</x-ui.button>
        </div>

        @if (count($parametri) === 0)
            <p class="mt-1 text-sm text-neutral-400">Nessun parametro. Aggiungine uno (es. Tensione = 220V).</p>
        @else
            <div class="mt-2 space-y-2">
                @foreach ($parametri as $i => $row)
                    <div class="flex items-center gap-2" wire:key="param-{{ $i }}">
                        <input type="text" wire:model="parametri.{{ $i }}.chiave" placeholder="Chiave"
                            class="block w-1/3 rounded-md border border-neutral-200 px-3 py-2 text-sm focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                        <input type="text" wire:model="parametri.{{ $i }}.valore" placeholder="Valore"
                            class="block flex-1 rounded-md border border-neutral-200 px-3 py-2 text-sm focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                        <button type="button" wire:click="removeParametro({{ $i }})" title="Rimuovi"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded text-danger-500 hover:bg-danger-100">🗑</button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

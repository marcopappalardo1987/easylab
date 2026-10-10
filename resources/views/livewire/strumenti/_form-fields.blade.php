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
        {{-- 🔗 ADR-051: non più una `<select>`. Si scorre, si filtra scrivendo
             e, se il fornitore manca, lo si crea da qui senza chiudere il form. --}}
        @include('livewire.fornitori._selettore', [
            'campo' => 'strumento',
            'etichetta' => 'Fornitore',
            'sceltoId' => $strumentoForm['fornitore_id'] ?? null,
            'erroreSu' => 'strumentoForm.fornitore_id',
            'facoltativo' => false,
        ])
    @endcan

    {{-- Parametri tecnici: repeater chiave → valore --}}
    <div>
        <div class="flex items-center justify-between">
            <span class="block text-sm font-medium text-ink">Parametri tecnici</span>
            <x-ui.button variant="ghost" wire:click="addParametro" class="!px-2 !py-1 text-sm">+ Aggiungi</x-ui.button>
        </div>

        @if (count($parametri) === 0)
            <p class="mt-1 text-sm text-ink-3">Nessun parametro. Aggiungine uno (es. Tensione = 220V).</p>
        @else
            <div class="mt-2 space-y-2">
                @foreach ($parametri as $i => $row)
                    <div class="flex items-center gap-2" wire:key="param-{{ $i }}">
                        <input type="text" wire:model="parametri.{{ $i }}.chiave" placeholder="Chiave"
                            class="block w-1/3 rounded-md border border-border-strong bg-surface px-3 py-2 text-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                        <input type="text" wire:model="parametri.{{ $i }}.valore" placeholder="Valore"
                            class="block flex-1 rounded-md border border-border-strong bg-surface px-3 py-2 text-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                        <button type="button" wire:click="removeParametro({{ $i }})" title="Rimuovi"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded text-bad-dot hover:bg-bad-soft">🗑</button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

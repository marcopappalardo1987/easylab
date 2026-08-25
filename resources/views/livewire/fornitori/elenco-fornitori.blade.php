<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Fornitori</h1>
            <p class="mt-1 text-sm text-neutral-600">
                Da chi sono state acquistate le macchine. Ogni macchina ha un fornitore solo.
            </p>
        </div>
        @can('fornitori.create')
            <x-ui.button wire:click="nuovo">+ Nuovo fornitore</x-ui.button>
        @endcan
    </div>

    @if ($notice)
        <div class="mt-4 rounded-md border border-warning-500 bg-warning-50 px-4 py-3 text-sm text-neutral-800">
            {{ $notice }}
        </div>
    @endif

    <x-ui.card class="mt-6 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Ragione sociale</th>
                        <th class="px-4 py-3 font-semibold">Email</th>
                        <th class="px-4 py-3 font-semibold">Telefono</th>
                        <th class="px-4 py-3 font-semibold">Macchine</th>
                        <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($fornitori as $fornitore)
                        <tr wire:key="forn-{{ $fornitore->id }}">
                            <td class="px-4 py-3 font-medium text-neutral-900">{{ $fornitore->ragione_sociale }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $fornitore->email ?: '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $fornitore->telefono ?: '—' }}</td>
                            <td class="px-4 py-3 tabular-nums text-neutral-600">{{ $fornitore->strumenti_count }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @can('fornitori.update')
                                    <button type="button" wire:click="edit({{ $fornitore->id }})"
                                        class="text-xs font-medium text-primary-600 hover:text-primary-700">Modifica</button>
                                @endcan
                                @can('fornitori.delete')
                                    <button type="button" wire:click="confermaElimina({{ $fornitore->id }})"
                                        class="ml-3 text-xs font-medium text-danger-600 hover:text-danger-800">Elimina</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-neutral-400">
                                Nessun fornitore. Aggiungine uno per poterlo associare alle macchine.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Modifica fornitore' : 'Nuovo fornitore'" close="closeForm">
            <form wire:submit="save" class="space-y-5">
                <x-ui.input name="form.ragione_sociale" label="Ragione sociale" wire:model="form.ragione_sociale"
                    placeholder="es. Thermo Fisher Italia" autofocus />
                <x-ui.input name="form.email" label="Email (opzionale)" type="email" wire:model="form.email" />
                <x-ui.input name="form.telefono" label="Telefono (opzionale)" wire:model="form.telefono" />
                <x-ui.textarea name="form.note" label="Note (opzionale)" wire:model="form.note" />

                <div class="flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="closeForm">Annulla</x-ui.button>
                    <x-ui.button type="submit">Salva</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($deletingId !== null)
        <x-ui.modal title="Eliminare il fornitore?">
            <p class="text-sm text-neutral-600">
                L'operazione è reversibile (soft delete). Un fornitore ancora associato a delle macchine
                non può essere eliminato: vanno prima riassegnate.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="elimina">Elimina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</div>

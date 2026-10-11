<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-ink">Fornitori</h1>
            <p class="mt-1 text-sm text-ink-2">
                Da chi sono state acquistate le macchine e i ricambi. Ogni macchina ha un fornitore solo;
                per un ricambio lo si indica sul pezzo montato.
            </p>
        </div>
        @can('fornitori.create')
            <x-ui.button wire:click="nuovo">+ Nuovo fornitore</x-ui.button>
        @endcan
    </div>

    {{-- ⚠️ Il bordo era `border-warning-500`: come nel registro di audit, il
         campione non porta un bordo colorato sull'alert — lo fa solo il fondo. --}}
    @if ($notice)
        <div class="mt-4 rounded-md border border-transparent bg-warn-soft px-4 py-3 text-sm text-warn-soft-ink">
            {{ $notice }}
        </div>
    @endif

    <x-ui.card class="mt-6 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border text-xs tracking-wide text-ink-3 uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Ragione sociale</th>
                        {{-- 🔗 ADR-046: il catalogo è per sede. Chi ne segue più
                             di una deve leggere di quale cliente è la riga. --}}
                        @if ($sedi->isNotEmpty())
                            <th class="px-4 py-3 font-semibold">Sede</th>
                        @endif
                        <th class="px-4 py-3 font-semibold">Email</th>
                        <th class="px-4 py-3 font-semibold">Telefono</th>
                        <th class="px-4 py-3 font-semibold">Macchine</th>
                        <th class="px-4 py-3 font-semibold">Ricambi</th>
                        <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($fornitori as $fornitore)
                        <tr wire:key="forn-{{ $fornitore->id }}">
                            <td class="px-4 py-3 font-medium text-ink">
                                {{ $fornitore->ragione_sociale }}
                                {{-- 🔗 ADR-062: una copia di un fornitore di EasyLab. --}}
                                @if ($fornitore->vieneDaEasyLab())
                                    <x-ui.badge variant="neutral" class="ml-1" data-da-easylab="{{ $fornitore->id }}">da EasyLab</x-ui.badge>
                                @endif
                            </td>
                            @if ($sedi->isNotEmpty())
                                <td class="px-4 py-3 text-ink-2" data-sede-fornitore="{{ $fornitore->id }}">{{ $sedi->has($fornitore->tenant_id) ? \App\Support\Tenancy\SediSeguite::etichetta($sedi[$fornitore->tenant_id]) : '—' }}</td>
                            @endif
                            <td class="px-4 py-3 text-ink-2">{{ $fornitore->email ?: '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $fornitore->telefono ?: '—' }}</td>
                            <td class="px-4 py-3 tabular-nums text-ink-2">{{ $fornitore->strumenti_count }}</td>
                            <td class="px-4 py-3 tabular-nums text-ink-2" data-ricambi="{{ $fornitore->ricambi_montati_count }}">{{ $fornitore->ricambi_montati_count }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @can('fornitori.update')
                                    <button type="button" wire:click="edit({{ $fornitore->id }})"
                                        class="text-xs font-medium text-brand hover:text-brand-hover">Modifica</button>
                                @endcan
                                @can('fornitori.delete')
                                    <button type="button" wire:click="confermaElimina({{ $fornitore->id }})"
                                        class="ml-3 text-xs font-medium text-bad-dot hover:brightness-90">Elimina</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $sedi->isNotEmpty() ? 7 : 6 }}" class="px-4 py-10 text-center text-sm text-ink-3">
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
                {{-- Solo alla creazione: un fornitore non cambia sede. --}}
                @if (! $editingId && $sedi->isNotEmpty())
                    {{-- 🔗 ADR-061: una tendina in cui si scrive per filtrare. --}}
                    @include('livewire.tenancy._selettore-sede', [
                        'selettore' => $sceltaSede,
                        'idDom' => 'sede-fornitore',
                        'etichetta' => 'Laboratorio',
                        'segnaposto' => 'Scegli il laboratorio…',
                        'aiuto' => 'Il fornitore entra nel catalogo di questo laboratorio, e solo lì si può scegliere su una macchina.',
                    ])
                @endif
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
            <p class="text-sm text-ink-2">
                L'operazione è reversibile (soft delete). Un fornitore ancora associato a delle macchine
                o a dei ricambi montati non può essere eliminato: vanno prima riassegnati.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('deletingId', null)">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="elimina">Elimina</x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</div>

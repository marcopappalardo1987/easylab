@php
    use App\Support\Semaforo;

    // Stato della garanzia: stessa soglia del semaforo, mai riscritta qui.
    // Colore + simbolo + etichetta come impone il Design System.
    $statoGaranzia = function ($g) {
        if ($g->isScaduta()) {
            return ['danger', '✗', 'Scaduta'];
        }

        return $g->data_scadenza_effettiva->lte(today()->addDays(Semaforo::giorniImminente()))
            ? ['warning', '◐', 'In scadenza']
            : ['success', '✓', 'Attiva'];
    };
@endphp

<x-ui.card class="!p-0">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
        <div>
            <p class="text-sm font-medium text-neutral-800">Garanzie</p>
            <p class="text-xs text-neutral-400">La scadenza effettiva è ciò che pilota il semaforo.</p>
        </div>
        @can('garanzie.macchina.manage')
            <x-ui.button variant="secondary" wire:click="openNuovaGaranzia">+ Nuova garanzia</x-ui.button>
        @endcan
    </div>

    <div class="overflow-x-auto">
        <table class="tabella-a-card w-full text-left text-sm">
            <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                <tr>
                    <th class="px-4 py-3 font-semibold">Stato</th>
                    <th class="px-4 py-3 font-semibold">Inizio</th>
                    <th class="px-4 py-3 font-semibold">Durata</th>
                    <th class="px-4 py-3 font-semibold">Scadenza effettiva</th>
                    @can('garanzie.macchina.manage')
                        <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                    @endcan
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @forelse ($garanzie as $g)
                    @php [$variante, $simbolo, $etichetta] = $statoGaranzia($g); @endphp
                    <tr wire:key="gar-{{ $g->id }}">
                        <td data-etichetta="Stato" class="px-4 py-3">
                            <x-ui.badge :variant="$variante">
                                <span aria-hidden="true">{{ $simbolo }}</span> {{ $etichetta }}
                            </x-ui.badge>
                        </td>
                        <td data-etichetta="Inizio" class="px-4 py-3 tabular-nums text-neutral-600">{{ $g->data_inizio->format('d/m/Y') }}</td>
                        <td data-etichetta="Durata" class="px-4 py-3 text-neutral-600">{{ $g->durata_mesi }} mesi</td>
                        <td data-etichetta="Scadenza effettiva" class="px-4 py-3 font-medium tabular-nums text-neutral-800">
                            {{ $g->data_scadenza_effettiva->format('d/m/Y') }}
                        </td>
                        @can('garanzie.macchina.manage')
                            <td data-azioni class="px-4 py-3 text-right whitespace-nowrap max-md:flex max-md:flex-wrap max-md:gap-x-5 max-md:text-sm">
                                <button type="button" wire:click="openModificaGaranzia({{ $g->id }})"
                                    class="text-xs font-medium text-primary-600 hover:text-primary-700">Modifica</button>
                                <button type="button" wire:click="openEliminaGaranzia({{ $g->id }})"
                                    class="ml-3 text-xs font-medium text-danger-600 hover:text-danger-800">Elimina</button>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-neutral-400">
                            Nessuna garanzia registrata.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@php
    use App\Enums\TipoScadenzaGaranzia;
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
        <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                <tr>
                    <th class="px-4 py-3 font-semibold">Stato</th>
                    <th class="px-4 py-3 font-semibold">Tipo</th>
                    <th class="px-4 py-3 font-semibold">Inizio</th>
                    <th class="px-4 py-3 font-semibold">Durata / Soglia</th>
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
                        <td class="px-4 py-3">
                            <x-ui.badge :variant="$variante">
                                <span aria-hidden="true">{{ $simbolo }}</span> {{ $etichetta }}
                            </x-ui.badge>
                        </td>
                        <td class="px-4 py-3 text-neutral-600">
                            {{ $g->tipo_scadenza === TipoScadenzaGaranzia::Data ? 'A data' : 'A ore' }}
                        </td>
                        <td class="px-4 py-3 tabular-nums text-neutral-600">{{ $g->data_inizio->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-neutral-600">
                            @if ($g->tipo_scadenza === TipoScadenzaGaranzia::Data)
                                {{ $g->durata_mesi }} mesi
                            @else
                                {{ number_format($g->soglia_ore, 0, ',', '.') }} h
                                <span class="block text-xs text-neutral-400">stima manuale (V1)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-medium tabular-nums text-neutral-800">
                            {{ $g->data_scadenza_effettiva->format('d/m/Y') }}
                        </td>
                        @can('garanzie.macchina.manage')
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="openModificaGaranzia({{ $g->id }})"
                                    class="text-xs font-medium text-primary-600 hover:text-primary-700">Modifica</button>
                                <button type="button" wire:click="openEliminaGaranzia({{ $g->id }})"
                                    class="ml-3 text-xs font-medium text-danger-600 hover:text-danger-700">Elimina</button>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-neutral-400">
                            Nessuna garanzia registrata.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

@can('letture_contaore.view')
    <x-ui.card class="mt-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-neutral-600">Letture contaore</p>
                <p class="text-xs text-neutral-400">Storico delle ore lette sulla macchina (append-only).</p>
            </div>
            @can('letture_contaore.create')
                <x-ui.button variant="secondary" wire:click="openLettura">⏱ Registra lettura</x-ui.button>
            @endcan
        </div>

        @if ($letture->isEmpty())
            <p class="mt-3 text-sm text-neutral-400">Nessuna lettura registrata.</p>
        @else
            <ul class="mt-3 divide-y divide-neutral-100">
                @foreach ($letture as $lettura)
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span class="font-medium tabular-nums text-neutral-800">
                            {{ number_format($lettura->ore, 0, ',', '.') }} h
                        </span>
                        <span class="text-xs text-neutral-400">
                            {{ $lettura->data->format('d/m/Y') }}@if ($lettura->registrataBy) · {{ $lettura->registrataBy->name }}@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
@endcan

@php
    use App\Enums\StatoIntervento;
    use Illuminate\Support\Facades\Gate;

    // Stato di riga a 3 valori (l'enum ne ha 2): colore + simbolo + etichetta,
    // perché il Design System vieta di affidare lo stato al solo colore.
    // "Imminente" non esiste qui: è una proprietà dello STRUMENTO, aggregata
    // con soglia da App\Support\Semaforo (ADR-005), non della singola riga.
    $statoRiga = fn ($i) => match (true) {
        $i->stato === StatoIntervento::Fatto => ['success', '✓', 'Fatto'],
        $i->isScaduto() => ['danger', '✗', 'Scaduto'],
        default => ['neutral', '◷', 'Pianificato'],
    };

    // La colonna azioni esiste solo per chi può agire: il Tenant (sola view)
    // vede la tabella a 5 colonne identica al punto 2.
    $mostraAzioni = Gate::any(['interventi.complete', 'interventi.update', 'interventi.delete']);
@endphp

@can('interventi.create')
    <div class="mb-3 flex justify-end">
        <x-ui.button wire:click="openNuovoIntervento">+ Nuovo intervento</x-ui.button>
    </div>
@endcan

<x-ui.card class="!p-0">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                <tr>
                    <th class="px-4 py-3 font-semibold">Stato</th>
                    <th class="px-4 py-3 font-semibold">Data</th>
                    <th class="px-4 py-3 font-semibold">Tipo</th>
                    <th class="px-4 py-3 font-semibold">Descrizione</th>
                    <th class="px-4 py-3 font-semibold">Tecnico</th>
                    @if ($mostraAzioni)
                        <th class="px-4 py-3 text-right font-semibold"><span class="sr-only">Azioni</span></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @forelse ($interventi as $i)
                    @php [$variante, $simbolo, $etichetta] = $statoRiga($i); @endphp
                    <tr wire:key="int-{{ $i->id }}">
                        <td class="px-4 py-3">
                            <x-ui.badge :variant="$variante">
                                <span aria-hidden="true">{{ $simbolo }}</span> {{ $etichetta }}
                            </x-ui.badge>
                        </td>
                        <td class="px-4 py-3 text-neutral-600 tabular-nums">
                            {{ $i->data_scadenza->format('d/m/Y') }}
                            @if ($i->data_esecuzione)
                                <span class="block text-xs text-neutral-400">Eseguito il {{ $i->data_esecuzione->format('d/m/Y') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-neutral-600">{{ ucfirst($i->tipo->value) }}</td>
                        <td class="px-4 py-3 text-neutral-800">{{ $i->descrizione }}</td>
                        <td class="px-4 py-3 text-neutral-600">{{ $i->tecnicoLabel() }}</td>
                        @if ($mostraAzioni)
                            <td class="px-4 py-3 text-right whitespace-nowrap text-xs">
                                @can('interventi.complete')
                                    @if ($i->stato === StatoIntervento::NonFatto)
                                        <button type="button" wire:click="openCompleta({{ $i->id }})" title="Segna come fatto"
                                            class="font-medium text-success-600 hover:underline">Fatto</button>
                                    @else
                                        <button type="button" wire:click="riapri({{ $i->id }})" title="Riporta a non fatto"
                                            class="font-medium text-neutral-500 hover:underline">Riapri</button>
                                    @endif
                                @endcan
                                @can('interventi.update')
                                    <button type="button" wire:click="openModificaIntervento({{ $i->id }})"
                                        class="ml-2 font-medium text-primary-600 hover:underline">Modifica</button>
                                @endcan
                                @can('interventi.delete')
                                    <button type="button" wire:click="openEliminaIntervento({{ $i->id }})"
                                        class="ml-2 font-medium text-danger-600 hover:underline">Elimina</button>
                                @endcan
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $mostraAzioni ? 6 : 5 }}" class="px-4 py-10 text-center text-sm text-neutral-400">
                            Nessuna attività registrata.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

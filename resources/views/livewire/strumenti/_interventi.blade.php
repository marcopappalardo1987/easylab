@php
    use App\Enums\StatoIntervento;

    // Stato di riga a 3 valori (l'enum ne ha 2): colore + simbolo + etichetta,
    // perché il Design System vieta di affidare lo stato al solo colore.
    // "Imminente" non esiste qui: è il motore semaforo (punto 4).
    $statoRiga = fn ($i) => match (true) {
        $i->stato === StatoIntervento::Fatto => ['success', '✓', 'Fatto'],
        $i->isScaduto() => ['danger', '✗', 'Scaduto'],
        default => ['neutral', '◷', 'Pianificato'],
    };
@endphp

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
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-neutral-400">
                            Nessuna attività registrata.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

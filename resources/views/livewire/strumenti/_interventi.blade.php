@php
    use App\Enums\StatoIntervento;
    use App\Enums\TipoIntervento;
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
        <table class="tabella-a-card w-full text-left text-sm">
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
                        <td data-etichetta="Stato" class="px-4 py-3">
                            <x-ui.badge :variant="$variante">
                                <span aria-hidden="true">{{ $simbolo }}</span> {{ $etichetta }}
                            </x-ui.badge>
                        </td>
                        <td data-etichetta="Data" class="px-4 py-3 text-neutral-600 tabular-nums">
                            {{ $i->data_scadenza->format('d/m/Y') }}
                            @if ($i->data_esecuzione)
                                <span class="block text-xs text-neutral-400">Eseguito il {{ $i->data_esecuzione->format('d/m/Y') }}</span>
                            @endif
                        </td>
                        <td data-etichetta="Tipo" class="px-4 py-3 text-neutral-600">
                            {{ $i->tipo->label() }}
                            {{-- Il certificato è la PROVA della taratura, e il posto in cui
                                 l'utente lo cerca è questa riga, non l'archivio dei documenti
                                 (ADR-009). Se manca, dirlo: «fatto» e «documentato» non sono
                                 la stessa cosa, e confonderli è ciò che rende inutile una
                                 taratura in un audit. --}}
                            @if ($i->tipo === TipoIntervento::TaraturaECertificazione)
                                @php $certificato = $certificati[$i->id] ?? null; @endphp
                                <span class="mt-1 block text-xs">
                                    @if ($certificato)
                                        @can('documenti.download')
                                            <a href="{{ route('documenti.download', $certificato) }}"
                                                class="font-medium text-primary-600 hover:text-primary-700">📄 Certificato</a>
                                        @else
                                            <span class="text-neutral-400">📄 Certificato allegato</span>
                                        @endcan
                                    @else
                                        <span class="text-warning-800">Certificato mancante</span>
                                        @can('documenti.upload')
                                            <button type="button" wire:click="openCaricaDocumento({{ $i->id }})"
                                                class="ml-1 font-medium text-primary-600 hover:text-primary-700">Allega</button>
                                        @endcan
                                    @endif
                                </span>
                            @endif
                        </td>
                        <td data-etichetta="Descrizione" class="px-4 py-3 text-neutral-800">
                            {{ $i->descrizione }}
                            {{-- Il report sta QUI e non in una colonna propria:
                                 è un testo lungo e quasi sempre assente, e una
                                 colonna vuota su ogni riga storica avrebbe
                                 stretto tutte le altre per niente. Sotto la
                                 descrizione si legge come ciò che è: il seguito
                                 di quella riga. --}}
                            @if ($i->report_fine_lavoro)
                                <span class="mt-1 block text-xs whitespace-pre-line text-neutral-500">{{ $i->report_fine_lavoro }}</span>
                            @endif
                        </td>
                        <td data-etichetta="Tecnico" class="px-4 py-3 text-neutral-600">{{ $i->tecnicoLabel() }}</td>
                        @if ($mostraAzioni)
                            <td data-azioni class="px-4 py-3 text-right whitespace-nowrap text-xs max-md:flex max-md:flex-wrap max-md:gap-x-5 max-md:text-sm">
                                @can('interventi.complete')
                                    @if ($i->stato === StatoIntervento::NonFatto)
                                        <button type="button" wire:click="openCompleta({{ $i->id }})" title="Segna come fatto"
                                            class="max-md:ml-0 max-md:inline-flex max-md:min-h-11 max-md:items-center font-medium text-success-600 hover:underline">Fatto</button>
                                    @else
                                        <button type="button" wire:click="riapri({{ $i->id }})" title="Riporta a non fatto"
                                            class="max-md:ml-0 max-md:inline-flex max-md:min-h-11 max-md:items-center font-medium text-neutral-500 hover:underline">Riapri</button>
                                    @endif
                                @endcan
                                @can('interventi.update')
                                    <button type="button" wire:click="openModificaIntervento({{ $i->id }})"
                                        class="max-md:ml-0 max-md:inline-flex max-md:min-h-11 max-md:items-center ml-2 font-medium text-primary-600 hover:underline">Modifica</button>
                                @endcan
                                @can('interventi.delete')
                                    <button type="button" wire:click="openEliminaIntervento({{ $i->id }})"
                                        class="max-md:ml-0 max-md:inline-flex max-md:min-h-11 max-md:items-center ml-2 font-medium text-danger-600 hover:underline">Elimina</button>
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

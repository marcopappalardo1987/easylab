@php
    // La riga di montaggio dice tre cose diverse, e vanno distinte a colpo
    // d'occhio: montato il giorno X, montato il giorno X *da una persona* (e
    // quindi non più riscrivibile dalla chiusura dell'intervento), oppure non
    // ancora montato. Una data inventata al posto di un'assenza è la lezione
    // pagata il 9 Ago — «montaggio ancora non effettuato» è la stessa dicitura
    // già usata nel form intervento.
    $etichettaMontaggio = function ($utilizzo) {
        if ($utilizzo->data === null) {
            return ['Montaggio ancora non effettuato', 'text-neutral-400', null];
        }

        return [
            $utilizzo->data->format('d/m/Y'),
            'text-neutral-800',
            $utilizzo->data_manuale ? 'Data corretta a mano: la chiusura dell\'intervento non la modifica.' : null,
        ];
    };
@endphp

<x-ui.card class="!p-0">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
        <div>
            <p class="text-sm font-medium text-neutral-800">Ricambi montati</p>
            <p class="text-xs text-neutral-400">
                I pezzi si registrano dal form dell'intervento; qui si leggono e si correggono.
            </p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="tabella-a-card w-full text-left text-sm">
            <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                <tr>
                    <th class="px-4 py-3 font-semibold">Pezzo</th>
                    <th class="px-4 py-3 font-semibold">Q.tà</th>
                    <th class="px-4 py-3 font-semibold">Montaggio</th>
                    <th class="px-4 py-3 font-semibold">Intervento</th>
                    @if ($vedeGaranzieRicambio)
                        <th class="px-4 py-3 font-semibold">Garanzia</th>
                    @endif
                    @if ($puoCorreggereRicambi)
                        <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @forelse ($ricambiMontati as $utilizzo)
                    @php [$testoData, $coloreData, $notaData] = $etichettaMontaggio($utilizzo); @endphp
                    <tr wire:key="ric-{{ $utilizzo->id }}">
                        <td data-etichetta="Pezzo" class="px-4 py-3 font-medium text-neutral-900">{{ $utilizzo->ricambio->nome }}</td>
                        <td data-etichetta="Q.tà" class="px-4 py-3 tabular-nums text-neutral-600">{{ $utilizzo->quantita }}</td>
                        <td data-etichetta="Montaggio" class="px-4 py-3 whitespace-nowrap {{ $coloreData }}">
                            <span @if ($notaData) title="{{ $notaData }}" @endif>
                                {{ $testoData }}
                                @if ($notaData)
                                    <span aria-hidden="true" class="text-primary-600">✎</span>
                                    <span class="sr-only">{{ $notaData }}</span>
                                @endif
                            </span>
                        </td>
                        <td data-etichetta="Intervento" class="px-4 py-3 text-neutral-600">
                            @if ($utilizzo->intervento)
                                <button type="button" x-on:click="tab = 'interventi'"
                                    class="text-left text-primary-600 hover:text-primary-700">
                                    {{ $utilizzo->intervento->descrizione }}
                                </button>
                            @else
                                {{-- `intervento_id` è nullable per le righe inserite da qui (ERD §7.2). --}}
                                <span class="text-neutral-400">—</span>
                            @endif
                        </td>
                        @if ($vedeGaranzieRicambio)
                            <td data-etichetta="Garanzia" class="px-4 py-3 whitespace-nowrap text-neutral-600">
                                @if ($utilizzo->garanzia)
                                    <span class="tabular-nums">{{ $utilizzo->garanzia->data_scadenza_effettiva->format('d/m/Y') }}</span>
                                    @if ($utilizzo->garanzia->isScaduta())
                                        <x-ui.badge variant="danger"><span aria-hidden="true">✗</span> Scaduta</x-ui.badge>
                                    @endif
                                @else
                                    <span class="text-neutral-400">—</span>
                                @endif
                            </td>
                        @endif
                        @if ($puoCorreggereRicambi)
                            <td data-azioni class="px-4 py-3 text-right whitespace-nowrap max-md:flex max-md:flex-wrap max-md:gap-x-5 max-md:text-sm">
                                <button type="button" wire:click="openCorreggiRicambio({{ $utilizzo->id }})"
                                    class="text-xs font-medium text-primary-600 hover:text-primary-700">Correggi</button>
                                @can('ricambio_utilizzo.delete')
                                    <button type="button" wire:click="openRimuoviRicambio({{ $utilizzo->id }})"
                                        class="ml-3 text-xs font-medium text-danger-600 hover:text-danger-800">Rimuovi</button>
                                @endcan
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-neutral-400">
                            Nessun ricambio montato su questa macchina.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

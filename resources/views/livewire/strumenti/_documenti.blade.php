<x-ui.card class="!p-0">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
        <div>
            <p class="text-sm font-medium text-neutral-800">Documenti</p>
            <p class="text-xs text-neutral-400">
                Manuali e certificati della macchina, e gli allegati dei singoli interventi.
            </p>
        </div>
        @can('documenti.upload')
            <x-ui.button variant="secondary" wire:click="openCaricaDocumento">+ Carica documento</x-ui.button>
        @endcan
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                <tr>
                    <th class="px-4 py-3 font-semibold">Nome</th>
                    <th class="px-4 py-3 font-semibold">Tipo</th>
                    <th class="px-4 py-3 font-semibold">Allegato a</th>
                    <th class="px-4 py-3 font-semibold">Dimensione</th>
                    <th class="px-4 py-3 font-semibold">Caricato</th>
                    <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
                @forelse ($documenti as $documento)
                    <tr wire:key="doc-{{ $documento->id }}">
                        <td class="px-4 py-3 font-medium text-neutral-900">{{ $documento->nome }}</td>
                        <td class="px-4 py-3 text-neutral-600">{{ $documento->tipo->label() }}</td>
                        <td class="px-4 py-3 text-neutral-600">
                            {{-- Dice a COSA è appeso: sulla stessa lista convivono i
                                 documenti della macchina e quelli dei suoi interventi. --}}
                            @if ($documento->documentabile instanceof App\Models\Intervento)
                                <button type="button" x-on:click="tab = 'interventi'"
                                    class="text-left text-primary-600 hover:text-primary-700">
                                    {{ $documento->documentabile->descrizione }}
                                </button>
                            @else
                                <span class="text-neutral-400">La macchina</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-neutral-600">{{ $documento->dimensioneLeggibile() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-neutral-600">
                            {{ $documento->created_at->format('d/m/Y') }}
                            @if ($documento->caricatoBy)
                                <span class="text-neutral-400">· {{ $documento->caricatoBy->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @can('documenti.download')
                                <a href="{{ route('documenti.download', $documento) }}"
                                    class="text-xs font-medium text-primary-600 hover:text-primary-700">Scarica</a>
                            @endcan
                            @can('documenti.delete')
                                <button type="button" wire:click="openEliminaDocumento({{ $documento->id }})"
                                    class="ml-3 text-xs font-medium text-danger-600 hover:text-danger-700">Elimina</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-neutral-400">
                            Nessun documento allegato a questa macchina.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

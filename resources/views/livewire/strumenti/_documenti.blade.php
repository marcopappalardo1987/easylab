<x-ui.card class="!p-0">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
        <div>
            <p class="text-sm font-medium text-ink">Documenti</p>
            <p class="text-xs text-ink-3">
                Manuali e certificati della macchina, e gli allegati dei singoli interventi.
            </p>
        </div>
        @can('documenti.upload')
            <x-ui.button variant="secondary" wire:click="openCaricaDocumento">+ Carica documento</x-ui.button>
        @endcan
    </div>

    <div class="overflow-x-auto">
        <table class="tabella-a-card w-full text-left text-sm">
            <thead class="border-b border-border text-xs tracking-wide text-ink-3 uppercase">
                <tr>
                    <th class="px-4 py-3 font-semibold">Nome</th>
                    <th class="px-4 py-3 font-semibold">Tipo</th>
                    <th class="px-4 py-3 font-semibold">Allegato a</th>
                    <th class="px-4 py-3 font-semibold">Dimensione</th>
                    <th class="px-4 py-3 font-semibold">Caricato</th>
                    <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($documenti as $documento)
                    <tr wire:key="doc-{{ $documento->id }}">
                        <td data-etichetta="Nome" class="px-4 py-3 font-medium text-ink">{{ $documento->nome }}</td>
                        <td data-etichetta="Tipo" class="px-4 py-3 text-ink-2">{{ $documento->tipo->label() }}</td>
                        <td data-etichetta="Allegato a" class="px-4 py-3 text-ink-2">
                            {{-- Dice a COSA è appeso: sulla stessa lista convivono i
                                 documenti della macchina e quelli dei suoi interventi. --}}
                            @if ($documento->documentabile instanceof App\Models\Intervento)
                                <button type="button" x-on:click="tab = 'interventi'"
                                    class="text-left text-brand hover:text-brand-hover">
                                    {{ $documento->documentabile->descrizione }}
                                </button>
                            @else
                                <span class="text-ink-3">La macchina</span>
                            @endif
                        </td>
                        <td data-etichetta="Dimensione" class="px-4 py-3 whitespace-nowrap text-ink-2">{{ $documento->dimensioneLeggibile() }}</td>
                        <td data-etichetta="Caricato" class="px-4 py-3 whitespace-nowrap text-ink-2">
                            {{ $documento->created_at->format('d/m/Y') }}
                            @if ($documento->caricatoBy)
                                <span class="text-ink-3">· {{ $documento->caricatoBy->name }}</span>
                            @endif
                        </td>
                        <td data-azioni class="px-4 py-3 text-right whitespace-nowrap max-md:flex max-md:flex-wrap max-md:gap-x-5 max-md:text-sm">
                            @can('documenti.download')
                                <a href="{{ route('documenti.download', $documento) }}"
                                    class="text-xs font-medium text-brand hover:text-brand-hover">Scarica</a>
                            @endcan
                            @can('documenti.delete')
                                <button type="button" wire:click="openEliminaDocumento({{ $documento->id }})"
                                    class="ml-3 text-xs font-medium text-bad-dot hover:brightness-90">Elimina</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-ink-3">
                            Nessun documento allegato a questa macchina.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>

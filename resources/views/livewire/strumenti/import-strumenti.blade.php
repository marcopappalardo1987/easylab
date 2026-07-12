<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

    <a href="{{ route('strumenti.index') }}" wire:navigate class="text-sm text-neutral-500 hover:text-neutral-800">‹ Torna agli strumenti</a>
    <h1 class="mt-3 text-2xl font-bold tracking-tight text-neutral-900">Importa strumenti da CSV</h1>

    {{-- Esito import --}}
    @if ($importate !== null)
        <x-ui.card class="mt-6 border-success-600/30 bg-success-100">
            <p class="text-sm font-medium text-success-600">
                Importati {{ $importate }} strumenti.
                @if ($scartate > 0) {{ $scartate }} righe scartate (con errori). @endif
            </p>
            <div class="mt-4 flex gap-3">
                <x-ui.button :href="route('strumenti.index')">Vai all'elenco</x-ui.button>
                <x-ui.button variant="secondary" wire:click="ricarica">Importa un altro file</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Istruzioni --}}
    <x-ui.card class="mt-6">
        <h2 class="text-sm font-semibold text-neutral-900">Formato del file</h2>
        <p class="mt-1 text-sm text-neutral-600">
            CSV (separatore <code class="rounded bg-neutral-100 px-1">;</code> o <code class="rounded bg-neutral-100 px-1">,</code>), prima riga con le intestazioni:
        </p>
        <pre class="mt-3 overflow-x-auto rounded-md bg-neutral-100 p-3 text-xs text-neutral-700">nome;modello;matricola;data_installazione;ubicazione;provenienza
Autoclave AC-200;AC-200;SN-0001;2015-03-01;Terapia intensiva;
Incubatrice INC-9;INC-9;SN-0002;01/07/2020;Neonatologia &gt; Terapia intensiva;Ospedale San Paolo</pre>
        <ul class="mt-3 space-y-1 text-sm text-neutral-600">
            <li>• <strong>nome</strong> e <strong>ubicazione</strong> sono obbligatori; gli altri campi sono facoltativi.</li>
            <li>• <strong>ubicazione</strong>: nome del dipartimento/laboratorio. Se esistono nodi omonimi, usa il percorso (<code class="rounded bg-neutral-100 px-1">Dipartimento &gt; Laboratorio</code>).</li>
            <li>• <strong>data_installazione</strong>: <code class="rounded bg-neutral-100 px-1">AAAA-MM-GG</code> oppure <code class="rounded bg-neutral-100 px-1">GG/MM/AAAA</code>.</li>
            <li>• <strong>provenienza</strong>: ente esterno da cui arriva la macchina → registra un movimento di <em>ingresso</em>.</li>
            <li>• Massimo {{ \App\Livewire\Strumenti\ImportStrumenti::MAX_RIGHE }} righe per file (max 2 MB).</li>
        </ul>
        <x-ui.button variant="secondary" class="mt-4" wire:click="scaricaTemplate">⬇ Scarica template CSV</x-ui.button>
    </x-ui.card>

    {{-- Upload --}}
    @unless ($analizzato)
        <x-ui.card class="mt-4">
            <label for="file" class="block text-sm font-medium text-neutral-800">File CSV</label>
            <input id="file" type="file" wire:model="file" accept=".csv,text/csv,text/plain"
                class="mt-2 block w-full text-sm text-neutral-700 file:mr-3 file:rounded-md file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100">
            @error('file') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror

            <div wire:loading wire:target="file" class="mt-2 text-sm text-neutral-400">Caricamento…</div>

            <x-ui.button class="mt-4" wire:click="analizza" wire:loading.attr="disabled" wire:target="analizza,file">
                Analizza
            </x-ui.button>
        </x-ui.card>
    @endunless

    {{-- Anteprima --}}
    @if ($analizzato)
        <x-ui.card class="mt-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <x-ui.badge variant="success">{{ $valide }} valide</x-ui.badge>
                    @if ($conErrori > 0)
                        <x-ui.badge variant="danger">{{ $conErrori }} con errori</x-ui.badge>
                    @endif
                </div>
                <div class="flex gap-2">
                    <x-ui.button variant="secondary" wire:click="ricarica">Carica un altro file</x-ui.button>
                    @if ($valide > 0)
                        <x-ui.button wire:click="importa">Importa {{ $valide }} righe valide</x-ui.button>
                    @else
                        <x-ui.button disabled>Nessuna riga valida</x-ui.button>
                    @endif
                </div>
            </div>

            @if ($troncato)
                <p class="mt-3 rounded-md border border-warning-500/30 bg-warning-100 px-3 py-2 text-sm text-warning-800">
                    Il file supera {{ \App\Livewire\Strumenti\ImportStrumenti::MAX_RIGHE }} righe: sono state considerate solo le prime.
                </p>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-neutral-200 text-xs tracking-wide text-neutral-400 uppercase">
                        <tr>
                            <th class="px-3 py-2 font-semibold">Riga</th>
                            <th class="px-3 py-2 font-semibold">Nome</th>
                            <th class="px-3 py-2 font-semibold">Ubicazione</th>
                            <th class="px-3 py-2 font-semibold">Esito</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($righe as $riga)
                            <tr wire:key="riga-{{ $riga['numero'] }}" @class(['bg-danger-100/40' => $riga['errori'] !== []])>
                                <td class="px-3 py-2 text-neutral-400">{{ $riga['numero'] }}</td>
                                <td class="px-3 py-2 text-neutral-800">{{ $riga['dati']['nome'] ?: '—' }}</td>
                                <td class="px-3 py-2 text-neutral-600">{{ $riga['nodoNome'] ?? ($riga['dati']['ubicazione'] ?: '—') }}</td>
                                <td class="px-3 py-2">
                                    @if ($riga['errori'] === [])
                                        <span class="text-success-600">✔ valida</span>
                                    @else
                                        <span class="text-danger-600">✖ {{ implode(' · ', $riga['errori']) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>

<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

    <a href="{{ route('strumenti.index') }}" wire:navigate class="text-sm text-ink-3 hover:text-ink">‹ Torna agli strumenti</a>
    <h1 class="mt-3 text-2xl font-bold tracking-tight text-ink">Importa strumenti da CSV</h1>

    {{-- Esito import --}}
    @if ($importate !== null)
        {{-- ⚠️ `!` davanti a bordo/fondo: senza, `bg-ok-soft` perde contro il
             `bg-surface` interno di `x-ui.card` nell'ordine alfabetico del
             foglio generato (o < s), e la card resta bianca/scura di base. --}}
        <x-ui.card class="mt-6 !border-ok-dot !bg-ok-soft">
            <p class="text-sm font-medium text-ok-soft-ink">
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
        <h2 class="text-sm font-semibold text-ink">Formato del file</h2>
        <p class="mt-1 text-sm text-ink-2">
            CSV (separatore <code class="rounded bg-surface-code px-1">;</code> o <code class="rounded bg-surface-code px-1">,</code>), prima riga con le intestazioni:
        </p>
        {{-- Superficie monospazio (DS §8.2, `--surface-code`): stessa famiglia dello
             stack trace della scheda errore e della matrice dei ruoli. --}}
        <pre class="mt-3 overflow-x-auto rounded-md bg-surface-code p-3 text-xs text-ink-2">nome;modello;matricola;data_installazione;ubicazione;provenienza
Autoclave AC-200;AC-200;SN-0001;2015-03-01;Terapia intensiva;
Incubatrice INC-9;INC-9;SN-0002;01/07/2020;Neonatologia &gt; Terapia intensiva;Ospedale San Paolo</pre>
        <ul class="mt-3 space-y-1 text-sm text-ink-2">
            <li>• <strong>nome</strong> e <strong>ubicazione</strong> sono obbligatori; gli altri campi sono facoltativi.</li>
            <li>• <strong>ubicazione</strong>: nome del laboratorio o del sotto-laboratorio. Se esistono nodi omonimi, usa il percorso (<code class="rounded bg-surface-code px-1">Laboratorio &gt; Sotto-laboratorio</code>).</li>
            <li>• <strong>data_installazione</strong>: <code class="rounded bg-surface-code px-1">AAAA-MM-GG</code> oppure <code class="rounded bg-surface-code px-1">GG/MM/AAAA</code>.</li>
            <li>• <strong>provenienza</strong>: ente esterno da cui arriva la macchina → registra un movimento di <em>ingresso</em>.</li>
            <li>• Massimo {{ \App\Livewire\Strumenti\ImportStrumenti::MAX_RIGHE }} righe per file (max 2 MB).</li>
        </ul>
        <x-ui.button variant="secondary" class="mt-4" wire:click="scaricaTemplate">⬇ Scarica template CSV</x-ui.button>
    </x-ui.card>

    {{-- Upload --}}
    @unless ($analizzato)
        <x-ui.card class="mt-4">
            {{-- 🔗 ADR-046: l'ubicazione si risolve per nome, quindi chi segue
                 più sedi dichiara prima in quale sta importando. --}}
            @if ($sedi->isNotEmpty())
                <div class="mb-4">
                    <label for="sede-import" class="block text-sm font-medium text-ink">Sede in cui importare</label>
                    <select id="sede-import" wire:model="sedeId"
                            class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                        <option value="">Scegli la sede…</option>
                        @foreach ($sedi as $sede)
                            <option value="{{ $sede->id }}">{{ \App\Support\Tenancy\SediSeguite::etichetta($sede) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-ink-3">Le ubicazioni del file vengono cercate fra i reparti di questa sede.</p>
                    @error('sedeId') <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p> @enderror
                </div>
            @endif
            <label for="file" class="block text-sm font-medium text-ink">File CSV</label>
            <input id="file" type="file" wire:model="file" accept=".csv,text/csv,text/plain"
                class="mt-2 block w-full text-sm text-ink-2 file:mr-3 file:rounded-md file:border-0 file:bg-brand-soft file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-soft-ink hover:file:bg-brand-soft-strong">
            @error('file') <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p> @enderror

            <div wire:loading wire:target="file" class="mt-2 text-sm text-ink-3">Caricamento…</div>

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
                    @if ($oltreIlTetto)
                        <x-ui.button disabled>Oltre il tetto del piano</x-ui.button>
                    @elseif ($valide > 0)
                        <x-ui.button wire:click="importa">Importa {{ $valide }} righe valide</x-ui.button>
                    @else
                        <x-ui.button disabled>Nessuna riga valida</x-ui.button>
                    @endif
                </div>
            </div>

            {{-- 🔗 ADR-049. Il tetto di strumenti del piano: l'import è tutto o
                 niente, e qui si dice quanti ce ne stanno ancora. --}}
            @if ($oltreIlTetto)
                <p class="mt-3 rounded-md border border-warn-dot bg-warn-soft px-3 py-2 text-sm text-warn-soft-ink" data-oltre-il-tetto>
                    {{ $oltreIlTetto }}
                </p>
            @endif

            @if ($troncato)
                <p class="mt-3 rounded-md border border-warn-dot bg-warn-soft px-3 py-2 text-sm text-warn-soft-ink">
                    Il file supera {{ \App\Livewire\Strumenti\ImportStrumenti::MAX_RIGHE }} righe: sono state considerate solo le prime.
                </p>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-surface-sunken text-xs tracking-wide text-ink-3 uppercase">
                        <tr>
                            <th class="px-3 py-2 font-semibold">Riga</th>
                            <th class="px-3 py-2 font-semibold">Nome</th>
                            <th class="px-3 py-2 font-semibold">Ubicazione</th>
                            <th class="px-3 py-2 font-semibold">Esito</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        {{-- L'opacità si calcola sul fondo sottostante (§brief, trappola
                             3): niente `bg-danger-100/40`, la riga con errori passa
                             direttamente al token `bg-bad-soft`. --}}
                        @foreach ($righe as $riga)
                            <tr wire:key="riga-{{ $riga['numero'] }}" @class(['bg-bad-soft' => $riga['errori'] !== []])>
                                <td class="px-3 py-2 text-ink-3">{{ $riga['numero'] }}</td>
                                <td class="px-3 py-2 text-ink">{{ $riga['dati']['nome'] ?: '—' }}</td>
                                <td class="px-3 py-2 text-ink-2">{{ $riga['nodoNome'] ?? ($riga['dati']['ubicazione'] ?: '—') }}</td>
                                <td class="px-3 py-2">
                                    @if ($riga['errori'] === [])
                                        <span class="text-ok-dot">✔ valida</span>
                                    @else
                                        <span class="text-bad-dot">✖ {{ implode(' · ', $riga['errori']) }}</span>
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

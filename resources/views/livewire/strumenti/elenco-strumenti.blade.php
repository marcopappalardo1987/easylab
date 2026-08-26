@php
    $arrow = fn ($col) => $sortBy === $col ? ($sortDir === 'asc' ? '↑' : '↓') : '';

    // Colonna "Prossima scadenza" (wireframe §1): la più vicina fra il prossimo
    // intervento aperto, la garanzia macchina (ADR-004) e la garanzia di un
    // pezzo montato (ADR-020). "Scaduto" viene dai model (isScaduto/isScaduta)
    // — definizione canonica, mai riscritta qui.
    //
    // ⚠️ Qui si mostra il DETTAGLIO, non l'aggregato del pallino: senza
    // `garanzie.ricambio.view` l'etichetta della garanzia ricambio degrada a
    // «Garanzia tra N gg», che è la dicitura del wireframe. La riga NON si
    // esclude e la data non si nasconde — è informazione sul bene del Tenant;
    // a essere protetta è la fonte, cioè l'esistenza del pezzo sostituito.
    $etichettaScadenza = function (string $tipo, $data, bool $scaduta) {
        if ($scaduta) {
            return $tipo.' — scaduta';
        }

        $giorni = (int) today()->diffInDays($data);

        return $giorni === 0 ? $tipo.' — oggi' : "{$tipo} tra {$giorni} gg";
    };

    $scadenzaLabel = function ($prossimo, $garanzia, $garanziaRicambio, bool $vedeRicambi) use ($etichettaScadenza) {
        // Ordine a parità di data: intervento, garanzia macchina, garanzia
        // ricambio. L'intervento vince perché porta con sé il tipo (Taratura e
        // certificazione, Manutenzione…), più informativo di "Garanzia"; fra le
        // due garanzie vince quella macchina, che si può nominare a chiunque.
        $candidati = [];

        if ($prossimo !== null) {
            $candidati[] = [
                $prossimo->data_scadenza,
                fn () => $etichettaScadenza($prossimo->tipo->label(), $prossimo->data_scadenza, $prossimo->isScaduto()),
            ];
        }

        if ($garanzia !== null) {
            $candidati[] = [
                $garanzia->data_scadenza_effettiva,
                fn () => $etichettaScadenza('Garanzia', $garanzia->data_scadenza_effettiva, $garanzia->isScaduta()),
            ];
        }

        if ($garanziaRicambio !== null) {
            $candidati[] = [
                $garanziaRicambio->data_scadenza_effettiva,
                fn () => $etichettaScadenza(
                    $vedeRicambi ? 'Garanzia ricambio' : 'Garanzia',
                    $garanziaRicambio->data_scadenza_effettiva,
                    $garanziaRicambio->isScaduta(),
                ),
            ];
        }

        if ($candidati === []) {
            return '—';
        }

        // Da PHP 8.0 `usort` è STABILE: a parità di data resta davanti chi è
        // stato aggiunto per primo, ed è così che la precedenza qui sopra si
        // realizza senza un secondo criterio di confronto.
        usort($candidati, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        return $candidati[0][1]();
    };
@endphp

<div class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Strumenti</h1>
        @can('strumenti.create')
            <x-ui.button variant="secondary" :href="route('strumenti.import')" wire:navigate>⬇ Importa CSV</x-ui.button>
        @endcan
    </div>

    @include('livewire.strumenti._tabs')

    {{-- Filtri --}}
    <x-ui.card class="mt-6">
        @php
            // Il select Ente compare solo se l'utente ne vede più di uno
            // (oggi mai, ADR-018; con i Rivenditori V1.1 sì).
            $mostraEnti = $enti->count() > 1;
        @endphp

        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-56 flex-1">
                <x-ui.input name="search" wire:model.live.debounce.300ms="search"
                    placeholder="Cerca per nome, modello, matricola…" />
            </div>

            @if ($mostraEnti)
                <select wire:model.live="enteId"
                    class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-56">
                    <option value="">Tutti gli Enti</option>
                    @foreach ($enti as $ente)
                        <option value="{{ $ente->id }}">{{ $ente->nome }}</option>
                    @endforeach
                </select>
            @endif

            <select wire:model.live="ubicazioneId"
                class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-64">
                <option value="">Tutte le ubicazioni</option>
                @foreach ($nodi as $nodo)
                    <option value="{{ $nodo->id }}">{{ $nodo->nome }}</option>
                @endforeach
            </select>

            {{-- Filtro semaforo (ADR-005): stato effettivo, cioè forzato se c'è. --}}
            <select wire:model.live="stato"
                class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2.5 text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-52">
                <option value="">Tutti gli stati</option>
                <option value="arancione">◐ Azione richiesta</option>
                <option value="verde">● In regola</option>
                <option value="rosso">■ Non idoneo</option>
            </select>

            {{-- Obsolescenza (ADR-014): segnalazione sull'età, indipendente dal semaforo. --}}
            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-ink-2">
                {{-- ⚠️ `border` esplicito accanto al colore: senza, la preflight di
                     Tailwind v4 (`border: 0 solid` su `*`) rende il contorno
                     inerte — il campo non aveva bordo. --}}
                <input type="checkbox" wire:model.live="soloObsoleti"
                    class="rounded border border-border-strong text-brand focus:ring-ring">
                ⏳ Solo obsoleti
            </label>
        </div>
    </x-ui.card>

    {{-- Tabella --}}
    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-surface-sunken text-xs tracking-wide text-ink-3 uppercase">
                    <tr>
                        {{-- Tutte le colonne sono ordinabili: le tre derivate (stato,
                             ubicazione, prossima scadenza) via sottoquery lato DB. --}}
                        @foreach ([
                            'stato' => 'Stato',
                            'nome' => 'Nome',
                            'modello' => 'Modello',
                            'matricola' => 'Matricola',
                            'ubicazione' => 'Ubicazione',
                            'data_installazione' => 'Installazione',
                            'prossima_scadenza' => 'Prossima scadenza',
                        ] as $col => $label)
                            <th class="px-4 py-3 font-semibold">
                                <button type="button" wire:click="sort('{{ $col }}')"
                                    class="inline-flex items-center gap-1 text-left uppercase hover:text-ink-2">
                                    {{ $label }} <span class="text-brand">{{ $arrow($col) }}</span>
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($strumenti as $s)
                        <tr wire:key="str-{{ $s->id }}" class="cursor-pointer hover:bg-surface-sunken"
                            onclick="window.location='{{ route('strumenti.show', $s) }}'">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1">
                                    <x-ui.semaforo :stato="$semafori[$s->id]" />
                                    <x-ui.semaforo-forzato :strumento="$s" />
                                </span>
                            </td>
                            <td class="px-4 py-3 font-medium text-ink">
                                <a href="{{ route('strumenti.show', $s) }}" wire:navigate class="hover:text-brand">{{ $s->nome }}</a>
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $s->modello ?: '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $s->matricola ?: '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">
                                @php $percorso = $percorsi[$s->unita_organizzativa_id] ?? []; @endphp
                                @if ($percorso)
                                    @foreach ($percorso as $segmento)
                                        @if (! $loop->first)<span class="text-ink-3">›</span>@endif
                                        <span>{{ $segmento }}</span>
                                    @endforeach
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-ink-2">
                                {{ $s->data_installazione?->format('d/m/Y') ?: '—' }}
                                <x-ui.obsoleto :strumento="$s" />
                            </td>
                            @php
                                $prossimo = $prossimi[$s->id] ?? null;
                                $garanzia = $garanzieMin[$s->id] ?? null;
                                $garanziaRicambio = $garanzieRicambioMin[$s->id] ?? null;
                                $inRitardo = $prossimo?->isScaduto() || $garanzia?->isScaduta() || $garanziaRicambio?->isScaduta();
                            @endphp
                            {{-- «In ritardo» non è la tripletta del semaforo (DS §4): è un
                                 accento testuale sulla colonna scadenza, e usa lo stesso
                                 token di lettura del semaforo 🟠 (`text-warn-soft-ink`,
                                 misurato AA nei due temi in DS §8.2). --}}
                            <td class="px-4 py-3 whitespace-nowrap {{ $inRitardo ? 'font-medium text-warn-soft-ink' : 'text-ink-2' }}">
                                {{ $scadenzaLabel($prossimo, $garanzia, $garanziaRicambio, $vedeGaranzieRicambio) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            {{-- ⚠️ **Due messaggi, perché sono due fatti diversi**: un
                                 elenco filtrato che si dichiara vuoto manda a cercare
                                 macchine che ci sono, e «Nessun risultato per i filtri»
                                 senza filtri manda a togliere un filtro che non c'è. È
                                 la stessa regola già applicata al registro degli errori
                                 e a quello di audit.

                                 La condizione **non si scrive qui**: la prima stesura lo
                                 faceva e nominava due filtri su cinque, quindi
                                 `?ubicazioneId=0` ed `?enteId=` cadevano nel ramo
                                 sbagliato. Il componente espone un predicato solo,
                                 costruito con gli stessi confronti con cui applica i
                                 filtri. --}}
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-ink-3">
                                {{ $this->haFiltriAttivi() ? 'Nessun risultato per i filtri applicati.' : 'Nessuno strumento.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-ink-2">
            <label for="perPage">Righe per pagina</label>
            <select id="perPage" wire:model.live="perPage"
                class="rounded-md border border-border-strong bg-surface py-1.5 pr-8 pl-2 text-sm text-ink focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                @foreach ($this->opzioniPerPage() as $opzione)
                    <option value="{{ $opzione }}">{{ $opzione }}</option>
                @endforeach
            </select>
            <span class="text-ink-3">
                {{ $strumenti->firstItem() ?? 0 }}–{{ $strumenti->lastItem() ?? 0 }} di {{ $strumenti->total() }}
            </span>
        </div>

        <div class="flex-1">
            {{ $strumenti->links() }}
        </div>
    </div>
</div>

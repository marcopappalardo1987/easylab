@php
    use App\Enums\TipoMotivoSemaforo;
    use App\Support\MotivoSemaforo;
    use App\Support\Semaforo;
    use Illuminate\Support\Facades\Gate;

    // Ogni motivo porta con sé la propria area di provenienza: chi non ha il
    // permesso di quell'area vede il motivo in forma NEUTRA — la scadenza sì,
    // il dettaglio e il link no (ADR-024). Il pallino è un aggregato dovuto a
    // tutti; la riga che lo causa non lo è.
    $areaVisibile = fn (MotivoSemaforo $m) => match ($m->tipo) {
        TipoMotivoSemaforo::Intervento => Gate::allows('interventi.view'),
        TipoMotivoSemaforo::GaranziaMacchina => Gate::allows('garanzie.macchina.view'),
        // ADR-029: l'ability della Policy, NON il permesso nudo — che spatie
        // concede prima che l'impostazione dell'Ente sia letta. Un test lo
        // congela, ed è così che questa riga è stata trovata.
        TipoMotivoSemaforo::GaranziaRicambio => Gate::allows('view', App\Models\Garanzia::class),
    };

    // Dove porta la freccia «›». Le garanzie ricambio non hanno ancora una
    // destinazione: il tab Ricambi nasce al blocco 5 di S4, e mandare al tab
    // Garanzie sarebbe peggio di non linkare — quel tab mostra le sole righe
    // macchina (clausola `strumento_id` di Strumento::garanzie()), quindi
    // l'utente ci arriverebbe senza trovare la riga che sta cercando.
    $tabDelMotivo = fn (MotivoSemaforo $m) => match ($m->tipo) {
        TipoMotivoSemaforo::Intervento => 'interventi',
        TipoMotivoSemaforo::GaranziaMacchina => 'garanzie',
        TipoMotivoSemaforo::GaranziaRicambio => null,
    };

    // ⚠️ La garanzia ricambio è l'unico motivo il cui TESTO dipende dal
    // permesso: «Garanzia ricambio» rivela che sulla macchina c'è un pezzo
    // sostituito, ed è esattamente il dato che ADR-004 nega al Tenant. Il
    // motivo resta però in elenco per tutti, con la sua data: il pallino è un
    // aggregato dovuto a tutti (ADR-020) e nasconderlo qui lo renderebbe
    // inspiegabile proprio a chi lo subisce. Per l'intervento e la garanzia
    // macchina non serve nulla di simile — lì la fonte non è un segreto, e a
    // essere gated sono il dettaglio e il link.
    $testoMotivo = fn (MotivoSemaforo $m, bool $visibile) => match ($m->tipo) {
        TipoMotivoSemaforo::Intervento => $m->scaduto ? 'Intervento scaduto il' : 'Intervento in scadenza il',
        TipoMotivoSemaforo::GaranziaMacchina => $m->scaduto ? 'Garanzia macchina scaduta il' : 'Garanzia macchina in scadenza il',
        TipoMotivoSemaforo::GaranziaRicambio => $visibile
            ? ($m->scaduto ? 'Garanzia ricambio scaduta il' : 'Garanzia ricambio in scadenza il')
            : ($m->scaduto ? 'Garanzia scaduta il' : 'Garanzia in scadenza il'),
    };

    $garanziaMacchina = $garanzie->first();
@endphp

<div class="grid gap-4 sm:grid-cols-2">

    {{-- 1. Stato e motivi — il cuore del tab: perché il semaforo è così. --}}
    <x-ui.card>
        <p class="text-sm font-medium text-neutral-600">Stato</p>
        <div class="mt-2">
            <x-ui.semaforo :stato="$diagnosi->stato" size="md" :label="true" />
        </div>

        @if (count($diagnosi->motivi) === 0)
            <p class="mt-3 text-sm text-neutral-400">Nessuna scadenza aperta o imminente.</p>
        @else
            <p class="mt-4 text-xs font-medium tracking-wide text-neutral-400 uppercase">
                Motivi ({{ count($diagnosi->motivi) }})
            </p>
            <ul class="mt-2 divide-y divide-neutral-100">
                @foreach ($diagnosi->motivi as $motivo)
                    @php
                        $visibile = $areaVisibile($motivo);
                        $tab = $tabDelMotivo($motivo);
                    @endphp
                    <li wire:key="motivo-{{ $motivo->tipo->value }}-{{ $motivo->riferimentoId }}"
                        class="flex items-start justify-between gap-3 py-2.5 text-sm">
                        <span class="text-neutral-800">
                            {{-- Colore + simbolo + testo, mai il solo colore (Design System §4). --}}
                            <span aria-hidden="true" class="{{ $motivo->scaduto ? 'text-danger-600' : 'text-warning-500' }}">{{ $motivo->scaduto ? '✗' : '◐' }}</span>
                            {{ $testoMotivo($motivo, $visibile) }}
                            <span class="tabular-nums">{{ $motivo->scadenza->format('d/m/Y') }}</span>
                            @if ($visibile && $motivo->dettaglio)
                                <span class="text-neutral-400">· {{ $motivo->dettaglio }}</span>
                            @endif
                        </span>
                        @if ($visibile && $tab !== null)
                            <button type="button" x-on:click="tab = '{{ $tab }}'"
                                class="shrink-0 text-xs font-medium text-primary-600 hover:text-primary-700"
                                title="Vai alla riga che lo causa">›</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- 2. Forzatura: SOLO se forzato. Mostra entrambi gli stati — quello
         forzato che vince e quello calcolato coi suoi motivi. Una forzatura non
         nasconde i problemi reali (ADR-005), e qui lo si vede. --}}
    @if ($strumento->forced_state !== null)
        <x-ui.card>
            <p class="text-sm font-medium text-neutral-600">Stato forzato</p>
            <div class="mt-2">
                <x-ui.semaforo :stato="$strumento->forced_state" size="md" :label="true" />
            </div>
            @php
                // Composto in PHP e non con @if inline: una direttiva Blade
                // attaccata a una parola ("Forzato@if") non viene compilata.
                $tracciamento = 'Forzato'
                    .($strumento->forcedBy ? ' da '.$strumento->forcedBy->name : '')
                    .($strumento->forced_at ? ' il '.$strumento->forced_at->format('d/m/Y') : '');
            @endphp
            <p class="mt-2 text-xs text-neutral-400">
                <span aria-hidden="true">⚑</span> {{ $tracciamento }}
            </p>
            @if ($strumento->forced_reason)
                <p class="mt-1 text-sm text-neutral-600">{{ $strumento->forced_reason }}</p>
            @endif
            <p class="mt-3 border-t border-neutral-100 pt-3 text-xs text-neutral-400">
                Il calcolato resta <span class="font-medium text-neutral-600">{{ $diagnosi->stato->value }}</span>:
                i motivi qui accanto valgono comunque.
            </p>
        </x-ui.card>
    @endif

    {{-- 3. Interventi: prossimo pianificato e ultimo eseguito. --}}
    @can('interventi.view')
        <x-ui.card>
            <p class="text-sm font-medium text-neutral-600">Prossimo intervento</p>
            @if ($prossimoIntervento)
                <p class="mt-1 text-sm text-neutral-800">
                    <span class="tabular-nums">{{ $prossimoIntervento->data_scadenza->format('d/m/Y') }}</span>
                    · {{ $prossimoIntervento->tipo->label() }}
                </p>
                <p class="mt-0.5 text-xs text-neutral-400">
                    {{ $prossimoIntervento->descrizione }} · {{ $prossimoIntervento->tecnicoLabel() }}
                </p>
            @else
                {{-- Anche l'assenza è un'informazione, e va scritta. --}}
                <p class="mt-1 text-sm text-neutral-400">Nessuno pianificato.</p>
            @endif

            <hr class="my-4 border-neutral-200">

            <p class="text-sm font-medium text-neutral-600">Ultimo eseguito</p>
            @if ($ultimoIntervento)
                <p class="mt-1 text-sm text-neutral-800">
                    <span class="tabular-nums">{{ $ultimoIntervento->data_esecuzione?->format('d/m/Y') }}</span>
                    · {{ $ultimoIntervento->tipo->label() }}
                </p>
            @else
                <p class="mt-1 text-sm text-neutral-400">Nessun intervento eseguito.</p>
            @endif
        </x-ui.card>
    @endcan

    {{-- 4. Garanzia macchina. Le garanzie ricambio pesano sullo stato qui sopra
         (ADR-020) ma NON hanno un blocco proprio: sono un dettaglio con un
         permesso diverso, e vivranno nel tab Ricambi (S4 blocco 5). --}}
    @can('garanzie.macchina.view')
        <x-ui.card>
            <p class="text-sm font-medium text-neutral-600">Garanzia macchina</p>
            @if ($garanziaMacchina)
                @php
                    // Stessa soglia del semaforo e stessa regola di scadenza del
                    // tab Garanzie: qui non si ricalcola nulla.
                    [$variante, $simbolo, $etichetta] = $garanziaMacchina->isScaduta()
                        ? ['danger', '✗', 'Scaduta']
                        : ($garanziaMacchina->data_scadenza_effettiva->lte(today()->addDays(Semaforo::giorniImminente()))
                            ? ['warning', '◐', 'In scadenza']
                            : ['success', '✓', 'Attiva']);
                @endphp
                <p class="mt-1 text-sm text-neutral-800">
                    Scade il <span class="tabular-nums font-medium">{{ $garanziaMacchina->data_scadenza_effettiva->format('d/m/Y') }}</span>
                </p>
                <div class="mt-2">
                    <x-ui.badge :variant="$variante">
                        <span aria-hidden="true">{{ $simbolo }}</span> {{ $etichetta }}
                    </x-ui.badge>
                </div>
            @else
                <p class="mt-1 text-sm text-neutral-400">Nessuna garanzia macchina.</p>
            @endif
        </x-ui.card>
    @endcan

    {{-- 5. Sintesi anagrafica. Il fornitore (ADR-023) entra qui in S4. --}}
    <x-ui.card>
        <p class="text-sm font-medium text-neutral-600">In sintesi</p>
        <dl class="mt-2 divide-y divide-neutral-100 text-sm">
            <div class="flex justify-between gap-4 py-2">
                <dt class="text-neutral-500">Ubicazione</dt>
                <dd class="text-right text-neutral-800">{{ $percorso }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2">
                <dt class="text-neutral-500">Installato</dt>
                <dd class="text-right text-neutral-800">
                    {{ $strumento->data_installazione?->format('m/Y') ?: '—' }}
                    {{-- L'obsolescenza è una segnalazione sull'età e NON tocca il
                         semaforo (ADR-014): sta qui, non fra i motivi. --}}
                    <x-ui.obsoleto :strumento="$strumento" />
                </dd>
            </div>
        </dl>
    </x-ui.card>

    {{-- 6. Statistiche della macchina. Ricambi montati e documenti: S4. --}}
    @can('interventi.view')
        <x-ui.card>
            <p class="text-sm font-medium text-neutral-600">Statistiche</p>
            <dl class="mt-2 divide-y divide-neutral-100 text-sm">
                <div class="flex justify-between gap-4 py-2">
                    <dt class="text-neutral-500">Interventi ultimi 12 mesi</dt>
                    <dd class="font-medium tabular-nums text-neutral-800">{{ $statInterventi['dodiciMesi'] }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-2">
                    <dt class="text-neutral-500">Scaduti non fatti</dt>
                    <dd class="font-medium tabular-nums text-neutral-800">{{ $statInterventi['scadutiAperti'] }}</dd>
                </div>
            </dl>
        </x-ui.card>
    @endcan

</div>

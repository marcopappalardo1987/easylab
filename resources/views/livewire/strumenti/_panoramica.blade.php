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

    // Dove porta la freccia «›».
    //
    // La garanzia ricambio ha una destinazione da quando esiste il tab Ricambi
    // (S4 blocco 5): fino ad allora era `null`, e mandare al tab Garanzie
    // sarebbe stato peggio di non linkare — quel tab mostra le sole righe
    // macchina (clausola `strumento_id` di Strumento::garanzie()), quindi
    // l'utente ci sarebbe arrivato senza trovare la riga cercata.
    //
    // ⚠️ Il permesso da chiedere è quello del TAB di destinazione, che non è
    // quello che rende visibile il motivo: il motivo si vede con l'ability di
    // ADR-029 sulla garanzia, il tab Ricambi è gated su `ricambio_utilizzo.view`
    // (scheda-strumento.blade.php). Oggi nessun ruolo di `config/rbac.php` ha
    // l'uno senza l'altro — verificato — ma un ruolo creato dalla UI di S6
    // potrebbe, e si ritroverebbe una freccia che non apre nulla. Una porta si
    // mostra solo se dietro c'è una stanza.
    $tabDelMotivo = fn (MotivoSemaforo $m) => match ($m->tipo) {
        TipoMotivoSemaforo::Intervento => 'interventi',
        TipoMotivoSemaforo::GaranziaMacchina => 'garanzie',
        TipoMotivoSemaforo::GaranziaRicambio => Gate::allows('ricambio_utilizzo.view') ? 'ricambi' : null,
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
        <p class="text-sm font-medium text-ink-2">Stato</p>
        <div class="mt-2">
            <x-ui.semaforo :stato="$diagnosi->stato" size="md" :label="true" />
        </div>

        @if (count($diagnosi->motivi) === 0)
            <p class="mt-3 text-sm text-ink-3">Nessuna scadenza aperta o imminente.</p>
        @else
            <p class="mt-4 text-xs font-medium tracking-wide text-ink-3 uppercase">
                Motivi ({{ count($diagnosi->motivi) }})
            </p>
            <ul class="mt-2 divide-y divide-border">
                @foreach ($diagnosi->motivi as $motivo)
                    @php
                        $visibile = $areaVisibile($motivo);
                        $tab = $tabDelMotivo($motivo);
                    @endphp
                    <li wire:key="motivo-{{ $motivo->tipo->value }}-{{ $motivo->riferimentoId }}"
                        class="flex items-start justify-between gap-3 py-2.5 text-sm">
                        <span class="text-ink">
                            {{-- Colore + simbolo + testo, mai il solo colore (Design System §4). --}}
                            <span aria-hidden="true" class="{{ $motivo->scaduto ? 'text-bad-dot' : 'text-warn-dot' }}">{{ $motivo->scaduto ? '✗' : '◐' }}</span>
                            {{ $testoMotivo($motivo, $visibile) }}
                            <span class="tabular-nums">{{ $motivo->scadenza->format('d/m/Y') }}</span>
                            @if ($visibile && $motivo->dettaglio)
                                <span class="text-ink-3">· {{ $motivo->dettaglio }}</span>
                            @endif
                        </span>
                        @if ($visibile && $tab !== null)
                            <button type="button" x-on:click="tab = '{{ $tab }}'"
                                class="shrink-0 text-xs font-medium text-brand hover:text-brand-hover"
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
            <p class="text-sm font-medium text-ink-2">Stato forzato</p>
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
            <p class="mt-2 text-xs text-ink-3">
                <span aria-hidden="true">⚑</span> {{ $tracciamento }}
            </p>
            @if ($strumento->forced_reason)
                <p class="mt-1 text-sm text-ink-2">{{ $strumento->forced_reason }}</p>
            @endif
            <p class="mt-3 border-t border-border pt-3 text-xs text-ink-3">
                Il calcolato resta <span class="font-medium text-ink-2">{{ $diagnosi->stato->value }}</span>:
                i motivi qui accanto valgono comunque.
            </p>
        </x-ui.card>
    @endif

    {{-- 3. Interventi: prossimo pianificato e ultimo eseguito. --}}
    @can('interventi.view')
        <x-ui.card>
            <p class="text-sm font-medium text-ink-2">Prossimo intervento</p>
            @if ($prossimoIntervento)
                <p class="mt-1 text-sm text-ink">
                    <span class="tabular-nums">{{ $prossimoIntervento->data_scadenza->format('d/m/Y') }}</span>
                    · {{ $prossimoIntervento->tipo->label() }}
                </p>
                <p class="mt-0.5 text-xs text-ink-3">
                    {{ $prossimoIntervento->descrizione }} · {{ $prossimoIntervento->tecnicoLabel() }}
                </p>
            @else
                {{-- Anche l'assenza è un'informazione, e va scritta. --}}
                <p class="mt-1 text-sm text-ink-3">Nessuno pianificato.</p>
            @endif

            <hr class="my-4 border-border">

            <p class="text-sm font-medium text-ink-2">Ultimo eseguito</p>
            @if ($ultimoIntervento)
                <p class="mt-1 text-sm text-ink">
                    <span class="tabular-nums">{{ $ultimoIntervento->data_esecuzione?->format('d/m/Y') }}</span>
                    · {{ $ultimoIntervento->tipo->label() }}
                </p>
            @else
                <p class="mt-1 text-sm text-ink-3">Nessun intervento eseguito.</p>
            @endif
        </x-ui.card>
    @endcan

    {{-- 4. Garanzia macchina. Le garanzie ricambio pesano sullo stato qui sopra
         (ADR-020) ma NON hanno un blocco proprio: sono un dettaglio con un
         permesso diverso, e vivono nel tab Ricambi (S4 blocco 5). --}}
    @can('garanzie.macchina.view')
        <x-ui.card>
            <p class="text-sm font-medium text-ink-2">Garanzia macchina</p>
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
                <p class="mt-1 text-sm text-ink">
                    Scade il <span class="tabular-nums font-medium">{{ $garanziaMacchina->data_scadenza_effettiva->format('d/m/Y') }}</span>
                </p>
                <div class="mt-2">
                    <x-ui.badge :variant="$variante">
                        <span aria-hidden="true">{{ $simbolo }}</span> {{ $etichetta }}
                    </x-ui.badge>
                </div>
            @else
                <p class="mt-1 text-sm text-ink-3">Nessuna garanzia macchina.</p>
            @endif
        </x-ui.card>
    @endcan

    {{-- 5. Sintesi anagrafica. --}}
    <x-ui.card>
        <p class="text-sm font-medium text-ink-2">In sintesi</p>
        <dl class="mt-2 divide-y divide-border text-sm">
            {{-- Il fornitore ha il permesso della PROPRIA area, non quello
                 della card: senza `fornitori.view` sparisce la riga, e
                 ubicazione e installazione restano. Un solo @can in testa al
                 pannello è l'errore che ADR-024 nomina per non farlo. --}}
            @can('fornitori.view')
                <div class="flex justify-between gap-4 py-2">
                    <dt class="text-ink-3">Fornitore</dt>
                    <dd class="text-right text-ink">
                        @if ($fornitore)
                            {{ $fornitore->ragione_sociale }}
                            {{-- ADR-023: `Strumento::fornitore()` è `withTrashed()`
                                 apposta perché questa riga possa dire «cestinato»
                                 invece di restare vuota — e una cella vuota si
                                 legge «fornitore mai inserito», che è un'altra
                                 cosa e manda a cercare nel posto sbagliato. --}}
                            @if ($fornitore->trashed())
                                <x-ui.badge variant="warning"><span aria-hidden="true">⌫</span> Cestinato</x-ui.badge>
                            @endif
                        @else
                            {{-- Nullable in schema per le righe storiche e per
                                 l'import, benché obbligatorio nel form. --}}
                            <span class="text-ink-3">—</span>
                        @endif
                    </dd>
                </div>
            @endcan
            <div class="flex justify-between gap-4 py-2">
                <dt class="text-ink-3">Ubicazione</dt>
                <dd class="text-right text-ink">{{ $percorso }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-2">
                <dt class="text-ink-3">Installato</dt>
                <dd class="text-right text-ink">
                    {{ $strumento->data_installazione?->format('m/Y') ?: '—' }}
                    {{-- L'obsolescenza è una segnalazione sull'età e NON tocca il
                         semaforo (ADR-014): sta qui, non fra i motivi. --}}
                    <x-ui.obsoleto :strumento="$strumento" />
                </dd>
            </div>
        </dl>
    </x-ui.card>

    {{-- 6. Statistiche della macchina: interventi, ricambi, documenti.

         Tre AREE in una card sola, e ognuna porta il proprio permesso: chi
         perde `documenti.view` perde la riga dei documenti e tiene le altre.
         Il gate d'insieme qui sotto non è una scorciatoia sulle tre — è solo
         ciò che evita di disegnare una card col titolo e nessun contenuto. --}}
    @php
        $vedeStatInterventi = Gate::allows('interventi.view');
        $vedeStatRicambi = Gate::allows('ricambio_utilizzo.view');
        $vedeStatDocumenti = Gate::allows('documenti.view');
    @endphp
    @if ($vedeStatInterventi || $vedeStatRicambi || $vedeStatDocumenti)
        <x-ui.card>
            <p class="text-sm font-medium text-ink-2">Statistiche</p>
            <dl class="mt-2 divide-y divide-border text-sm">
                @if ($vedeStatInterventi)
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-ink-3">Interventi ultimi 12 mesi</dt>
                        <dd class="font-medium tabular-nums text-ink">{{ $statInterventi['dodiciMesi'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-ink-3">Scaduti non fatti</dt>
                        <dd class="font-medium tabular-nums text-ink">{{ $statInterventi['scadutiAperti'] }}</dd>
                    </div>
                @endif

                @if ($vedeStatRicambi)
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-ink-3">Ricambi montati</dt>
                        <dd class="font-medium tabular-nums text-ink">{{ $statRicambi['montati'] }}</dd>
                    </div>

                    {{-- Riga separata e SOLO se ce n'è almeno uno: un pezzo con
                         `data` NULL è registrato ma non ancora sulla macchina
                         (ADR-020, ed è il motivo per cui non pesa sul semaforo).
                         Fonderlo col numero sopra direbbe che è montato; una
                         riga «In attesa: 0» sempre presente sarebbe rumore su
                         una macchina che non ha nulla in sospeso. --}}
                    @if ($statRicambi['inAttesa'] > 0)
                        <div class="flex justify-between gap-4 py-2">
                            <dt class="text-ink-3">In attesa di montaggio</dt>
                            <dd class="font-medium tabular-nums text-warn-soft-ink">{{ $statRicambi['inAttesa'] }}</dd>
                        </div>
                    @endif

                    {{-- ⚠️ `$vedeGaranzieRicambio` è `Gate::allows('view',
                         Garanzia::class)`, cioè l'ability della Policy: MAI
                         `@can('garanzie.ricambio.view')`, che spatie concede
                         via `Gate::before` appena il permesso è sul ruolo e che
                         scavalcherebbe l'impostazione per-Ente di ADR-029.
                         L'errore è già stato commesso una volta in questo file.

                         Sta qui e non nella card «Garanzia macchina» perché
                         quella è gated su `garanzie.macchina.view`: ospitarlo là
                         toglierebbe un dato della propria area a chi vede i
                         ricambi e non le garanzie della macchina. --}}
                    @if ($vedeGaranzieRicambio)
                        <div class="flex justify-between gap-4 py-2">
                            <dt class="text-ink-3">Ricambi coperti da garanzia</dt>
                            <dd class="font-medium tabular-nums text-ink">{{ $statRicambi['copertiDaGaranzia'] }}</dd>
                        </div>
                    @endif
                @endif

                {{-- Tutti i documenti che il tab Documenti elenca: quelli della
                     macchina E quelli dei suoi interventi, dove vivono i
                     certificati di taratura — cioè la maggior parte. Il numero
                     viene dalla collection già caricata da quel tab, così non
                     esistono due definizioni di «documenti di questa macchina»
                     libere di divergere. --}}
                @if ($vedeStatDocumenti)
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-ink-3">Documenti allegati</dt>
                        <dd class="font-medium tabular-nums text-ink">{{ $documenti->count() }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>
    @endif

</div>

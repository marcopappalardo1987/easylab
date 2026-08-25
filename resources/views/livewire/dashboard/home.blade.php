@php
    use App\Enums\StatoSemaforo;
@endphp

{{--
    La pagina di atterraggio, per tutti i ruoli (S6 — Wireframe §1).

    ⚠️ **Gatata blocco per blocco e non una volta sola in testa**, come impone la
    Policy di Code Review per le viste che compongono più aree: chi non ha il
    permesso di un'area non deve nemmeno sapere che quell'area esiste.

    ⛔ **Nessun riquadro scompone uno stato per CAUSA.** ADR-020 legittima il
    pallino come aggregato dovuto a tutti — il Tenant non vede le righe
    garanzia-ricambio ma vede l'arancione che ne deriva — e non la sua
    scomposizione: un «di cui 4 da garanzie ricambio» direbbe a un Ente su
    `visibilita_garanzie_ricambio = nascosta` quanti pezzi sostituiti ha sulle
    proprie macchine, e lo direbbe senza passare da nessuno scope, perché un
    numero non è una riga.
--}}
<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Dashboard</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ $perimetro }}</p>
    </div>

    @can('strumenti.view')
        @if ($parco->parcoVuoto())
            {{-- ⚠️ **Una frase, non quattro zeri.** Quattro zeri si leggono come
                 «il sistema è vuoto», e sarebbero la casella vuota che dice il
                 falso — un difetto che questo progetto ha già pagato due volte.
                 La frase è scelta per essere vera per TUTTI: il cliente nuovo,
                 l'Ente di piattaforma di Superadmin e Developer, il Responsabile
                 senza nodi assegnati e il Tecnico esterno senza portafoglio.
                 «Nessuno strumento in questo Ente» sarebbe falsa per gli ultimi
                 due, che un Ente pieno ce l'hanno — semplicemente non è loro. --}}
            <x-ui.card class="mt-8">
                <p class="py-6 text-center text-sm text-neutral-400">Nessuno strumento visibile.</p>
            </x-ui.card>
        @else
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                {{-- ⚠️ Ogni riquadro è un LINK all'elenco già filtrato, e il numero
                     deve combaciare con le righe che si trovano arrivando: è la
                     ragione per cui i conteggi passano dagli stessi scope del
                     filtro invece di avere una forma SQL propria. --}}
                <a href="{{ route('strumenti.index', ['stato' => StatoSemaforo::Verde->value]) }}" wire:navigate
                   class="rounded-lg transition hover:border-primary-600">
                    <x-ui.stat-tile :valore="$parco->verdi" class="h-full hover:border-primary-600">
                        <x-ui.semaforo :stato="StatoSemaforo::Verde" :label="true" />
                    </x-ui.stat-tile>
                </a>

                {{-- L'ordinamento per scadenza più vicina sopravvive dal wireframe,
                     ma sulla superficie che lo implementa già. --}}
                <a href="{{ route('strumenti.index', [
                        'stato' => StatoSemaforo::Arancione->value,
                        'sortBy' => 'prossima_scadenza',
                        'sortDir' => 'asc',
                    ]) }}" wire:navigate
                   class="rounded-lg transition hover:border-primary-600">
                    <x-ui.stat-tile :valore="$parco->arancioni" class="h-full hover:border-primary-600">
                        <x-ui.semaforo :stato="StatoSemaforo::Arancione" :label="true" />
                    </x-ui.stat-tile>
                </a>

                <a href="{{ route('strumenti.index', ['stato' => StatoSemaforo::Rosso->value]) }}" wire:navigate
                   class="rounded-lg transition hover:border-primary-600">
                    <x-ui.stat-tile :valore="$parco->rossi" class="h-full hover:border-primary-600">
                        <x-ui.semaforo :stato="StatoSemaforo::Rosso" :label="true" />
                    </x-ui.stat-tile>
                </a>

                {{-- ⚠️ Il glifo e il colore sono quelli di `x-ui.obsoleto`, che qui
                     non si può riusare: quel componente è un badge PER RIGA e si
                     auto-annulla su una macchina non obsoleta, mentre questa è
                     un'etichetta. Restano allineati i token, non il markup. --}}
                <a href="{{ route('strumenti.index', ['soloObsoleti' => 1]) }}" wire:navigate
                   class="rounded-lg transition hover:border-primary-600">
                    <x-ui.stat-tile :valore="$parco->obsoleti" :dettaglio="$parco->dettaglioObsoleti()"
                                    class="h-full hover:border-primary-600">
                        <span class="inline-flex items-center gap-1.5 text-obsolete-500">
                            <span aria-hidden="true">⏳</span> Obsoleti
                        </span>
                    </x-ui.stat-tile>
                </a>

            </div>

            {{-- ⚠️ **Quattro riquadri in fila si leggono come quattro fette di una
                 torta, e non lo sono**: i primi tre partizionano il parco, il
                 quarto è ortogonale — ADR-014 dice che l'obsolescenza «non tocca
                 il semaforo», quindi una macchina obsoleta è già contata in uno
                 dei tre. Chi prova a sommare deve trovare scritto perché non
                 torna, come già fa la cabina di regia coi propri KPI. --}}
            <p class="mt-2 text-xs text-neutral-500">
                Le prime tre coprono tutte le {{ number_format($parco->totale(), 0, ',', '.') }} macchine che vedi.
                Gli <span class="text-obsolete-500">obsoleti</span> sono una segnalazione sull'età e non uno stato
                manutentivo: sono già contati in una delle tre.
            </p>
        @endif
    @endcan

    {{-- 🔴 Cabina di regia (S6), gatata blocco per blocco e non una volta
         sola in testa alla pagina: chi non ha il permesso non deve
         nemmeno sapere che la pagina esiste.

         ⚠️ **Resta una card, ed è l'unica.** Per Superadmin e Developer il
         blocco qui sopra è legittimamente vuoto — il loro Ente di piattaforma
         non ha macchine, e il Developer non ha nemmeno un `tenant_id` — quindi
         senza questa la loro pagina di atterraggio non direbbe niente. La
         composizione che ne esce è onesta: «qui non ci sono macchine, il tuo
         lavoro è di là». Nessun redirect per ruolo, che sarebbe la seconda
         regola di instradamento che questa pagina esiste per non avere. --}}
    @can('tenants.view_all')
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('piattaforma.index') }}"
               class="rounded-lg border border-neutral-200 bg-white p-6 shadow-sm transition hover:border-primary-600">
                <h2 class="font-semibold text-neutral-900">🏢 Piattaforma</h2>
                <p class="mt-1 text-sm text-neutral-600">I clienti di EasyLab, le loro sedi e lo stato dei contratti.</p>
                <p class="mt-3 text-sm font-medium text-primary-600">Vai alla cabina di regia &rarr;</p>
            </a>
        </div>
    @endcan

</div>

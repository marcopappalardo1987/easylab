<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Registro di audit</h1>
        <p class="mt-1 text-sm text-neutral-600">
            Chi ha fatto cosa, e quando, su tutta la piattaforma.
        </p>
    </div>

    {{-- ⚠️ Non è una nota di colore: è il limite di affidabilità del dato, e
         chi legge un registro deve saperlo prima di trarne conclusioni. Fino al
         22 Ago 2026 le righe scritte durante un'impersonazione nominano
         l'impersonato e non chi stava agendo davvero. Lo storico non si
         riscrive. --}}
    <x-ui.card class="mt-4 border-warning-500 bg-warning-100">
        <p class="text-sm text-warning-800">
            <span aria-hidden="true">⚠️</span>
            Le azioni compiute <strong>durante un'impersonazione</strong> portano il nome di chi è stato
            impersonato. Dal <strong>{{ App\Support\AuditLog::ATTRIBUZIONE_AFFIDABILE_DA }}</strong> le righe
            scritte <strong>dentro una richiesta</strong> dicono anche chi c'era dietro; per quelle precedenti
            quell'informazione non esiste.
        </p>
        <p class="mt-2 text-xs text-warning-800">
            Restano senza attribuzione le scritture <strong>differite</strong> — code, comandi di console,
            webhook — perché lì non c'è una sessione da interrogare.
        </p>
    </x-ui.card>

    <x-ui.card class="mt-6 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        {{-- La direzione è l'unico ordinamento offerto, e la freccia
                             legge quella **applicata**, non la property. --}}
                        <th scope="col" class="py-3 pl-4 pr-3"
                            aria-sort="{{ $direzione === 'asc' ? 'ascending' : 'descending' }}">
                            <button type="button" wire:click="inverti" class="uppercase tracking-wide hover:text-neutral-800">
                                Quando <span aria-hidden="true">{{ $direzione === 'asc' ? '▲' : '▼' }}</span>
                            </button>
                        </th>
                        <th scope="col" class="px-3 py-3" aria-sort="none">Chi</th>
                        <th scope="col" class="px-3 py-3" aria-sort="none">Azione</th>
                        <th scope="col" class="px-3 py-3" aria-sort="none">Soggetto</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-200">
                    @forelse ($righe as $riga)
                        @php
                            $etichetta = App\Support\Audit\SoggettiAudit::etichetta($riga);
                            // `?->` e non `->`: il logger inizializza sempre `properties`, ma una
                            // riga inserita fuori da lì — una migration di correzione, un fix a
                            // mano su una tabella append-only — ha la colonna a `null`. La vista
                            // di un registro non è il posto dove cadere per un dato imperfetto.
                            $perConto = $riga->properties?->get('impersonato_da');
                        @endphp

                        <tr wire:key="attivita-{{ $riga->id }}" class="align-top hover:bg-neutral-50">
                            <td class="whitespace-nowrap py-3 pl-4 pr-3 text-neutral-600 tabular-nums">
                                {{ $riga->created_at?->format('d/m/Y H:i:s') ?? '—' }}
                            </td>

                            <td class="px-3 py-3">
                                <div>
                                    @if ($riga->causer)
                                        <span class="font-medium text-neutral-900">{{ $riga->causer->name }}</span>
                                    @else
                                        {{-- **Non** «Sistema»: sarebbe un'affermazione. Copre
                                             login falliti, console, webhook e coda. --}}
                                        <span class="text-neutral-400" title="Azione senza utente autenticato">—</span>
                                    @endif

                                    @if ($perConto)
                                        <span class="mt-0.5 block text-xs text-warning-800">
                                            per conto di {{ $impersonatori[$perConto] ?? '#'.$perConto }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-3">
                                <div>
                                    <span class="text-neutral-900">{{ $riga->description }}</span>
                                    @if ($riga->event)
                                        <x-ui.badge variant="neutral" class="ml-1">{{ App\Support\AuditLog::VERBI[$riga->event] ?? $riga->event }}</x-ui.badge>
                                    @else
                                        {{-- `event` è NULL su tutte le righe esplicite: sono
                                             atti, non eventi CRUD, e la distinzione è la sola
                                             affidabile — `description` è testo libero. --}}
                                        <x-ui.badge variant="primary" class="ml-1">Atto</x-ui.badge>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-3 text-neutral-700">
                                @if ($etichetta->sostantivo === null)
                                    <span class="text-neutral-400" title="Questa azione non ricade su una riga">nessun soggetto</span>
                                @else
                                    {{ $etichetta->testo() }}
                                    @if ($etichetta->cestinato)
                                        <x-ui.badge variant="warning" class="ml-1">cestinato</x-ui.badge>
                                    @elseif ($etichetta->mancante)
                                        <x-ui.badge variant="warning" class="ml-1">non più presente</x-ui.badge>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-neutral-500">
                                Nessuna riga nel registro.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-neutral-500">
            @if ($righe->total() > 0)
                {{ $righe->firstItem() }}–{{ $righe->lastItem() }} di {{ number_format($righe->total(), 0, ',', '.') }} righe
            @endif
        </p>

        {{ $righe->links() }}
    </div>

</div>

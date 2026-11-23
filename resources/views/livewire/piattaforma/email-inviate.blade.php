<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Email inviate</h1>
        <p class="mt-1 text-sm text-ink-2">
            Ogni email che la piattaforma ha consegnato al server di posta, a chi e quando.
        </p>
    </div>

    {{-- Che cosa vuol dire «accettata», detto prima della tabella: chi legge un
         registro di posta vuole sapere se il cliente l'ha ricevuta, e questo
         registro può dire solo se è partita da noi. --}}
    <x-ui.card class="mt-4 border-transparent bg-surface-sunken">
        <p class="text-sm text-ink-2">
            <strong>Accettata</strong> vuol dire che il server di posta l'ha presa in carico: è partita da noi,
            non è detto che sia nella casella del destinatario.
            <strong>Non partita</strong> è un'email ancora in invio dopo {{ App\Models\EmailInviata::NON_PARTITA_DOPO_MINUTI }} minuti:
            il server l'ha rifiutata o non ha risposto. Il motivo è in <em>Errori</em>.
            Il registro conserva tipo, destinatari, oggetto e mittente, mai il testo, per
            {{ App\Models\EmailInviata::GIORNI_DI_CONSERVAZIONE }} giorni: le più vecchie si cancellano ogni notte.
        </p>
    </x-ui.card>

    {{-- Filtri in query string: una vista filtrata si manda per link. --}}
    <div class="mt-8 flex flex-wrap items-end gap-3">
        <div class="min-w-56 flex-1">
            <label for="email-cerca" class="block text-sm font-medium text-ink-2">Cerca</label>
            <input id="email-cerca" type="search" wire:model.live.debounce.300ms="cerca"
                   placeholder="Destinatario o oggetto"
                   class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
        </div>

        <div>
            <label for="email-tipo" class="block text-sm font-medium text-ink-2">Tipo</label>
            <select id="email-tipo" wire:model.live="tipo"
                    class="mt-1 block rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                <option value="">Tutti</option>
                @foreach ($tipi as $chiave => $voce)
                    <option value="{{ $chiave }}">{{ $voce->nome }}</option>
                @endforeach
                <option value="{{ $this::FUORI_CATALOGO }}">Prove di invio</option>
            </select>
        </div>

        <div>
            <label for="email-stato" class="block text-sm font-medium text-ink-2">Stato</label>
            <select id="email-stato" wire:model.live="stato"
                    class="mt-1 block rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none">
                <option value="">Tutti</option>
                @foreach ($this->stati() as $valore => $etichettaStato)
                    <option value="{{ $valore }}">{{ $etichettaStato }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-surface-sunken text-left text-xs uppercase tracking-wide text-ink-3">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3">Quando</th>
                        <th scope="col" class="px-3 py-3">Tipo</th>
                        <th scope="col" class="px-3 py-3">A chi</th>
                        <th scope="col" class="px-3 py-3">Oggetto</th>
                        <th scope="col" class="px-3 py-3">Stato</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    @forelse ($righe as $riga)
                        @php $statoRiga = $riga->statoVisibile(); @endphp
                        <tr wire:key="email-{{ $riga->id }}" data-email-inviata="{{ $riga->id }}" class="align-top hover:bg-surface-sunken">
                            <td class="whitespace-nowrap py-3 pl-4 pr-3 text-ink-2 tabular-nums">
                                {{ $riga->created_at->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-3 py-3 text-ink">
                                {{ $riga->nomeTipo() }}
                            </td>
                            <td class="px-3 py-3 break-all text-ink">
                                {{ $riga->destinatari }}
                            </td>
                            <td class="px-3 py-3 text-ink-2">
                                {{ $riga->oggetto ?? '—' }}
                                <p class="mt-0.5 text-xs text-ink-3">
                                    da {{ $riga->mittente ?? '—' }}@if ($riga->mailer) · {{ $riga->mailer }}@endif
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-3 py-3">
                                @if ($statoRiga === App\Models\EmailInviata::STATO_ACCETTATA)
                                    <x-ui.badge variant="success">Accettata</x-ui.badge>
                                    <p class="mt-0.5 text-xs text-ink-3 tabular-nums">{{ $riga->accettata_at?->format('H:i:s') }}</p>
                                @elseif ($statoRiga === App\Models\EmailInviata::STATO_NON_PARTITA)
                                    <x-ui.badge variant="danger">Non partita</x-ui.badge>
                                @else
                                    <x-ui.badge variant="neutral">In invio</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-ink-3">
                                @if ($filtriApplicati)
                                    Nessuna email con questi filtri.
                                @else
                                    Nessuna email nel registro.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-ink-3">
            @if ($righe->total() > 0)
                {{ $righe->firstItem() }}–{{ $righe->lastItem() }} di {{ number_format($righe->total(), 0, ',', '.') }} email
            @endif
        </p>

        {{ $righe->links() }}
    </div>

</div>

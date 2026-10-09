{{--
    Piattaforma → Email (🔗 ADR-047).

    Due tabelle e non una: le email **informative** hanno un interruttore, quelle
    **di servizio** no — e metterle nella stessa lista con una colonna a volte
    vuota farebbe leggere un'assenza come uno stato. Ogni riga dice quando parte
    e a chi, con le parole di `CatalogoEmail`: questa vista non ne riscrive una.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Email</h1>
        <p class="mt-1 text-sm text-ink-2">
            Tutto ciò che Easy Lab scrive per posta: quando parte, a chi arriva, e se è acceso.
        </p>
    </div>

    @if (session('email'))
        <div @class([
            'mt-4 rounded-md border px-3 py-2 text-sm',
            'border-bad-dot bg-bad-soft text-bad-soft-ink' => session('emailFallita'),
            'border-ok-dot bg-ok-soft text-ok-soft-ink' => ! session('emailFallita'),
        ]) data-esito-email>{{ session('email') }}</div>
    @endif

    {{-- L'indirizzo delle prove sta in cima e una volta sola: vale per ogni
         bottone «Invia prova» della pagina. --}}
    <x-ui.card class="mt-4 !border-brand-line !bg-brand-soft">
        <div class="flex flex-wrap items-end gap-4">
            <div class="min-w-64 grow sm:grow-0">
                <label for="indirizzo-prova" class="block text-sm font-medium text-ink">Indirizzo per le prove</label>
                <input id="indirizzo-prova" type="email" wire:model="indirizzoProva"
                       placeholder="tu@esempio.it"
                       class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 shadow-sm focus:border-brand focus:ring-2 focus:ring-ring focus:outline-none sm:w-80" />
                @error('indirizzoProva')
                    <p class="mt-1 text-sm text-bad-soft-ink">{{ $message }}</p>
                @enderror
            </div>
            <p class="max-w-xl text-sm text-ink-2">
                «Invia prova» manda subito a questo indirizzo un esemplare dell'email, con dati finti e
                l'oggetto che comincia con <strong class="text-ink">[Prova]</strong>. Non tocca i dati di nessun
                cliente, e i link al suo interno non portano da nessuna parte.
            </p>
        </div>
    </x-ui.card>

    <h2 class="mt-8 text-lg font-semibold text-ink">Email informative</h2>
    <p class="mt-1 text-sm text-ink-2">
        Si accendono e si spengono qui, per tutti i clienti insieme. Chi le riceve può rinunciarvi dalle
        proprie preferenze.
    </p>

    <x-ui.card class="mt-3 !p-0">
        <div class="overflow-x-auto">
            <table class="tabella-a-card w-full text-sm">
                <thead class="border-b border-border bg-surface-sunken text-left text-xs uppercase tracking-wide text-ink-3">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3">Email</th>
                        <th scope="col" class="px-3 py-3">Quando parte</th>
                        <th scope="col" class="px-3 py-3">A chi</th>
                        <th scope="col" class="px-3 py-3">Stato</th>
                        <th scope="col" class="px-3 py-3 text-right">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($informative as $voce)
                        <tr wire:key="email-{{ $voce['tipo']->chiave }}" class="align-top hover:bg-surface-sunken"
                            data-email="{{ $voce['tipo']->chiave }}" data-attiva="{{ $voce['attiva'] ? '1' : '0' }}">
                            <td class="py-3 pl-4 pr-3 font-medium text-ink" data-etichetta="Email">{{ $voce['tipo']->nome }}</td>
                            <td class="px-3 py-3 text-ink-2" data-etichetta="Quando parte">{{ $voce['tipo']->quando }}</td>
                            <td class="px-3 py-3 text-ink-2" data-etichetta="A chi">{{ $voce['tipo']->aChi }}</td>
                            <td class="px-3 py-3" data-etichetta="Stato">
                                @if ($voce['attiva'])
                                    <x-ui.badge variant="success">Accesa</x-ui.badge>
                                @else
                                    <x-ui.badge variant="neutral">Spenta</x-ui.badge>
                                @endif
                            </td>
                            <td class="space-x-2 px-3 py-3 text-right whitespace-nowrap" data-etichetta="Azioni">
                                @if ($voce['attiva'])
                                    <x-ui.button variant="secondary" wire:click="spegni('{{ $voce['tipo']->chiave }}')">Spegni</x-ui.button>
                                @else
                                    <x-ui.button wire:click="accendi('{{ $voce['tipo']->chiave }}')">Accendi</x-ui.button>
                                @endif
                                <x-ui.button variant="ghost" wire:click="inviaProva('{{ $voce['tipo']->chiave }}')"
                                             wire:loading.attr="disabled" wire:target="inviaProva('{{ $voce['tipo']->chiave }}')">Invia prova</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <h2 class="mt-8 text-lg font-semibold text-ink">Email di servizio</h2>
    <p class="mt-1 text-sm text-ink-2">
        Senza queste qualcuno non entra, o non sa che il proprio account non c'è più: non hanno interruttore.
    </p>

    <x-ui.card class="mt-3 !p-0">
        <div class="overflow-x-auto">
            <table class="tabella-a-card w-full text-sm">
                <thead class="border-b border-border bg-surface-sunken text-left text-xs uppercase tracking-wide text-ink-3">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3">Email</th>
                        <th scope="col" class="px-3 py-3">Quando parte</th>
                        <th scope="col" class="px-3 py-3">A chi</th>
                        <th scope="col" class="px-3 py-3 text-right">Azioni</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($diServizio as $voce)
                        <tr wire:key="email-{{ $voce['tipo']->chiave }}" class="align-top hover:bg-surface-sunken"
                            data-email="{{ $voce['tipo']->chiave }}">
                            <td class="py-3 pl-4 pr-3 font-medium text-ink" data-etichetta="Email">{{ $voce['tipo']->nome }}</td>
                            <td class="px-3 py-3 text-ink-2" data-etichetta="Quando parte">{{ $voce['tipo']->quando }}</td>
                            <td class="px-3 py-3 text-ink-2" data-etichetta="A chi">{{ $voce['tipo']->aChi }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap" data-etichetta="Azioni">
                                <x-ui.button variant="ghost" wire:click="inviaProva('{{ $voce['tipo']->chiave }}')"
                                             wire:loading.attr="disabled" wire:target="inviaProva('{{ $voce['tipo']->chiave }}')">Invia prova</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>

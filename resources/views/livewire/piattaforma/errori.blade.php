{{--
    L'error tracker interno — l'elenco delle issue.

    ⚠️ **Sola lettura, e per scelta.** Non c'è un solo `wire:click` che scriva:
    i tre gesti (risolvi/ignora/riapri) vivono sulla **scheda**, che è la pagina
    in cui si è letto lo stack trace e si sa abbastanza per decidere — qui
    chiederebbero di zittire un errore senza averlo aperto. L'unico controllo
    interattivo è il filtro di stato, che è in query string: una vista filtrata
    si manda per link.

    ⚠️ Il sottotitolo non è decorazione: è la **stringa del corpo** su cui il
    positivo di `AccessoErroriTest` distingue questa pagina dalla cabina.
    Asserire su «Errori» non basterebbe — la sub-nav stampa quella parola su
    tutte e quattro le pagine, quindi un test verde direbbe soltanto che si è
    atterrati da qualche parte dentro la piattaforma.
--}}
<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <x-piattaforma.nav />

    <div class="mt-6">
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Errori</h1>
        <p class="mt-1 text-sm text-neutral-600">
            Cosa si è rotto, quante volte, e con quale contesto.
        </p>
    </div>

    {{-- ⚠️ Il limite del dato, in cima e non in fondo: chi legge questa pagina
         sta contando qualcosa, e deve sapere prima di contare che le due cifre
         della colonna «Quante volte» non misurano la stessa cosa. --}}
    <x-ui.card class="mt-4 border-info-500 bg-info-500/5">
        <p class="text-sm text-neutral-700">
            Le <strong>occorrenze</strong> sono tutte le volte che l'errore è successo; i
            <strong>contesti conservati</strong> sono le sole di cui si è tenuta la prova —
            al più {{ config('easylab.errori.contesti_per_errore') }} per errore, e non più di una
            ogni {{ config('easylab.errori.finestra_contesto_secondi') }} secondi. Un errore con
            diecimila occorrenze non ha diecimila schede da leggere.
        </p>
    </x-ui.card>

    <div class="mt-8 flex flex-wrap items-end gap-3">
        <div>
            <label for="errori-stato" class="block text-sm font-medium text-neutral-700">Stato</label>
            {{-- ⚠️ Il `value` selezionato è quello **applicato**, non la
                 property: `?stato=` arriva dalla query string e può valere
                 qualunque cosa, mentre la query ha usato la sentinella. --}}
            <select id="errori-stato" wire:model.live="stato"
                    class="mt-1 block rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">Tutti</option>
                @foreach ($stati as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected($statoAttivo === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <x-ui.card class="mt-4 !p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th scope="col" class="py-3 pl-4 pr-3">Classe</th>
                        <th scope="col" class="px-3 py-3">Dove</th>
                        <th scope="col" class="px-3 py-3">Quante volte</th>
                        <th scope="col" class="px-3 py-3">Prima / Ultima volta</th>
                        <th scope="col" class="px-3 py-3">Stato</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-neutral-200">
                    @forelse ($errori as $errore)
                        <tr wire:key="errore-{{ $errore->id }}" class="align-top hover:bg-neutral-50">
                            <td class="py-3 pl-4 pr-3">
                                {{-- Il link alla scheda sta sulla **classe** e
                                     non su una freccia in fondo alla riga: è
                                     l'identità della issue, ed è la cosa che si
                                     legge per prima. --}}
                                <a href="{{ route('piattaforma.errori.mostra', $errore) }}"
                                   class="font-medium text-primary-700 hover:underline">
                                    {{ class_basename($errore->classe) }}
                                </a>
                                {{-- Il namespace sotto, in piccolo: due
                                     `RuntimeException` di librerie diverse sono
                                     due bug diversi, e il nome corto da solo non
                                     lo direbbe. --}}
                                @if (class_basename($errore->classe) !== $errore->classe)
                                    <span class="mt-0.5 block text-xs text-neutral-500">{{ $errore->classe }}</span>
                                @endif
                            </td>

                            <td class="px-3 py-3 font-mono text-xs text-neutral-700">
                                {{-- Percorso **relativo** alla base del progetto: lo
                                     garantisce chi scrive, perché su Cloud la
                                     directory di deploy cambia a ogni release. --}}
                                {{ $errore->file }}:{{ $errore->riga }}
                            </td>

                            {{-- 🔴 Le due cifre, in un componente solo: vedi
                                 `x-errori.cifre`. --}}
                            <td class="px-3 py-3 text-neutral-700">
                                <x-errori.cifre :errore="$errore" />
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 text-neutral-600 tabular-nums">
                                <span class="block">{{ $errore->ultima_occorrenza_at?->format('d/m/Y H:i:s') ?? '—' }}</span>
                                <span class="mt-0.5 block text-xs text-neutral-500">
                                    prima volta: {{ $errore->prima_occorrenza_at?->format('d/m/Y H:i:s') ?? '—' }}
                                </span>
                            </td>

                            <td class="px-3 py-3">
                                @if ($errore->stato === 'risolto')
                                    <x-ui.badge variant="success">Risolto</x-ui.badge>
                                    {{-- ⚠️ `risoltoDa` è `nullOnDelete`: su una
                                         issue chiusa da un utente poi cancellato
                                         la relazione è vuota, e la storia dei bug
                                         sopravvive comunque a chi l'ha scritta. --}}
                                    <span class="mt-0.5 block text-xs text-neutral-500">
                                        da {{ $errore->risoltoDa?->name ?? 'un utente non più presente' }}
                                    </span>
                                @elseif ($errore->stato === 'ignorato')
                                    <x-ui.badge variant="neutral">Ignorato</x-ui.badge>
                                @else
                                    <x-ui.badge variant="danger">Aperto</x-ui.badge>
                                    @if ($errore->riaperto_automaticamente_at)
                                        {{-- Una regressione: era risolto ed è
                                             tornato. È il fatto che dice che una
                                             correzione non ha tenuto. --}}
                                        <span class="mt-0.5 block text-xs text-warning-800">
                                            riaperto il {{ $errore->riaperto_automaticamente_at->format('d/m/Y H:i') }}
                                        </span>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            {{-- Due messaggi, perché sono due fatti diversi: un
                                 tracker vuoto e un filtro che non trova mandano a
                                 fare due cose opposte. «Nessun errore con questo
                                 filtro» davanti a un tracker genuinamente vuoto
                                 manda a cercare un filtro da togliere che non
                                 c'è — e, peggio, «Nessun errore» davanti a un
                                 filtro attivo direbbe che va tutto bene. --}}
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-neutral-500">
                                @if ($statoAttivo !== '')
                                    Nessun errore con questo filtro.
                                @else
                                    Nessun errore registrato.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-neutral-500">
            @if ($errori->total() > 0)
                {{ $errori->firstItem() }}–{{ $errori->lastItem() }} di {{ number_format($errori->total(), 0, ',', '.') }} errori
            @endif
        </p>

        {{ $errori->links() }}
    </div>

</div>

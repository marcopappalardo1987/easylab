{{-- @page: margini della CARTA, non del documento — è l'unico modo di togliere
     lo spazio che il browser riserva di suo. Intestazione e piè di pagina del
     browser (data, titolo, URL) NON sono controllabili da CSS: restano una
     casella nel dialogo di stampa, e vanno tolte da lì. --}}
@push('styles')
    <style>
        @page { margin: 8mm; }
        @media print {
            /* L'etichetta parte in cima al foglio: in stampa non c'è nulla
               sopra di lei da cui distanziarsi. */
            .foglio-etichetta { padding: 0 !important; max-width: none !important; }
        }
    </style>
@endpush

<div class="foglio-etichetta mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">

    {{-- Intestazione e azioni: tutto ciò che NON va sull'adesivo. `print:hidden`
         è la ragione per cui non serve un layout di stampa separato. --}}
    <div class="print:hidden">
        <a href="{{ route('strumenti.show', $strumento) }}" wire:navigate
            class="text-sm text-neutral-500 hover:text-neutral-800">‹ Torna alla scheda</a>

        <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Etichetta QR</h1>
                <p class="mt-1 text-sm text-neutral-600">
                    Stampa e applica sulla macchina. Chi la inquadra apre la scheda
                    dopo aver fatto accesso: il codice non mostra dati da solo.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button onclick="window.print()"><span aria-hidden="true">🖨</span>&nbsp;Stampa</x-ui.button>
                @if ($puoRigenerare)
                    <x-ui.button variant="secondary" wire:click="openRigenera">Rigenera codice</x-ui.button>
                @endif
            </div>
        </div>
    </div>

    {{-- L'etichetta. Il bordo tratteggiato è la linea di taglio: si vede a
         schermo e in stampa, perché serve a chi ritaglia. --}}
    <div class="mt-6 flex justify-center print:mt-0 print:justify-start">
        <div class="w-[85mm] rounded-lg border-2 border-dashed border-neutral-300 bg-white p-6 text-center">
            <div class="mx-auto w-[55mm]">{!! $svg !!}</div>

            <p class="mt-3 text-base leading-tight font-bold text-neutral-900">{{ $strumento->nome }}</p>

            @if ($strumento->modello)
                <p class="text-sm text-neutral-600">{{ $strumento->modello }}</p>
            @endif

            @if ($strumento->matricola)
                <p class="mt-1 font-mono text-xs tracking-wide text-neutral-500">{{ $strumento->matricola }}</p>
            @endif

            <p class="mt-2 border-t border-neutral-200 pt-2 text-[10px] leading-snug text-neutral-500">
                {{ $percorso }}
            </p>
        </div>
    </div>

    {{-- L'URL in chiaro serve a due cose concrete: leggerlo quando l'adesivo è
         rovinato, e capire dove porta il codice senza doverlo inquadrare. --}}
    <div class="mt-6 print:hidden">
        <x-ui.card>
            <p class="text-sm font-medium text-neutral-600">Indirizzo contenuto nel codice</p>
            <p class="mt-1 font-mono text-xs break-all text-neutral-500">{{ $url }}</p>
            <p class="mt-3 border-t border-neutral-100 pt-3 text-xs text-neutral-400">
                Il collegamento è firmato e non scade: un'etichetta vale quanto la macchina.
                Per invalidarla — per esempio se la macchina esce dall'Ente — si rigenera il codice,
                e da quel momento le etichette stampate prima non funzionano più.
            </p>
        </x-ui.card>
    </div>

    {{-- Rigenerazione: conferma esplicita, perché la conseguenza non si deduce
         dal gesto (le etichette già applicate smettono di funzionare). --}}
    @if ($showRigeneraForm)
        <x-ui.modal title="Rigenerare il codice QR?" close="closeRigenera">
            <p class="text-sm text-neutral-600">
                Il codice attuale smetterà di funzionare: le etichette già stampate e applicate
                sulla macchina andranno sostituite. L'operazione resta registrata nel log.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeRigenera">Annulla</x-ui.button>
                <x-ui.button variant="danger" wire:click="rigenera">Rigenera</x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</div>

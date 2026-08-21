<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Piattaforma</h1>
        <p class="mt-1 text-sm text-neutral-600">
            I clienti di EasyLab, le loro sedi e lo stato dei contratti.
        </p>
    </div>

    {{-- I quattro numeri. Ognuno porta il proprio contesto sotto: un totale
         senza «di cui» è la cifra che poi viene citata da sola. --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

        <x-ui.stat-tile
            label="Ricavo mensile"
            :valore="number_format($riepilogo->mrrEuro(), 0, ',', '.').' €'"
            :dettaglio="$riepilogo->mrrBloccatoCent > 0
                ? 'a listino · '.number_format($riepilogo->mrrBloccatoEuro(), 0, ',', '.').' € fermi per insoluto'
                : 'a listino'" />

        <x-ui.stat-tile
            label="Clienti"
            :valore="$riepilogo->clienti"
            :dettaglio="$riepilogo->dettaglioClienti()
                .($riepilogo->clientiBloccati > 0 ? ' · '.$riepilogo->clientiBloccati.' 🔒' : '')" />

        <x-ui.stat-tile
            label="Sedi"
            :valore="$riepilogo->sedi"
            dettaglio="Enti dei clienti" />

        <x-ui.stat-tile
            label="Strumenti"
            :valore="$riepilogo->strumenti"
            dettaglio="Macchine di tutti i clienti" />

    </div>

    {{-- Un piano fuori catalogo non fa esplodere la pagina, ma non si nasconde
         nemmeno: chi legge deve poterlo riparare, e questa è l'unica schermata
         da cui si ripara. --}}
    @if ($riepilogo->pianiSconosciuti > 0)
        <x-ui.card class="mt-4 border-warning-500 bg-warning-100">
            <p class="text-sm text-warning-800">
                <span aria-hidden="true">⚠️</span>
                {{ $riepilogo->pianiSconosciuti }}
                {{ $riepilogo->pianiSconosciuti === 1 ? 'account ha un piano' : 'account hanno un piano' }}
                che non è più a catalogo: {{ $riepilogo->pianiSconosciuti === 1 ? 'vale' : 'valgono' }}
                0 € nel ricavo qui sopra.
            </p>
        </x-ui.card>
    @endif

    <x-ui.card class="mt-6">
        <p class="text-sm text-neutral-500">
            Da qui arriveranno l'elenco dei clienti con le loro sedi, l'accesso come cliente
            e le leve che oggi vivono solo da riga di comando: blocco per insoluto, visibilità
            delle garanzie sui ricambi, attivazione di un cliente e dati fiscali.
        </p>
    </x-ui.card>

</div>

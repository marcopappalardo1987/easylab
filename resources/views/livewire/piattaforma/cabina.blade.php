<div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Piattaforma</h1>
            <p class="mt-1 text-sm text-neutral-600">
                I clienti di EasyLab, le loro sedi e lo stato dei contratti.
            </p>
        </div>
    </div>

    {{-- Guscio del blocco C: la pagina esiste ed è gatata, il contenuto arriva
         coi blocchi successivi. Il conteggio qui sotto non è un KPI — è la
         prova visibile che la porta non-scopata si è aperta davvero. --}}
    <x-ui.card class="mt-6">
        <p class="text-sm text-neutral-600">
            {{ $clienti }} {{ $clienti === 1 ? 'cliente' : 'clienti' }} sulla piattaforma,
            {{ $sedi }} {{ $sedi === 1 ? 'sede' : 'sedi' }} in tutto.
        </p>

        <p class="mt-4 text-sm text-neutral-500">
            Da qui arriveranno i totali con l'MRR, l'elenco dei clienti con le loro sedi,
            l'accesso come cliente e le leve che oggi vivono solo da riga di comando:
            blocco per insoluto, visibilità delle garanzie sui ricambi, attivazione di un
            cliente e dati fiscali.
        </p>
    </x-ui.card>

</div>

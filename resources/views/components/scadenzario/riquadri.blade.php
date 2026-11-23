@props([
    'contatori',
    'statoAttivo' => null,
    'soglia',
])

{{-- I quattro riquadri in cima ai due Scadenzari, quello dell'Ente e quello del
     parco (🔗 ADR-005/011/037/052; DS §5.2). Uno solo per le due pagine, come la
     regola che li conta (`App\Support\Interventi\PartizioniScadenzario`): due
     copie del markup divergerebbero alla prima etichetta cambiata.

     Tre finestre di tempo — scaduti, entro la soglia di ADR-005, oltre la soglia
     sulle macchine idonee — e lo stato della macchina: «Non idonei» non guarda
     la data, e i suoi interventi scaduti o in scadenza restano anche nei primi
     due. Ognuno INDIRIZZA l'elenco, come i riquadri della dashboard.

     ⚠️ I `wire:click` girano sul componente Livewire che include questo file:
     `filtra()` esiste, con la stessa firma, in entrambi.

     Layout: 2×2 dal telefono, una fila da quattro dal desktop. Due colonne anche
     sullo schermo stretto perché i riquadri sono corti — un numero e due righe
     di testo — e quattro in colonna spingerebbero l'elenco sotto la piega.

     ⛔ Solo token **semantici** (`ring-ring`, `border-brand`): nessuna tonalità
     di scala e nessuna variante `dark:`, o `PaletteGuardrailTest` e
     `SuperficiTokenizzateGuardrailTest` diventano rossi. --}}
<div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <button type="button" wire:click="filtra('scaduti')" class="rounded-lg text-left transition">
        <x-ui.stat-tile label="Scaduti" :valore="$contatori['scaduti']"
                        class="h-full hover:border-brand {{ $statoAttivo === 'scaduti' ? 'ring-2 ring-ring' : '' }}" />
    </button>

    <button type="button" wire:click="filtra('in_scadenza')" class="rounded-lg text-left transition">
        <x-ui.stat-tile label="In scadenza" :valore="$contatori['in_scadenza']"
                        :dettaglio="'entro '.$soglia.' giorni'"
                        class="h-full hover:border-brand {{ $statoAttivo === 'in_scadenza' ? 'ring-2 ring-ring' : '' }}" />
    </button>

    <button type="button" wire:click="filtra('idonei')" class="rounded-lg text-left transition">
        <x-ui.stat-tile label="Idonei" :valore="$contatori['idonei']"
                        :dettaglio="'oltre '.$soglia.' giorni'"
                        class="h-full hover:border-brand {{ $statoAttivo === 'idonei' ? 'ring-2 ring-ring' : '' }}" />
    </button>

    <button type="button" wire:click="filtra('non_idonei')" class="rounded-lg text-left transition">
        <x-ui.stat-tile label="Non idonei" :valore="$contatori['non_idonei']"
                        dettaglio="su macchine non idonee"
                        class="h-full hover:border-brand {{ $statoAttivo === 'non_idonei' ? 'ring-2 ring-ring' : '' }}" />
    </button>
</div>

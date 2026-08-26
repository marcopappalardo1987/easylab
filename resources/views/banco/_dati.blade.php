{{-- Tabella — 🔗 DS §7, Wireframe §1, `@utility tabella-a-card` in app.css. --}}

@php
    use App\Enums\StatoSemaforo;

    // Righe finte, in memoria: alla tabella servono dei valori, non dei modelli.
    // L'unico oggetto vero è lo strumento obsoleto, che serve a `x-ui.obsoleto`.
    $obsoleto = new App\Models\Strumento;
    $obsoleto->data_installazione = today()->subYears(14);

    $righe = [
        ['matricola' => 'SC-200/2019', 'modello' => 'Compressore SC-200', 'sede' => 'Stampaggio', 'stato' => StatoSemaforo::Verde, 'scadenza' => '12/11/2026', 'obsoleto' => null],
        ['matricola' => 'TR-045/2012', 'modello' => 'Tornio TR-45', 'sede' => 'Officina', 'stato' => StatoSemaforo::Arancione, 'scadenza' => '02/09/2026', 'obsoleto' => $obsoleto],
        ['matricola' => 'PR-991/2021', 'modello' => 'Pressa PR-991', 'sede' => 'Linea 2', 'stato' => StatoSemaforo::Rosso, 'scadenza' => '18/07/2026', 'obsoleto' => null],
    ];
@endphp

@component('banco.pezzo', [
    'titolo' => 'Tabella con `tabella-a-card` — a 360px diventa una lista di schede',
    'token' => 'thead bg/text-ink-3 · divide-border · hover:bg-surface-sunken · scheda mobile: --surface, --border, --ink-3',
    'nota' => "🔴 Questo pezzo si guarda a 360px, o non si sta guardando. Sotto i 768px l'utility rende ogni riga una scheda e ogni cella espone la propria intestazione da `data-etichetta` — che è l'unica cosa scritta due volte, nel `<th>` e nell'attributo, e che `TabellaCardMobileTest` verifica identica. La cella delle azioni porta `data-azioni`: perde l'etichetta e diventa un blocco a tutta larghezza in fondo alla scheda, perché in tabella è l'ultima colonna, cioè quella che su un telefono resta fuori schermo. ⚠️ Una cella deve restare un solo figlio diretto: le due righe della colonna «Stato» stanno dentro un unico `<span>`.",
])
    <div class="w-full overflow-x-auto">
        <table class="tabella-a-card w-full text-left text-sm">
            <thead class="border-b border-border text-xs tracking-wide text-ink-3 uppercase">
                <tr>
                    <th class="px-4 py-3 font-semibold">Matricola</th>
                    <th class="px-4 py-3 font-semibold">Modello</th>
                    <th class="px-4 py-3 font-semibold">Sede</th>
                    <th class="px-4 py-3 font-semibold">Stato</th>
                    <th class="px-4 py-3 font-semibold">Prossima scadenza</th>
                    <th class="px-4 py-3 font-semibold"><span class="sr-only">Azioni</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($righe as $riga)
                    <tr class="hover:bg-surface-sunken">
                        <td data-etichetta="Matricola" class="px-4 py-3 font-medium text-ink">{{ $riga['matricola'] }}</td>
                        <td data-etichetta="Modello" class="px-4 py-3 text-ink-2">{{ $riga['modello'] }}</td>
                        <td data-etichetta="Sede" class="px-4 py-3 text-ink-2">{{ $riga['sede'] }}</td>
                        <td data-etichetta="Stato" class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5">
                                <x-ui.semaforo :stato="$riga['stato']" label />
                                @if ($riga['obsoleto'])
                                    <x-ui.obsoleto :strumento="$riga['obsoleto']" />
                                @endif
                            </span>
                        </td>
                        <td data-etichetta="Prossima scadenza" class="px-4 py-3 tabular-nums whitespace-nowrap text-ink-2">{{ $riga['scadenza'] }}</td>
                        <td data-azioni class="px-4 py-3">
                            <x-ui.button variant="secondary" href="#dati">Apri la scheda</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endcomponent

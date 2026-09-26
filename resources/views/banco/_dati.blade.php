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

{{-- I tre pezzi dei grafici — 🔗 DS §2.5 (tinte dei grafici), §8.2 (token), ADR-034.

     ⚠️ **Costruiti in memoria, come tutto il banco**: `SerieMensile` è un
     value object senza database, e le voci della composizione sono array
     letterali. Il banco resta una pagina che non tocca un dato.

     ⛔ **Perché sono qui, e non solo nella cabina.** `BancoTest` pretende che
     ogni componente della libreria sia montato in questa pagina, e non è
     burocrazia: un pezzo che vive solo dentro una schermata reale è un pezzo
     che nessuno guarda **nei due temi** — ed è nel tema scuro che i grafici
     hanno il loro punto fragile dichiarato (§2.5: verde↔arancione a ΔE 6,9). --}}

@php
    use App\Support\Piattaforma\SerieMensile;

    $andamento = new SerieMensile(
        mesi: ['2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02'],
        etichette: ['Set', 'Ott', 'Nov', 'Dic', 'Gen', 'Feb'],
        valori: [4, 6, 5, 9, 12, 14],
        base: 3,
    );

    // ⚠️ Il caso limite che il banco deve mostrare **accanto** a quello pieno:
    // su serie vuota la microlinea non disegna nulla, che è diverso dal
    // disegnare una linea piatta a zero — quella si leggerebbe come un dato.
    $serieVuota = new SerieMensile(mesi: [], etichette: [], valori: []);
@endphp

@component('banco.pezzo', [
    'titolo' => 'x-ui.sparkline — la microlinea, e la stessa a serie vuota',
    'token' => 'stroke-chart-brand · fill-chart-band · stroke-surface',
    'nota' => "⚠️ È DECORATIVA: `aria-hidden`, nessun `role`, nessuna etichetta. Chi la usa DEVE metterle accanto il numero a parole («+11 in 6 mesi»), o il dato esiste solo per chi vede. A destra la serie vuota: non disegna nulla, perché una linea piatta a zero si leggerebbe come una misura.",
])
    <div class="flex items-center gap-6">
        <span class="flex items-center gap-2">
            <span class="block w-24"><x-ui.sparkline :valori="$andamento->valori" /></span>
            <span class="text-sm tabular-nums text-ink-2">{{ $andamento->variazioneConSegno() }} in 6 mesi</span>
        </span>
        <span class="block w-24"><x-ui.sparkline :valori="$serieVuota->valori" /></span>
    </div>
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.grafico-barre — l\'andamento mensile, con la sua tabella',
    'token' => 'fill-chart-brand · text-ink-3 · tabular-nums',
    'nota' => "⛔ Sotto il disegno c'è la STESSA serie in tabella, e non è un ripiego per gli screen reader: è il modo in cui il dato si copia, si verifica e si legge quando il colore non basta (DS §2.5). Ogni barra porta un `<title>` col mese esteso e il valore.",
])
    <x-ui.grafico-barre :serie="$andamento" titolo="Nuovi strumenti" unita="strumenti" />
@endcomponent

@component('banco.pezzo', [
    'titolo' => 'x-ui.barra-composizione — le quote di un totale',
    'token' => 'bg-chart-brand · bg-chart-obsoleto · bg-chart-rosso',
    'nota' => "⛔ Ogni voce porta etichetta E glifo, mai il solo colore: in tema scuro la coppia verde↔arancione scende sotto la soglia di sicurezza (DS §2.5, ADR-034), ed è la ridondanza a renderla legittima.",
])
    <x-ui.barra-composizione
        titolo="Clienti per piano"
        unita="clienti"
        :voci="[
            ['etichetta' => 'SaaS', 'valore' => 25, 'classe' => 'bg-chart-brand', 'glifo' => '●'],
            ['etichetta' => 'Free', 'valore' => 12, 'classe' => 'bg-chart-obsoleto', 'glifo' => '◆'],
            ['etichetta' => 'Bloccati', 'valore' => 2, 'classe' => 'bg-chart-rosso', 'glifo' => '■'],
        ]" />
@endcomponent

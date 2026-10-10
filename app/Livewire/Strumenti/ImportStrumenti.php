<?php

namespace App\Livewire\Strumenti;

use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Billing\TettoStrumenti;
use App\Support\Billing\TettoStrumentiRaggiunto;
use App\Support\Tenancy\SediSeguite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import massivo strumenti da CSV (S2 stretch — onboarding clienti).
 * Flusso: carica → analizza (anteprima con errori riga-per-riga) → importa solo
 * le righe valide. I laboratori si risolvono SOLO tra i nodi visibili
 * all'utente (global scope) → nessuna scrittura cross-tenant o fuori sotto-albero.
 */
#[Layout('components.layouts.app')]
class ImportStrumenti extends Component
{
    use WithFileUploads;

    public const MAX_RIGHE = 1000;

    /** Caratteri di un valore mostrati per una riga scartata. */
    public const ANTEPRIMA_SCARTATE = 100;

    public const COLONNE = ['nome', 'modello', 'matricola', 'data_installazione', 'ubicazione', 'provenienza'];

    public $file;

    /**
     * La sede in cui si importa, per chi ne segue più di una (🔗 ADR-046).
     *
     * 🔴 L'ubicazione di una riga si risolve **per nome** fra i reparti in
     * vista: con i reparti di più clienti insieme, «Ematologia» di un cliente
     * finirebbe scambiata con quella di un altro, e il percorso esplicito
     * prenderebbe in silenzio l'ultimo dei due. L'import lavora quindi su una
     * sede alla volta, dichiarata prima dell'analisi.
     *
     * `mixed` perché arriva dal browser; non è `#[Locked]` perché è un campo.
     * A proteggere `importa()` è il lucchetto su `$righe`: i `nodoId` sono
     * stati risolti dentro la sede validata, e dopo non si possono riscrivere.
     */
    public mixed $sedeId = null;

    private ?Collection $sediCache = null;

    /*
     * `#[Locked]` sull'esito dell'analisi (S7, T1c): `importa()` si fida di
     * `$righe` — errori, dati e `nodoId` — e una proprietà pubblica è scrivibile
     * dal client. Senza il lucchetto un `$set('righe', …)` saltava ogni
     * controllo di `valutaRiga()`: nomi oltre 255 caratteri, date arbitrarie, il
     * nodo Ente. Lo scope del nodo reggeva l'isolamento, non la validazione.
     */

    /** @var list<array{numero:int,dati:array<string,string>,nodoId:?int,nodoNome:?string,errori:list<string>}> */
    #[Locked]
    public array $righe = [];

    #[Locked]
    public bool $analizzato = false;

    #[Locked]
    public bool $troncato = false;

    #[Locked]
    public ?int $importate = null;

    #[Locked]
    public ?int $scartate = null;

    public function scaricaTemplate(): StreamedResponse
    {
        $righe = [
            self::COLONNE,
            ['Autoclave AC-200', 'AC-200', 'SN-0001', '2015-03-01', 'Terapia intensiva', ''],
            ['Incubatrice INC-9', 'INC-9', 'SN-0002', '01/07/2020', 'Neonatologia > Terapia intensiva', 'Ospedale San Paolo'],
        ];

        return response()->streamDownload(function () use ($righe) {
            $out = fopen('php://output', 'w');
            foreach ($righe as $riga) {
                fputcsv($out, $riga, ';');
            }
            fclose($out);
        }, 'template-strumenti.csv', ['Content-Type' => 'text/csv']);
    }

    public function analizza(): void
    {
        $this->authorize('strumenti.create');

        $regole = ['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt']];

        $vaScelta = $this->sedi()->count() > 1;

        if ($vaScelta) {
            $regole['sedeId'] = ['required', Rule::in($this->sedi()->modelKeys())];
        }

        $this->validate($regole, attributes: ['sedeId' => 'sede']);

        // Un CSV è testo: un byte NUL vuol dire un binario rinominato, e le sue
        // "righe" finirebbero nell'anteprima e nel database come spazzatura.
        $contenuto = (string) file_get_contents($this->file->getRealPath());
        if (str_contains($contenuto, "\0")) {
            $this->addError('file', 'Il file non è un CSV di testo.');

            return;
        }

        // Il BOM via PRIMA di decidere la codifica (T1cA-5): con un solo byte
        // Windows-1252 nel resto del file, la conversione trasformerebbe il BOM
        // in «ï»¿» e l'intestazione `nome` non si troverebbe più.
        $contenuto = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contenuto);

        $this->reset(['righe', 'importate', 'scartate', 'troncato', 'analizzato']);

        $lette = $this->leggiCsv($this->inUtf8($contenuto));
        [$perNome, $perPercorso] = $this->mappeNodi($vaScelta ? (int) $this->sedeId : null);

        foreach ($lette as $i => $dati) {
            $this->righe[] = $this->valutaRiga($i + 2, $dati, $perNome, $perPercorso); // +2: riga 1 = intestazione
        }

        $this->analizzato = true;
    }

    public function importa(): void
    {
        $this->authorize('strumenti.create');

        $valide = collect($this->righe)->filter(fn ($r) => $r['errori'] === []);
        if ($valide->isEmpty()) {
            return;
        }

        try {
            DB::transaction(fn () => $this->scrivi($valide));
        } catch (TettoStrumentiRaggiunto) {
            // Niente da dire qui: l'anteprima resta aperta e `render()` rilegge
            // il tetto, quindi la spiegazione è già sotto gli occhi di chi ha
            // premuto. Non si è scritta nessuna riga.
            return;
        }

        $this->importate = $valide->count();
        $this->scartate = count($this->righe) - $this->importate;
        $this->reset(['righe', 'file', 'analizzato']);
    }

    /**
     * Le sedi toccate dalle righe valide, con quante righe ciascuna.
     *
     * Oggi è sempre una sola: l'import lavora su una sede alla volta. Si
     * raggruppa lo stesso, perché il tetto di strumenti (🔗 ADR-049) si chiede
     * alla sede in cui la riga finisce davvero, non a quella che ci si aspetta.
     *
     * @param  Collection<int,array{nodoId:?int}>  $valide
     * @return array<int,int> id della sede → righe
     */
    private function righePerSede(Collection $valide): array
    {
        $sedeDelNodo = UnitaOrganizzativa::whereKey($valide->pluck('nodoId')->unique()->all())
            ->pluck('tenant_id', 'id');

        // `get()` e non l'indice: un reparto sparito fra l'analisi e l'import
        // non deve diventare un errore qui. Lo dice il `findOrFail` di
        // `scrivi()`, col 404 di sempre.
        return $valide
            ->countBy(fn (array $riga) => (int) $sedeDelNodo->get($riga['nodoId']))
            ->all();
    }

    /**
     * Che cosa impedisce l'import, se il piano non ha posto per tutte le righe
     * valide. `null` = si può importare.
     */
    private function oltreIlTetto(Collection $valide): ?string
    {
        foreach ($this->righePerSede($valide) as $sede => $nuovi) {
            $tetto = TettoStrumenti::dellEnte($sede);

            if (! $tetto->consente($nuovi)) {
                return $tetto->spiegazione($nuovi);
            }
        }

        return null;
    }

    /** @param  Collection<int,array{dati:array<string,string>,nodoId:?int}>  $valide */
    private function scrivi(Collection $valide): void
    {
        // 🔴 ADR-049: tutto o niente, e **prima** della prima riga. Importarne
        // «quante ce ne stanno» lascerebbe a chi ha caricato il file il compito
        // di scoprire quali sono rimaste fuori.
        foreach ($this->righePerSede($valide) as $sede => $nuovi) {
            TettoStrumenti::esigi($sede, $nuovi);
        }

        foreach ($valide as $riga) {
            $nodo = UnitaOrganizzativa::findOrFail($riga['nodoId']); // in scope
            $dati = $riga['dati'];

            $strumento = Strumento::create([
                'tenant_id' => $nodo->tenant_id,
                'unita_organizzativa_id' => $nodo->id,
                'nome' => $dati['nome'],
                'modello' => $dati['modello'] ?: null,
                'matricola' => $dati['matricola'] ?: null,
                'data_installazione' => $this->parseData($dati['data_installazione']),
            ]);

            if (filled($dati['provenienza'])) {
                SpostamentoStrumento::create([
                    'tenant_id' => $nodo->tenant_id,
                    'strumento_id' => $strumento->id,
                    'da_esterno' => trim($dati['provenienza']),
                    'a_nodo_id' => $nodo->id,
                    'tipo_spostamento' => TipoSpostamento::Ingresso,
                    'data' => now()->toDateString(),
                    'eseguito_da' => auth()->id(),
                ]);
            }
        }
    }

    public function ricarica(): void
    {
        $this->reset(['file', 'righe', 'analizzato', 'importate', 'scartate', 'troncato', 'sedeId']);
    }

    // --- Parsing ---

    /**
     * Legge il CSV: rimuove il BOM, rileva il delimitatore, normalizza le
     * intestazioni e restituisce le righe come mappa colonna→valore.
     *
     * @return list<array<string,string>>
     */
    protected function leggiCsv(string $contenuto): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [];
        }
        fwrite($handle, $contenuto);
        rewind($handle);

        $primaRiga = fgets($handle);
        if ($primaRiga === false) {
            fclose($handle);

            return [];
        }

        $primaRiga = preg_replace('/^\xEF\xBB\xBF/', '', $primaRiga); // BOM
        $delimitatore = substr_count($primaRiga, ';') >= substr_count($primaRiga, ',') ? ';' : ',';

        $intestazioni = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            str_getcsv(rtrim($primaRiga, "\r\n"), $delimitatore)
        );

        $righe = [];
        while (($valori = fgetcsv($handle, 0, $delimitatore)) !== false) {
            if ($valori === [null] || $valori === false) {
                continue; // riga vuota
            }
            if (count(array_filter($valori, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            if (count($righe) >= self::MAX_RIGHE) {
                $this->troncato = true;
                break;
            }

            $dati = [];
            foreach (self::COLONNE as $colonna) {
                $idx = array_search($colonna, $intestazioni, true);
                $dati[$colonna] = $idx !== false ? trim((string) ($valori[$idx] ?? '')) : '';
            }
            $righe[] = $dati;
        }

        fclose($handle);

        return $righe;
    }

    /**
     * Il contenuto in UTF-8, qualunque cosa abbia salvato il foglio di calcolo.
     *
     * L'Excel italiano esporta «CSV (delimitato)» in Windows-1252: «Unità» ha
     * un byte 0xE0 che non è UTF-8 valido. Passato così com'è rompeva la
     * serializzazione JSON del componente (500 all'analisi) e, su Postgres,
     * l'INSERT. Un file è in una codifica sola: si decide sul file intero, non
     * cella per cella.
     */
    private function inUtf8(string $contenuto): string
    {
        return mb_check_encoding($contenuto, 'UTF-8')
            ? $contenuto
            : mb_convert_encoding($contenuto, 'UTF-8', 'Windows-1252');
    }

    /**
     * Mappe per risolvere l'ubicazione: per nome (con ambiguità) e per percorso.
     * Costruite dai nodi SCOPATI → isolamento e sotto-albero automatici.
     *
     * @return array{0: array<string, Collection>, 1: array<string, UnitaOrganizzativa>}
     */
    protected function mappeNodi(?int $sede = null): array
    {
        // `$sede` valorizzata solo per chi segue più sedi (ADR-046): per tutti
        // gli altri lo scope contiene già una sede sola.
        $nodi = UnitaOrganizzativa::query()
            ->when($sede !== null, fn ($q) => $q->where('tenant_id', $sede))
            ->get();
        $byId = $nodi->keyBy('id');

        $nonEnte = $nodi->reject(fn ($n) => $n->tipo === TipoUnitaOrganizzativa::Ente);

        $perNome = $nonEnte->groupBy(fn ($n) => mb_strtolower(trim($n->nome)))->all();

        $perPercorso = [];
        foreach ($nonEnte as $nodo) {
            $catena = [];
            $corrente = $nodo;
            while ($corrente !== null && $corrente->tipo !== TipoUnitaOrganizzativa::Ente) {
                array_unshift($catena, mb_strtolower(trim($corrente->nome)));
                $corrente = $corrente->parent_id !== null ? $byId->get($corrente->parent_id) : null;
            }
            $perPercorso[implode(' > ', $catena)] = $nodo;
        }

        return [$perNome, $perPercorso];
    }

    /**
     * @param  array<string, Collection>  $perNome
     * @param  array<string, UnitaOrganizzativa>  $perPercorso
     */
    protected function valutaRiga(int $numero, array $dati, array $perNome, array $perPercorso): array
    {
        $errori = [];

        if ($dati['nome'] === '') {
            $errori[] = 'Nome mancante';
        } elseif (mb_strlen($dati['nome']) > 255) {
            $errori[] = 'Nome troppo lungo';
        }

        foreach (['modello', 'matricola', 'provenienza'] as $campo) {
            if (mb_strlen($dati[$campo]) > 255) {
                $errori[] = ucfirst($campo).' troppo lungo';
            }
        }

        if ($dati['data_installazione'] !== '' && $this->parseData($dati['data_installazione']) === null) {
            $errori[] = 'Data non valida (usa AAAA-MM-GG o GG/MM/AAAA)';
        }

        $nodo = null;
        if ($dati['ubicazione'] === '') {
            $errori[] = 'Ubicazione mancante';
        } else {
            $chiave = mb_strtolower(trim($dati['ubicazione']));

            if (str_contains($chiave, '>')) {
                // Percorso esplicito: normalizza gli spazi attorno al separatore.
                $chiave = implode(' > ', array_map('trim', explode('>', $chiave)));
                $nodo = $perPercorso[$chiave] ?? null;
                if ($nodo === null) {
                    $errori[] = 'Ubicazione non trovata: '.Str::limit($dati['ubicazione'], self::ANTEPRIMA_SCARTATE);
                }
            } else {
                $candidati = $perNome[$chiave] ?? collect();
                if ($candidati->isEmpty()) {
                    $errori[] = 'Ubicazione non trovata: '.Str::limit($dati['ubicazione'], self::ANTEPRIMA_SCARTATE);
                } elseif ($candidati->count() > 1) {
                    $errori[] = 'Ubicazione ambigua: usa il percorso (es. "Dipartimento > '.$dati['ubicazione'].'")';
                } else {
                    $nodo = $candidati->first();
                }
            }
        }

        // Una riga scartata si mostra e basta: i suoi valori finiscono nello
        // snapshot di Livewire, e un campo da 1,5 MB lo porterebbe oltre il
        // limite del payload (T1cB-9). Le righe valide sono già entro 255.
        if ($errori !== []) {
            $dati = array_map(fn (string $v) => Str::limit($v, self::ANTEPRIMA_SCARTATE), $dati);
        }

        return [
            'numero' => $numero,
            'dati' => $dati,
            'nodoId' => $nodo?->id,
            'nodoNome' => $nodo?->nome,
            'errori' => $errori,
        ];
    }

    protected function parseData(string $valore): ?string
    {
        $valore = trim($valore);
        if ($valore === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y'] as $formato) {
            try {
                $data = CarbonImmutable::createFromFormat($formato, $valore);
                // Anno ≥ 1 (T1cA-4/T1cB-6): «0000-01-01» fa il giro del formato
                // ma Postgres rifiuta l'anno zero, e il form manuale lo scarta.
                if ($data !== false && $data->format($formato) === $valore && $data->year >= 1) {
                    return $data->toDateString();
                }
            } catch (\Throwable) {
                // formato successivo
            }
        }

        return null;
    }

    public function render()
    {
        $righeValide = collect($this->righe)->filter(fn ($r) => $r['errori'] === []);
        $valide = $righeValide->count();

        return view('livewire.strumenti.import-strumenti', [
            'valide' => $valide,
            'conErrori' => count($this->righe) - $valide,
            // 🔗 ADR-049: se il piano non ha posto per tutte le righe valide lo
            // si dice nell'anteprima, al posto del bottone. Solo con righe da
            // importare: prima dell'analisi non costa nessuna query.
            'oltreIlTetto' => $valide > 0 ? $this->oltreIlTetto($righeValide) : null,
            // Vuota per chi lavora su una sede sola: niente tendina.
            'sedi' => $this->sedi()->count() > 1 ? $this->sedi() : collect(),
        ]);
    }

    /**
     * Le sedi in vista, una volta per richiesta e solo per chi può seguirne più
     * di una: per tutti gli altri nessuna query (stessa forma di
     * `ElencoFornitori::sedi()`).
     *
     * @return Collection<int,UnitaOrganizzativa>
     */
    private function sedi(): Collection
    {
        return $this->sediCache ??= SediSeguite::piuClienti() ? SediSeguite::elenco() : collect();
    }
}

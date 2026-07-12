<?php

namespace App\Livewire\Strumenti;

use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
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

    public const COLONNE = ['nome', 'modello', 'matricola', 'data_installazione', 'ubicazione', 'provenienza'];

    public $file;

    /** @var list<array{numero:int,dati:array<string,string>,nodoId:?int,nodoNome:?string,errori:list<string>}> */
    public array $righe = [];

    public bool $analizzato = false;

    public bool $troncato = false;

    public ?int $importate = null;

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

        $this->validate([
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
        ]);

        $this->reset(['righe', 'importate', 'scartate', 'troncato']);

        $lette = $this->leggiCsv($this->file->getRealPath());
        [$perNome, $perPercorso] = $this->mappeNodi();

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

        DB::transaction(function () use ($valide) {
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
        });

        $this->importate = $valide->count();
        $this->scartate = count($this->righe) - $this->importate;
        $this->reset(['righe', 'file', 'analizzato']);
    }

    public function ricarica(): void
    {
        $this->reset(['file', 'righe', 'analizzato', 'importate', 'scartate', 'troncato']);
    }

    // --- Parsing ---

    /**
     * Legge il CSV: rimuove il BOM, rileva il delimitatore, normalizza le
     * intestazioni e restituisce le righe come mappa colonna→valore.
     *
     * @return list<array<string,string>>
     */
    protected function leggiCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

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
     * Mappe per risolvere l'ubicazione: per nome (con ambiguità) e per percorso.
     * Costruite dai nodi SCOPATI → isolamento e sotto-albero automatici.
     *
     * @return array{0: array<string, Collection>, 1: array<string, UnitaOrganizzativa>}
     */
    protected function mappeNodi(): array
    {
        $nodi = UnitaOrganizzativa::get();
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
                    $errori[] = 'Ubicazione non trovata: '.$dati['ubicazione'];
                }
            } else {
                $candidati = $perNome[$chiave] ?? collect();
                if ($candidati->isEmpty()) {
                    $errori[] = 'Ubicazione non trovata: '.$dati['ubicazione'];
                } elseif ($candidati->count() > 1) {
                    $errori[] = 'Ubicazione ambigua: usa il percorso (es. "Dipartimento > '.$dati['ubicazione'].'")';
                } else {
                    $nodo = $candidati->first();
                }
            }
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
                if ($data !== false && $data->format($formato) === $valore) {
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
        $valide = collect($this->righe)->filter(fn ($r) => $r['errori'] === [])->count();

        return view('livewire.strumenti.import-strumenti', [
            'valide' => $valide,
            'conErrori' => count($this->righe) - $valide,
        ]);
    }
}

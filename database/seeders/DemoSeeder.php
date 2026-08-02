<?php

namespace Database\Seeders;

use App\Enums\StatoIntervento;
use App\Enums\TipoIntervento;
use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Intervento;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dati dimostrativi realistici in italiano, su volumi da migliaia di record.
 *
 * NON è agganciato a DatabaseSeeder: si lancia a mano
 * (`php artisan db:seed --class=DemoSeeder`) perché è materiale da ambiente di
 * dimostrazione, non da CI né da produzione.
 *
 * È **additivo**: non cancella nulla. Popola gli Enti già esistenti — così chi
 * sta usando l'app vede subito i dati, essendo ogni utente vincolato al proprio
 * Ente (ADR-018) — e ne aggiunge di nuovi per avere più tenant su cui mostrare
 * l'isolamento. Rilanciarlo aggiunge altri dati: è pensato per girare una volta.
 *
 * Per i volumi grandi usa `insert()` a blocchi invece di create(): gli eventi
 * Eloquent non scattano, quindi ogni riga è costruita già conforme agli
 * invarianti del dominio (`tenant_id` allineato a quello dello strumento;
 * `stato = fatto` ⇔ `data_esecuzione` valorizzata) e al termine il seeder
 * **verifica** che siano rispettati, fallendo rumorosamente in caso contrario.
 */
class DemoSeeder extends Seeder
{
    private const DIPARTIMENTI = [
        'Analisi Cliniche', 'Microbiologia', 'Anatomia Patologica', 'Biologia Molecolare',
        'Ematologia', 'Immunologia', 'Chimica Clinica', 'Genetica Medica',
        'Virologia', 'Tossicologia Forense', 'Neonatologia', 'Radiologia',
    ];

    private const LABORATORI = [
        'Sala Prelievi', 'Colture Cellulari', 'Sequenziamento', 'Citofluorimetria',
        'Sterilizzazione', 'Preparazione Terreni', 'Camera Bianca', 'Crioconservazione',
        'Spettrometria', 'Diagnostica Rapida',
    ];

    /** Modelli di strumento: nome → prefisso matricola. */
    private const STRUMENTI = [
        'Autoclave' => 'AUT', 'Centrifuga refrigerata' => 'CFR', 'Spettrofotometro UV-Vis' => 'SPU',
        'Incubatore a CO2' => 'INC', 'Cappa a flusso laminare' => 'CFL', 'Microscopio ottico' => 'MIC',
        'Bilancia analitica' => 'BIL', 'Congelatore -80 °C' => 'FRZ', 'Agitatore magnetico' => 'AGM',
        'pHmetro da banco' => 'PHM', 'Termociclatore PCR' => 'PCR', 'Bagno termostatico' => 'BGT',
        'Stufa a secco' => 'STF', 'Cappa chimica aspirante' => 'CCA', 'Vortex da laboratorio' => 'VRT',
        'Liofilizzatore' => 'LIO', 'Citofluorimetro' => 'CIT', 'Analizzatore ematologico' => 'AEM',
        'Densitometro osseo' => 'DEN', 'Cromatografo HPLC' => 'HPL', 'Micropipetta automatica' => 'MPA',
        'Contatore di colonie' => 'CCL', 'Distillatore per acqua' => 'DST', 'Frigoemoteca' => 'FEM',
    ];

    private const COSTRUTTORI = ['Thermo', 'Eppendorf', 'Sartorius', 'Hettich', 'Memmert', 'Binder', 'Zeiss', 'Leica'];

    private const NOMI = ['Luca', 'Giulia', 'Marco', 'Francesca', 'Alessandro', 'Chiara', 'Davide', 'Sara', 'Matteo', 'Elena', 'Andrea', 'Valentina', 'Simone', 'Martina', 'Federico', 'Ilaria'];

    private const COGNOMI = ['Bianchi', 'Rossi', 'Ferrari', 'Esposito', 'Russo', 'Colombo', 'Ricci', 'Marino', 'Greco', 'Bruno', 'Gallo', 'Conti', 'De Luca', 'Mancini', 'Costa', 'Giordano'];

    private const DESCRIZIONI = [
        TipoIntervento::Manutenzione->value => [
            'Manutenzione ordinaria programmata', 'Sostituzione guarnizioni e filtri',
            'Pulizia circuito idraulico', 'Lubrificazione parti meccaniche',
            'Controllo tenuta e pressione', 'Sostituzione lampada UV',
            'Verifica sistema di raffreddamento', 'Sanificazione camera interna',
        ],
        TipoIntervento::Taratura->value => [
            'Taratura annuale con certificato ACCREDIA', 'Verifica di calibrazione con masse campione',
            'Taratura sonde di temperatura', 'Calibrazione fotometrica',
            'Verifica periodica di conformità metrologica',
        ],
        TipoIntervento::Ispezione->value => [
            'Ispezione visiva semestrale', 'Controllo sicurezza elettrica',
            'Verifica allarmi e sistemi di sicurezza', 'Ispezione tenuta cappa aspirante',
        ],
        TipoIntervento::Riparazione->value => [
            'Sostituzione scheda di controllo', 'Riparazione compressore',
            'Sostituzione display guasto', 'Ripristino dopo blocco software',
            'Sostituzione motore rotore',
        ],
        TipoIntervento::Altro->value => [
            'Aggiornamento firmware', 'Spostamento e ricollaudo',
            'Formazione operatori sull\'uso', 'Collaudo di accettazione',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            // Si popolano gli Enti GIÀ esistenti (così chi sta guardando l'app
            // vede subito i dati: ogni utente è vincolato al proprio Ente,
            // ADR-018) e se ne aggiungono di nuovi per avere più tenant fra cui
            // dimostrare l'isolamento.
            $esistenti = UnitaOrganizzativa::withoutGlobalScopes()
                ->where('tipo', TipoUnitaOrganizzativa::Ente->value)->get();

            foreach ($esistenti->merge($this->creaEnti()) as $ente) {
                $this->command?->info("Ente «{$ente->nome}»:");
                $this->popolaEnte($ente);
            }
        });

        $this->verificaInvarianti();
        $this->riepilogo();
    }

    /** @return Collection<int, UnitaOrganizzativa> */
    private function creaEnti()
    {
        $nomi = [
            'Ospedale San Raffaele — Laboratori',
            'Policlinico Universitario di Padova',
        ];

        return collect($nomi)->map(function (string $nome) {
            $ente = UnitaOrganizzativa::create([
                'tipo' => TipoUnitaOrganizzativa::Ente,
                'parent_id' => null,
                'nome' => $nome,
                'note' => 'Ente di dimostrazione',
            ]);
            $ente->forceFill(['tenant_id' => $ente->id])->saveQuietly();

            $this->command?->info("Ente creato: {$nome}");

            return $ente;
        });
    }

    private function popolaEnte(UnitaOrganizzativa $ente): void
    {
        $nodi = $this->creaAlberatura($ente);
        $utenti = $this->creaUtenti($ente);
        $tecnici = $utenti['tecnici'];

        $strumentiIds = $this->creaStrumenti($ente, $nodi);
        $this->creaInterventi($ente, $strumentiIds, $tecnici);
        $this->creaSpostamenti($ente, $strumentiIds, $nodi, $utenti['admin']);
    }

    /** @return list<int> id dei nodi foglia (dove stanno gli strumenti) */
    private function creaAlberatura(UnitaOrganizzativa $ente): array
    {
        $foglie = [];

        foreach (self::DIPARTIMENTI as $nomeDip) {
            $dip = UnitaOrganizzativa::create([
                'tenant_id' => $ente->id,
                'parent_id' => $ente->id,
                'tipo' => TipoUnitaOrganizzativa::Dipartimento,
                'nome' => $nomeDip,
            ]);
            $foglie[] = (int) $dip->id;

            foreach (array_rand(array_flip(self::LABORATORI), random_int(2, 4)) as $nomeLab) {
                $lab = UnitaOrganizzativa::create([
                    'tenant_id' => $ente->id,
                    'parent_id' => $dip->id,
                    'tipo' => TipoUnitaOrganizzativa::Sottolaboratorio,
                    'nome' => $nomeLab,
                ]);
                $foglie[] = (int) $lab->id;
            }
        }

        return $foglie;
    }

    /** @return array{admin: User, tecnici: list<int>} */
    private function creaUtenti(UnitaOrganizzativa $ente): array
    {
        $slug = str($ente->nome)->slug()->limit(24, '')->value();
        $admin = $this->utente("Responsabile {$ente->nome}", "admin.{$slug}@demo.test", $ente->id, 'Admin');

        $tecnici = [];
        foreach (range(1, 12) as $i) {
            $nome = self::NOMI[array_rand(self::NOMI)].' '.self::COGNOMI[array_rand(self::COGNOMI)];
            $email = str($nome)->slug('.')->value().".{$slug}.{$i}@demo.test";
            $tecnici[] = (int) $this->utente($nome, $email, $ente->id, 'Tecnico')->id;
        }

        foreach (range(1, 4) as $i) {
            $nome = self::NOMI[array_rand(self::NOMI)].' '.self::COGNOMI[array_rand(self::COGNOMI)];
            $this->utente($nome, str($nome)->slug('.')->value().".resp.{$slug}.{$i}@demo.test", $ente->id, 'Responsabile Reparto');
        }

        return ['admin' => $admin, 'tecnici' => $tecnici];
    }

    private function utente(string $nome, string $email, int $tenantId, string $ruolo): User
    {
        $utente = User::updateOrCreate(
            ['email' => $email],
            ['name' => $nome, 'password' => Hash::make('password'), 'tenant_id' => $tenantId, 'email_verified_at' => now()],
        );
        $utente->syncRoles([$ruolo]);

        return $utente;
    }

    /** @return list<int> */
    private function creaStrumenti(UnitaOrganizzativa $ente, array $nodi): array
    {
        $modelli = array_keys(self::STRUMENTI);
        $righe = [];
        $adesso = now();

        // Alcune decine di strumenti per nodo foglia → migliaia per Ente.
        foreach ($nodi as $nodo) {
            foreach (range(1, random_int(20, 35)) as $ignored) {
                $modello = $modelli[array_rand($modelli)];
                $sigla = self::STRUMENTI[$modello];
                $costruttore = self::COSTRUTTORI[array_rand(self::COSTRUTTORI)];

                $righe[] = [
                    'tenant_id' => $ente->id,
                    'unita_organizzativa_id' => $nodo,
                    'nome' => $modello,
                    'modello' => $costruttore.' '.$sigla.'-'.random_int(100, 999),
                    'matricola' => $sigla.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                    'parametri_tecnici' => json_encode([
                        'Alimentazione' => random_int(0, 1) ? '230 V — 50 Hz' : '400 V — 50 Hz',
                        'Potenza' => random_int(200, 4000).' W',
                        'Temperatura di esercizio' => random_int(-80, 250).' °C',
                    ], JSON_UNESCAPED_UNICODE),
                    'data_installazione' => today()->subDays(random_int(30, 5200))->toDateString(),
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ];
            }
        }

        foreach (array_chunk($righe, 500) as $blocco) {
            Strumento::insert($blocco);
        }

        $this->command?->info('  strumenti: '.count($righe));

        // Riletti dal DB invece di dedurre un intervallo di id: gli id
        // contigui non sono garantiti, e così si includono anche gli strumenti
        // che l'Ente aveva già (che meritano interventi come gli altri).
        return Strumento::withoutGlobalScopes()->where('tenant_id', $ente->id)->pluck('id')->all();
    }

    private function creaInterventi(UnitaOrganizzativa $ente, array $strumentiIds, array $tecnici): void
    {
        $righe = [];
        $adesso = now();
        $totale = 0;

        foreach ($strumentiIds as $strumentoId) {
            // Storico + pianificati: distribuzione che produce un semaforo vario
            // (la maggior parte verde, una minoranza scaduta o imminente).
            foreach (range(1, random_int(2, 6)) as $ignored) {
                $tipo = array_rand(self::DESCRIZIONI);
                $descrizioni = self::DESCRIZIONI[$tipo];

                // 60% storico già eseguito, 25% pianificato lontano,
                // 10% imminente (entro 30 gg), 5% scaduto non fatto.
                $dado = random_int(1, 100);
                [$scadenza, $stato, $esecuzione] = match (true) {
                    $dado <= 60 => $this->eseguito(),
                    $dado <= 85 => [today()->addDays(random_int(31, 400)), StatoIntervento::NonFatto, null],
                    $dado <= 95 => [today()->addDays(random_int(0, 30)), StatoIntervento::NonFatto, null],
                    default => [today()->subDays(random_int(1, 240)), StatoIntervento::NonFatto, null],
                };

                $righe[] = [
                    'tenant_id' => $ente->id, // invariante: stesso tenant dello strumento
                    'strumento_id' => $strumentoId,
                    'tecnico_id' => random_int(0, 4) ? $tecnici[array_rand($tecnici)] : null,
                    'descrizione' => $descrizioni[array_rand($descrizioni)],
                    'tipo' => $tipo,
                    'data_scadenza' => $scadenza->toDateString(),
                    'stato' => $stato->value,
                    // invariante: valorizzata SSE stato = fatto
                    'data_esecuzione' => $esecuzione?->toDateString(),
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ];
            }

            if (count($righe) >= 1000) {
                Intervento::insert($righe);
                $totale += count($righe);
                $righe = [];
            }
        }

        if ($righe !== []) {
            Intervento::insert($righe);
            $totale += count($righe);
        }

        $this->command?->info("  interventi: {$totale}");
    }

    /** @return array{0: Carbon, 1: StatoIntervento, 2: Carbon} */
    private function eseguito(): array
    {
        $scadenza = today()->subDays(random_int(20, 1500));
        // Eseguito attorno alla scadenza: qualche volta in anticipo, più spesso poco dopo.
        $esecuzione = $scadenza->copy()->addDays(random_int(-5, 15));

        return [$scadenza, StatoIntervento::Fatto, $esecuzione->isFuture() ? today() : $esecuzione];
    }

    private function creaSpostamenti(UnitaOrganizzativa $ente, array $strumentiIds, array $nodi, User $admin): void
    {
        $righe = [];
        $adesso = now();
        $esterni = ['Ospedale Civile di Brescia', 'Laboratori Riuniti S.p.A.', 'Università degli Studi di Bologna', 'Centro Diagnostico Sant\'Anna'];

        // Uno spostamento su ~3 strumenti, tipo ingresso (provenienza esterna) o interno.
        foreach ($strumentiIds as $strumentoId) {
            if (random_int(1, 3) !== 1) {
                continue;
            }

            $ingresso = random_int(0, 1) === 1;
            $righe[] = [
                'tenant_id' => $ente->id,
                'strumento_id' => $strumentoId,
                'da_nodo_id' => $ingresso ? null : $nodi[array_rand($nodi)],
                'da_esterno' => $ingresso ? $esterni[array_rand($esterni)] : null,
                'a_nodo_id' => $nodi[array_rand($nodi)],
                'a_esterno' => null,
                'tipo_spostamento' => $ingresso ? TipoSpostamento::Ingresso->value : TipoSpostamento::Interno->value,
                'data' => today()->subDays(random_int(1, 1800))->toDateString(),
                'eseguito_da' => $admin->id,
                'nota' => $ingresso ? 'Presa in carico da ente esterno' : 'Riorganizzazione interna dei reparti',
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ];
        }

        foreach (array_chunk($righe, 500) as $blocco) {
            SpostamentoStrumento::insert($blocco);
        }

        $this->command?->info('  spostamenti: '.count($righe));
    }

    /**
     * Gli insert() a blocchi saltano gli eventi Eloquent: qui si verifica a
     * posteriori che gli invarianti del dominio siano comunque rispettati.
     */
    private function verificaInvarianti(): void
    {
        $fattiSenzaData = Intervento::withoutGlobalScopes()
            ->where('stato', StatoIntervento::Fatto->value)->whereNull('data_esecuzione')->count();

        $apertiConData = Intervento::withoutGlobalScopes()
            ->where('stato', StatoIntervento::NonFatto->value)->whereNotNull('data_esecuzione')->count();

        $tenantDisallineati = Intervento::withoutGlobalScopes()
            ->join('strumenti', 'strumenti.id', '=', 'interventi.strumento_id')
            ->whereColumn('interventi.tenant_id', '!=', 'strumenti.tenant_id')->count();

        if ($fattiSenzaData || $apertiConData || $tenantDisallineati) {
            throw new \RuntimeException(
                "Invarianti violati dal seeder — fatti senza data: {$fattiSenzaData}, ".
                "aperti con data: {$apertiConData}, tenant disallineati: {$tenantDisallineati}"
            );
        }

        $this->command?->info('Invarianti verificati: stato/data_esecuzione e tenant allineati.');
    }

    private function riepilogo(): void
    {
        $this->command?->table(
            ['Tabella', 'Righe totali'],
            [
                ['unita_organizzativa', UnitaOrganizzativa::withoutGlobalScopes()->count()],
                ['strumenti', Strumento::withoutGlobalScopes()->count()],
                ['interventi', Intervento::withoutGlobalScopes()->count()],
                ['spostamenti_strumento', SpostamentoStrumento::withoutGlobalScopes()->count()],
                ['users', User::count()],
            ]
        );
    }
}

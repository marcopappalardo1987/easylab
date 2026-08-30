<?php

namespace Database\Seeders;

use App\Enums\SoggettoGaranzia;
use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Enums\TipoIntervento;
use App\Enums\TipoSpostamento;
use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Catalogo strumenti: nome → varianti commerciali [modello, sigla matricola].
     *
     * I modelli sono un insieme CHIUSO e volutamente piccolo: lo stesso modello
     * deve ripetersi su molte unità e in molti laboratori, altrimenti la vista
     * "Per modello" (S2) mostra migliaia di righe da un'unità e non aggrega
     * nulla. È l'errore della prima versione, che infilava un numero casuale
     * nel nome del modello rendendolo di fatto unico per ogni pezzo.
     */
    private const CATALOGO = [
        'Autoclave' => [['Thermo Steri-200', 'AUT'], ['Hettich Vapor-450', 'AUT']],
        'Centrifuga refrigerata' => [['Eppendorf 5810R', 'CFR'], ['Hettich Rotina 420R', 'CFR']],
        'Spettrofotometro UV-Vis' => [['Thermo Evolution 220', 'SPU'], ['Shimadzu UV-1900', 'SPU']],
        'Incubatore a CO2' => [['Binder CB-170', 'INC'], ['Memmert ICO-105', 'INC']],
        'Cappa a flusso laminare' => [['Faster SafeFast Elite', 'CFL'], ['Thermo MSC-Advantage', 'CFL']],
        'Microscopio ottico' => [['Zeiss Primostar 3', 'MIC'], ['Leica DM750', 'MIC']],
        'Bilancia analitica' => [['Sartorius Entris II', 'BIL'], ['Mettler XPR-205', 'BIL']],
        'Congelatore -80 °C' => [['Thermo TSX-400', 'FRZ'], ['Haier DW-86L', 'FRZ']],
        'Agitatore magnetico' => [['IKA RCT Basic', 'AGM'], ['Velp AREC-X', 'AGM']],
        'pHmetro da banco' => [['Hanna HI-2020', 'PHM'], ['Mettler S220', 'PHM']],
        'Termociclatore PCR' => [['Bio-Rad C1000', 'PCR'], ['Thermo ProFlex', 'PCR']],
        'Bagno termostatico' => [['Memmert WNB-14', 'BGT'], ['Julabo Corio CD', 'BGT']],
        'Stufa a secco' => [['Binder ED-56', 'STF'], ['Memmert UN-75', 'STF']],
        'Cappa chimica aspirante' => [['Asem Labline 1800', 'CCA'], ['Faster FlowFast H', 'CCA']],
        'Liofilizzatore' => [['Christ Alpha 2-4', 'LIO'], ['Telstar LyoQuest', 'LIO']],
        'Citofluorimetro' => [['BD FACSLyric', 'CIT'], ['Beckman CytoFLEX', 'CIT']],
        'Analizzatore ematologico' => [['Sysmex XN-1000', 'AEM'], ['Mindray BC-6800', 'AEM']],
        'Cromatografo HPLC' => [['Agilent 1260 Infinity', 'HPL'], ['Waters Arc HPLC', 'HPL']],
        'Contatore di colonie' => [['Interscience Scan 500', 'CCL'], ['Schuett Count', 'CCL']],
        'Distillatore per acqua' => [['Millipore Milli-Q IQ', 'DST'], ['Elga Purelab Flex', 'DST']],
        'Frigoemoteca' => [['Fiocchetti Emoteca 700', 'FEM'], ['Angelantoni BBR-625', 'FEM']],
        'Vortex da laboratorio' => [['IKA Vortex 3', 'VRT'], ['Velp ZX4', 'VRT']],
    ];

    /**
     * Catalogo ricambi: CHIUSO e piccolo per la stessa ragione dei modelli
     * strumento — la ricerca incrociata di ADR-008 ("dove è montato questo
     * pezzo") aggrega per voce di catalogo, e nomi quasi tutti diversi le
     * darebbero migliaia di righe da un'unità ciascuna, cioè niente da
     * aggregare. È l'errore già commesso una volta coi modelli.
     */
    private const RICAMBI = [
        'Guarnizione di tenuta', 'Filtro HEPA', 'Lampada UV', 'Sonda di temperatura',
        'Cinghia di trasmissione', 'Scheda di controllo', 'Ventola di raffreddamento',
        'Kit di manutenzione annuale', 'Rotore di ricambio', 'Pompa peristaltica',
    ];

    /**
     * Fornitori: elenco CHIUSO come i modelli strumento e il catalogo ricambi.
     * Un'anagrafica di nomi quasi tutti diversi non farebbe aggregare nulla —
     * ed è l'errore già commesso una volta coi modelli.
     */
    private const FORNITORI = [
        'Thermo Fisher Scientific Italia', 'Sartorius Italy', 'Eppendorf Italia',
        'Bio-Rad Laboratories', 'Agilent Technologies Italia', 'Merck Life Science',
        'VWR International', 'Carlo Erba Reagents', 'Hettich Italia', 'Binder Italia',
    ];

    private const NOMI = ['Luca', 'Giulia', 'Marco', 'Francesca', 'Alessandro', 'Chiara', 'Davide', 'Sara', 'Matteo', 'Elena', 'Andrea', 'Valentina', 'Simone', 'Martina', 'Federico', 'Ilaria'];

    private const COGNOMI = ['Bianchi', 'Rossi', 'Ferrari', 'Esposito', 'Russo', 'Colombo', 'Ricci', 'Marino', 'Greco', 'Bruno', 'Gallo', 'Conti', 'De Luca', 'Mancini', 'Costa', 'Giordano'];

    private const DESCRIZIONI = [
        TipoIntervento::ManutenzioneOrdinaria->value => [
            'Manutenzione ordinaria programmata', 'Sostituzione guarnizioni e filtri',
            'Pulizia circuito idraulico', 'Lubrificazione parti meccaniche',
            'Controllo tenuta e pressione', 'Sostituzione lampada UV',
            'Verifica sistema di raffreddamento', 'Sanificazione camera interna',
            'Ispezione visiva semestrale', 'Controllo sicurezza elettrica',
        ],
        TipoIntervento::ManutenzioneStraordinaria->value => [
            'Sostituzione scheda di controllo', 'Riparazione compressore',
            'Sostituzione display guasto', 'Ripristino dopo blocco software',
            'Sostituzione motore rotore',
        ],
        TipoIntervento::ManutenzioneFullRisk->value => [
            'Intervento in contratto full risk', 'Sostituzione componente coperto da contratto',
            'Ripristino funzionale con parti incluse', 'Verifica programmata full risk',
        ],
        TipoIntervento::TaraturaECertificazione->value => [
            'Taratura annuale con certificato ACCREDIA', 'Verifica di calibrazione con masse campione',
            'Taratura sonde di temperatura', 'Calibrazione fotometrica',
            'Verifica periodica di conformità metrologica',
            'Certificazione di conformità CEI 62-5', 'Rinnovo certificazione di sicurezza elettrica',
            'Certificazione prestazionale cappa aspirante', 'Verifica e certificazione allarmi',
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

            // I due Enti demo condividono UN account (ADR-032): senza, il DB
            // dimostrativo sarebbe tutto 1:1 e lo switcher fra sedi non si
            // vedrebbe mai funzionare — stessa ragione per cui esiste un
            // tecnico esterno per Ente. firstOrCreate: il seeder è additivo.
            $gruppoDemo = Account::firstOrCreate(['ragione_sociale' => 'Gruppo Sanitario Demo']);

            foreach ($esistenti as $ente) {
                $this->command?->info("Ente «{$ente->nome}»:");
                $this->popolaEnte($ente);
            }
            foreach ($this->creaEnti() as $ente) {
                $this->command?->info("Ente «{$ente->nome}»:");
                $this->popolaEnte($ente, $gruppoDemo);
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

    protected function popolaEnte(UnitaOrganizzativa $ente, ?Account $condiviso = null): void
    {
        $nodi = $this->creaAlberatura($ente);
        $utenti = $this->creaUtenti($ente);
        $tecnici = $utenti['tecnici'];

        $this->creaAccount($ente, $utenti['admin'], $condiviso);

        $fornitori = $this->creaFornitori($ente);
        $strumentiIds = $this->creaStrumenti($ente, $nodi, $fornitori);
        $this->creaInterventi($ente, $strumentiIds, $tecnici);
        $this->creaSpostamenti($ente, $strumentiIds, $nodi, $utenti['admin']);
        $this->creaGaranzie($ente, $strumentiIds);
        $this->creaRicambi($ente, $strumentiIds);
        $this->forzaAlcuniSemafori($ente, $utenti['admin'], $strumentiIds);
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

        // Un tecnico ESTERNO per Ente (ADR-030): `tenant_id` NULL — è staff
        // EasyLab, non un dipendente del laboratorio — e accede per portafoglio.
        //
        // Senza almeno una di queste righe la demo mostrerebbe un solo tipo di
        // tecnico su due, e il canale portafoglio non si vedrebbe mai
        // funzionare: i dodici qui sopra sono INTERNI, e a loro l'accesso
        // arriva dalle assegnazioni.
        $nome = self::NOMI[array_rand(self::NOMI)].' '.self::COGNOMI[array_rand(self::COGNOMI)];
        $esterno = $this->utente($nome, "esterno.{$slug}@easylab.test", null, 'Tecnico');
        $esterno->portafoglioClienti()->syncWithoutDetaching([$ente->id]);

        return ['admin' => $admin, 'tecnici' => $tecnici];
    }

    /**
     * L'account dell'Ente (ADR-032), con l'Admin fra i membri.
     *
     * Gli Enti preesistenti arrivano già agganciati dal backfill della
     * migration: si usa quello. Per i nuovi: l'account condiviso del gruppo
     * demo se c'è, altrimenti un 1:1 sulla ragione sociale (firstOrCreate,
     * disciplina di creaFornitori: rilanciare il seeder non moltiplica nulla).
     */
    private function creaAccount(UnitaOrganizzativa $ente, User $admin, ?Account $condiviso): void
    {
        $account = $ente->account
            ?? $condiviso
            ?? Account::firstOrCreate(['ragione_sociale' => $ente->nome]);

        if ($ente->account_id === null) {
            $ente->forceFill(['account_id' => $account->id])->saveQuietly();
        }

        $account->aggiungiMembro($admin);
    }

    private function utente(string $nome, string $email, ?int $tenantId, string $ruolo): User
    {
        $utente = User::updateOrCreate(
            ['email' => $email],
            ['name' => $nome, 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        // Fuori dal Fillable da ADR-032 (l'unica via applicativa è lo
        // switcher): il seeder, come il provisioning, passa dal forceFill.
        $utente->forceFill(['tenant_id' => $tenantId])->save();
        $utente->syncRoles([$ruolo]);

        return $utente;
    }

    /**
     * Anagrafica fornitori dell'Ente (ADR-023).
     *
     * `firstOrCreate` sulla ragione sociale: il seeder è additivo e rilanciarlo
     * non deve moltiplicare le anagrafiche — stessa disciplina di
     * `Ricambio::collegaOCrea()`, che qui non si può usare perché è del
     * catalogo ricambi.
     *
     * @return list<int>
     */
    private function creaFornitori(UnitaOrganizzativa $ente): array
    {
        $ids = collect(self::FORNITORI)
            ->map(fn (string $nome) => (int) Fornitore::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $ente->id, 'ragione_sociale' => $nome],
                ['email' => str($nome)->slug().'@fornitore.test', 'telefono' => '0'.random_int(10, 99).' '.random_int(1000000, 9999999)],
            )->id)
            ->all();

        $this->command?->info('  fornitori: '.count($ids));

        return $ids;
    }

    /** @return list<int> */
    private function creaStrumenti(UnitaOrganizzativa $ente, array $nodi, array $fornitori = []): array
    {
        $nomi = array_keys(self::CATALOGO);
        $righe = [];
        $adesso = now();

        // Almeno 35 strumenti per nodo: con 12 dipartimenti e almeno 2
        // laboratori ciascuno sono almeno 1.260 strumenti per Ente. Ogni unità
        // pesca dal catalogo chiuso, così lo STESSO modello finisce in molti
        // laboratori e la vista "Per modello" ha qualcosa da aggregare.
        foreach ($nodi as $nodo) {
            foreach (range(1, random_int(35, 45)) as $ignored) {
                $nome = $nomi[array_rand($nomi)];
                $varianti = self::CATALOGO[$nome];
                [$modello, $sigla] = $varianti[array_rand($varianti)];

                $righe[] = [
                    'tenant_id' => $ente->id,
                    'unita_organizzativa_id' => $nodo,
                    'nome' => $nome,
                    'modello' => $modello,
                    'matricola' => $sigla.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                    'parametri_tecnici' => json_encode([
                        'Alimentazione' => random_int(0, 1) ? '230 V — 50 Hz' : '400 V — 50 Hz',
                        'Potenza' => random_int(200, 4000).' W',
                        'Temperatura di esercizio' => random_int(-80, 250).' °C',
                    ], JSON_UNESCAPED_UNICODE),
                    'data_installazione' => today()->subDays(random_int(30, 5200))->toDateString(),
                    // ⚠️ Una quota SENZA fornitore (~12%), di proposito: è la
                    // situazione reale del DB dopo la migration, ed è l'unico
                    // modo di vedere a occhio come si comportano scheda,
                    // Panoramica ed elenco su una riga storica. Stessa ragione
                    // per cui le garanzie ricambio nascono in forma "a data".
                    'fornitore_id' => ($fornitori !== [] && random_int(1, 100) > 12)
                        ? $fornitori[array_rand($fornitori)]
                        : null,
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
     * Garanzia macchina su OGNI strumento (ERD §6.1 — ADR-004): mix di tipo
     * `data` e `ore`, con scadenze distribuite fra già finite, in scadenza e
     * ancora attive.
     *
     * La distribuzione è pesata verso le garanzie ANCORA ATTIVE (~70%): una
     * garanzia scaduta o in scadenza accende l'arancione, quindi coprire tutto
     * il parco con scadenze uniformi renderebbe arancione quasi ogni strumento
     * e il semaforo smetterebbe di discriminare — un segnale sempre acceso non
     * è un segnale.
     *
     * insert() a blocchi: gli eventi non scattano, quindi
     * `data_scadenza_effettiva` è precalcolata QUI conforme alla
     * normalizzazione del model — e verificaInvarianti() lo ricontrolla.
     */
    private function creaGaranzie(UnitaOrganizzativa $ente, array $strumentiIds): void
    {
        $righe = [];
        $adesso = now();

        foreach ($strumentiIds as $strumentoId) {
            // 70% attiva · 15% in scadenza entro la soglia · 15% già finita.
            $esito = match (true) {
                random_int(1, 100) <= 70 => 'attiva',
                random_int(1, 100) <= 50 => 'imminente',
                default => 'scaduta',
            };

            $durata = [12, 24, 36, 60][array_rand([12, 24, 36, 60])];

            // Si sceglie prima la scadenza voluta, poi si retrodata l'inizio:
            // così la normalizzazione (inizio + durata) cade dove serve.
            $effettiva = match ($esito) {
                'attiva' => today()->addDays(random_int(31, 1500)),
                'imminente' => today()->addDays(random_int(0, 30)),
                default => today()->subDays(random_int(1, 1200)),
            };

            // ⚠️ La scadenza si RICALCOLA da `data_inizio` con la regola del
            // model, invece di scrivere il target scelto sopra: `subMonths` non
            // è l'inverso di `addMonths` quando la data cade in un giorno che
            // il mese di arrivo non ha (29 febbraio, 31 del mese). Scrivendo il
            // target si ottenevano righe con `data_inizio + durata ≠
            // data_scadenza_effettiva` — cioè fuori dalla normalizzazione
            // ADR-019 — una ogni qualche migliaio, quindi invisibili finché
            // `verificaInvarianti()` non le ha cercate. Lo spostamento rispetto
            // al target è di un giorno e non cambia la distribuzione voluta.
            $inizio = $effettiva->copy()->subMonths($durata);

            $righe[] = [
                'tenant_id' => $ente->id,
                'soggetto' => SoggettoGaranzia::Macchina->value,
                'strumento_id' => $strumentoId,
                'ricambio_utilizzo_id' => null,
                'data_inizio' => $inizio->toDateString(),
                'durata_mesi' => $durata,
                'data_scadenza_effettiva' => $inizio->copy()->addMonths($durata)->toDateString(),
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ];
        }

        foreach (array_chunk($righe, 500) as $blocco) {
            Garanzia::insert($blocco);
        }

        $this->command?->info('  garanzie: '.count($righe));
    }

    /**
     * Pezzi montati e loro garanzie (ERD §7.1/7.2 — ADR-008/020/022).
     *
     * Serve a rendere ADR-020 **ispezionabile a occhio** sul DB di sviluppo:
     * finché non esistono righe vere, la fonte "garanzia ricambio" del semaforo
     * si può solo testare, non guardare — e in S3-bis la verifica sui dati veri
     * («Incubatore INC-H») aveva scoperto cose che i test non avevano visto.
     *
     * Tre scelte, e nessuna è di comodo:
     *
     * 1. **Il catalogo passa da `Ricambio::collegaOCrea()` e non da `insert()`**:
     *    è il metodo di dominio del blocco 1, e farlo girare qui lo esercita
     *    sulle grafie vere invece che sulle sole fixture. Sono dieci righe per
     *    Ente: il costo di non ottimizzare è nullo, il guadagno è che il
     *    collega-o-crea è provato anche fuori dai test.
     * 2. **Le righe di montaggio vanno a `insert()` a blocchi**, che salta gli
     *    eventi e quindi le quattro guardie di `RicambioUtilizzo`: sono perciò
     *    costruite già conformi (tenant dello strumento, tenant del ricambio,
     *    intervento dello stesso strumento, quantità ≥ 1) e
     *    `verificaInvarianti()` le ricontrolla una per una a posteriori.
     * 3. **La garanzia del pezzo nasce "a data"** (`data_scadenza_dichiarata`,
     *    ADR-022) e non "a durata": è la forma che il form intervento produce
     *    davvero, e seminare l'altra darebbe un DB di dimostrazione che non
     *    somiglia a quello di esercizio.
     *
     * Stesso mix 70/15/15 delle garanzie macchina: un parco tutto arancione non
     * discrimina più nulla.
     */
    private function creaRicambi(UnitaOrganizzativa $ente, array $strumentiIds): void
    {
        $catalogo = collect(self::RICAMBI)
            ->map(fn (string $nome) => (int) Ricambio::collegaOCrea($nome, (int) $ente->id)->id)
            ->all();

        // Un intervento per strumento, quando c'è: ADR-022 dice che il punto
        // d'ingresso abituale è il form intervento, quindi la maggior parte
        // delle righe deve portarne il riferimento. `intervento_id` resta NULL
        // dove non ce n'è uno — è il caso "inserito dal tab Ricambi".
        $interventoPerStrumento = Intervento::withoutGlobalScopes()
            ->whereIn('strumento_id', $strumentiIds)
            ->pluck('id', 'strumento_id');

        $righe = [];
        $adesso = now();

        // Circa un terzo del parco monta almeno un pezzo: non tutti, o la
        // fonte non si distinguerebbe dalle altre guardando l'elenco.
        foreach ($strumentiIds as $strumentoId) {
            if (random_int(1, 3) !== 1) {
                continue;
            }

            foreach (range(1, random_int(1, 2)) as $ignored) {
                $righe[] = [
                    'tenant_id' => $ente->id,
                    'strumento_id' => $strumentoId,
                    'ricambio_id' => $catalogo[array_rand($catalogo)],
                    'intervento_id' => $interventoPerStrumento[$strumentoId] ?? null,
                    'quantita' => random_int(1, 3),
                    'data' => today()->subDays(random_int(30, 900))->toDateString(),
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ];
            }
        }

        foreach (array_chunk($righe, 500) as $blocco) {
            RicambioUtilizzo::insert($blocco);
        }

        $this->command?->info('  ricambi montati: '.count($righe));

        // Le righe appena scritte, rilette dal DB per averne gli id (come per
        // gli strumenti: gli id contigui non sono garantiti). Si escludono
        // quelle che hanno già una garanzia, così un secondo giro del seeder
        // non appende una seconda garanzia agli stessi pezzi.
        $senzaGaranzia = RicambioUtilizzo::withoutGlobalScopes()
            ->where('ricambio_utilizzo.tenant_id', $ente->id)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('garanzie')
                ->whereColumn('garanzie.ricambio_utilizzo_id', 'ricambio_utilizzo.id'))
            ->get(['id', 'data']);

        $garanzie = [];

        foreach ($senzaGaranzia as $utilizzo) {
            $esito = match (true) {
                random_int(1, 100) <= 70 => 'attiva',
                random_int(1, 100) <= 50 => 'imminente',
                default => 'scaduta',
            };

            $montaggio = $utilizzo->data;

            // La garanzia del pezzo parte dal montaggio, quindi la scadenza
            // deve caderci dopo (è l'invariante di `normalizzaScadenza`, che
            // l'insert() non applica): per il ramo "scaduta" si prende il
            // minimo fra montaggio+N e ieri, mai una data anteriore al pezzo.
            $effettiva = match ($esito) {
                'attiva' => today()->addDays(random_int(31, 1200)),
                'imminente' => today()->addDays(random_int(0, 30)),
                default => $montaggio->copy()->addDays(random_int(1, 300))->min(today()->subDay()),
            };

            $garanzie[] = [
                'tenant_id' => $ente->id,
                'soggetto' => SoggettoGaranzia::Ricambio->value,
                'strumento_id' => null,
                'ricambio_utilizzo_id' => $utilizzo->id,
                'data_inizio' => $montaggio->toDateString(),
                'durata_mesi' => null,
                'data_scadenza_dichiarata' => $effettiva->toDateString(),
                'data_scadenza_effettiva' => $effettiva->toDateString(),
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ];
        }

        foreach (array_chunk($garanzie, 500) as $blocco) {
            Garanzia::insert($blocco);
        }

        $this->command?->info('  garanzie ricambio: '.count($garanzie));
    }

    /**
     * Qualche semaforo forzato per Ente (ADR-005): due rossi "non idoneo" con
     * motivo, un arancione e un verde forzato su uno strumento che ha davvero
     * uno scaduto — quest'ultimo è il caso che mostra bene il principio: il
     * pallino è verde per decisione umana, ma l'intervento scaduto resta lì.
     *
     * Passa dai metodi di dominio (non da insert()) autenticandosi come Admin
     * dell'Ente: così `forced_by` è realistico e l'audit log si popola come in
     * esercizio. L'Admin appartiene allo stesso Ente degli strumenti, quindi i
     * global scope restano coerenti.
     */
    private function forzaAlcuniSemafori(UnitaOrganizzativa $ente, User $admin, array $strumentiIds): void
    {
        $motivi = [
            'Non idoneo: perdita dal circuito, in attesa del ricambio',
            'Fuori servizio dopo il collaudo di sicurezza elettrica',
        ];

        $scelti = collect($strumentiIds)->shuffle()->take(4)->values();
        if ($scelti->count() < 4) {
            return;
        }

        Auth::login($admin);

        // Due rossi con motivo (obbligatorio per il rosso).
        foreach ([0, 1] as $i) {
            Strumento::withoutGlobalScopes()->find($scelti[$i])
                ?->forzaSemaforo(StatoSemaforo::Rosso, $motivi[$i]);
        }

        // Un arancione forzato (motivo facoltativo, qui valorizzato).
        Strumento::withoutGlobalScopes()->find($scelti[2])
            ?->forzaSemaforo(StatoSemaforo::Arancione, 'Da tenere sotto osservazione dopo la riparazione');

        // Un verde forzato su uno strumento CON uno scaduto: la forzatura non
        // nasconde il problema, che resta visibile nel tab Interventi.
        $conScaduto = Intervento::withoutGlobalScopes()->scadute()
            ->whereIn('strumento_id', $strumentiIds)->value('strumento_id');

        Strumento::withoutGlobalScopes()->find($conScaduto ?? $scelti[3])
            ?->forzaSemaforo(StatoSemaforo::Verde, 'Verificato sul campo: operativo, scadenza già pianificata');

        Auth::logout();

        $this->command?->info('  semafori forzati: 4 (2 rossi)');
    }

    /**
     * Gli insert() a blocchi saltano gli eventi Eloquent: qui si verifica a
     * posteriori che gli invarianti del dominio siano comunque rispettati.
     */
    protected function verificaInvarianti(): void
    {
        $fattiSenzaData = Intervento::withoutGlobalScopes()
            ->where('stato', StatoIntervento::Fatto->value)->whereNull('data_esecuzione')->count();

        $apertiConData = Intervento::withoutGlobalScopes()
            ->where('stato', StatoIntervento::NonFatto->value)->whereNotNull('data_esecuzione')->count();

        $tenantDisallineati = Intervento::withoutGlobalScopes()
            ->join('strumenti', 'strumenti.id', '=', 'interventi.strumento_id')
            ->whereColumn('interventi.tenant_id', '!=', 'strumenti.tenant_id')->count();

        // Le garanzie: `data_scadenza_effettiva` deve rispettare la
        // normalizzazione ADR-004/019, che gli insert() a blocchi non applicano.
        // DUE rami, perché ADR-022 ha aggiunto la forma "a data": controllarne
        // uno solo lascerebbe scoperte proprio le garanzie ricambio — e con
        // `durata_mesi` NULL il controllo vecchio esploderebbe, non fallirebbe.
        $garanzieIncoerenti = Garanzia::withoutGlobalScopes()->get()
            ->reject(fn (Garanzia $g) => $g->durata_mesi !== null
                ? $g->data_inizio->copy()->addMonths($g->durata_mesi)->isSameDay($g->data_scadenza_effettiva)
                : $g->data_scadenza_dichiarata?->isSameDay($g->data_scadenza_effettiva)
                    && $g->data_scadenza_dichiarata->gt($g->data_inizio))
            ->count();

        if ($garanzieIncoerenti > 0) {
            throw new \RuntimeException("Invarianti violati: {$garanzieIncoerenti} garanzie con data_scadenza_effettiva fuori normalizzazione (ADR-019/022).");
        }

        if ($fattiSenzaData || $apertiConData || $tenantDisallineati) {
            throw new \RuntimeException(
                "Invarianti violati dal seeder — fatti senza data: {$fattiSenzaData}, ".
                "aperti con data: {$apertiConData}, tenant disallineati: {$tenantDisallineati}"
            );
        }

        $this->verificaRicambiUtilizzo();

        // ADR-032: nessun Ente resta senza account, nessun account del seeder
        // senza membri, nessun nodo non-ente con un account addosso.
        $entiSenzaAccount = UnitaOrganizzativa::withoutGlobalScopes()
            ->where('tipo', TipoUnitaOrganizzativa::Ente->value)->whereNull('account_id')->count();
        $accountSenzaMembri = Account::doesntHave('membri')->count();
        $nodiConAccount = UnitaOrganizzativa::withoutGlobalScopes()
            ->where('tipo', '!=', TipoUnitaOrganizzativa::Ente->value)->whereNotNull('account_id')->count();

        if ($entiSenzaAccount || $accountSenzaMembri || $nodiConAccount) {
            throw new \RuntimeException(
                "Invarianti ADR-032 violati — enti senza account: {$entiSenzaAccount}, ".
                "account senza membri: {$accountSenzaMembri}, nodi non-ente con account: {$nodiConAccount}"
            );
        }

        $this->command?->info('Invarianti verificati: stato/data_esecuzione, tenant allineati, ricambi montati coerenti, account agganciati.');
    }

    /**
     * Le quattro guardie di `RicambioUtilizzo` (ERD §7.2), una per una: gli
     * `insert()` a blocchi non fanno scattare gli eventi, quindi qui si
     * ricontrolla a posteriori ciò che il model avrebbe impedito.
     *
     * Non è zelo: le prime due sono **sicurezza**, non ordine. Un utilizzo il
     * cui strumento appartiene a un altro Ente rompe l'invariante su cui poggia
     * il livello 2 di `DepartmentThroughStrumentoScope` (che filtra per
     * `strumento_id` fidandosi che il tenant coincida), e uno il cui ricambio è
     * di un altro Ente farebbe comparire il NOME di un pezzo altrui nella
     * ricerca incrociata di ADR-008.
     */
    private function verificaRicambiUtilizzo(): void
    {
        $quantitaNonValide = RicambioUtilizzo::withoutGlobalScopes()->where('quantita', '<', 1)->count();

        $strumentoAltroEnte = RicambioUtilizzo::withoutGlobalScopes()
            ->join('strumenti', 'strumenti.id', '=', 'ricambio_utilizzo.strumento_id')
            ->whereColumn('ricambio_utilizzo.tenant_id', '!=', 'strumenti.tenant_id')->count();

        $ricambioAltroEnte = RicambioUtilizzo::withoutGlobalScopes()
            ->join('ricambi', 'ricambi.id', '=', 'ricambio_utilizzo.ricambio_id')
            ->whereColumn('ricambio_utilizzo.tenant_id', '!=', 'ricambi.tenant_id')->count();

        $interventoAltroStrumento = RicambioUtilizzo::withoutGlobalScopes()
            ->join('interventi', 'interventi.id', '=', 'ricambio_utilizzo.intervento_id')
            ->whereColumn('interventi.strumento_id', '!=', 'ricambio_utilizzo.strumento_id')->count();

        if ($quantitaNonValide || $strumentoAltroEnte || $ricambioAltroEnte || $interventoAltroStrumento) {
            throw new \RuntimeException(
                "Invarianti violati sui ricambi montati — quantità < 1: {$quantitaNonValide}, ".
                "strumento di un altro Ente: {$strumentoAltroEnte}, ricambio di un altro Ente: {$ricambioAltroEnte}, ".
                "intervento di un altro strumento: {$interventoAltroStrumento}"
            );
        }
    }

    protected function riepilogo(): void
    {
        $this->command?->table(
            ['Tabella', 'Righe totali'],
            [
                ['unita_organizzativa', UnitaOrganizzativa::withoutGlobalScopes()->count()],
                ['strumenti', Strumento::withoutGlobalScopes()->count()],
                ['interventi', Intervento::withoutGlobalScopes()->count()],
                ['spostamenti_strumento', SpostamentoStrumento::withoutGlobalScopes()->count()],
                ['garanzie', Garanzia::withoutGlobalScopes()->count()],
                ['ricambi', Ricambio::withoutGlobalScopes()->count()],
                ['ricambio_utilizzo', RicambioUtilizzo::withoutGlobalScopes()->count()],
                ['fornitori', Fornitore::withoutGlobalScopes()->count()],
                ['strumenti senza fornitore', Strumento::withoutGlobalScopes()->whereNull('fornitore_id')->count()],
                ['accounts', Account::count()],
                ['account_user', DB::table('account_user')->count()],
                ['users', User::count()],
            ]
        );
    }
}

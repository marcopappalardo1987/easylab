<?php

namespace App\Support\Gdpr;

use App\Models\Account;
use App\Models\Documento;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Piani;
use App\Support\Piattaforma\CsvSicuro;
use App\Support\Tenancy\VistaPiattaforma;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 🔴 L'export GDPR dei dati di un Account: uno zip di CSV, i file dei documenti e
 * un manifest (diritto di accesso e portabilità, «Privacy GDPR e Registro
 * Trattamenti» §4; «anche in caso di lockout, lato Superadmin», ADR-013 e
 * ADR-032 punto 5).
 *
 * **Chi.** Un operatore col permesso di piattaforma `tenants.view_all`
 * (`VistaPiattaforma::PERMESSO`, set 🔒 di `config/rbac.php`: nessuna schermata
 * lo può concedere). Nessun permesso nuovo. L'Admin di un Ente non ce l'ha,
 * quindi non esporta né il proprio Account né un altro: l'export self-service
 * del cliente è una decisione ancora da prendere.
 *
 * **Da dove: solo senza utente in sessione, cioè da console** (`easylab:esporta-tenant`).
 * ADR-018 dice che in sessione nessun ruolo esce dal proprio tenant, se non
 * impersonando — e in impersonazione il permesso che conta è quello
 * dell'impersonato, che `tenants.view_all` non ha. Una superficie web di
 * questo export sarebbe un secondo percorso cross-tenant da progettare come la
 * cabina (`can:` di rotta + porta nominata), non un metodo da richiamare da un
 * componente: finché nessuno la decide, `autorizza()` rifiuta qualunque
 * chiamata con un utente autenticato, Superadmin compreso. È lo stesso patto di
 * `easylab:lockout`: la console è cross-tenant per natura. L'account bloccato
 * non è un caso: il lockout chiude la navigazione, non l'export (ADR-013).
 *
 * **Il perimetro** è di `PerimetroTenant` (filtri espliciti, niente scope). I
 * CSV passano da `CsvSicuro::cella()` riga per riga, con lo stesso delimitatore,
 * lo stesso BOM e gli stessi argomenti di `fputcsv` di `CsvSicuro::stringa()`,
 * che però vuole l'intera matrice in memoria: qui si scrive in streaming su un
 * file temporaneo.
 *
 * **Memoria e disco.** Le righe arrivano a blocchi di `PerimetroTenant::BLOCCO`
 * e ogni documento si copia per stream su un file temporaneo: in memoria non
 * c'è mai una tabella intera né un file intero. Il prezzo è il disco:
 * `ZipArchive` legge i file aggiunti solo a `close()`, quindi durante l'export
 * servono circa due volte i byte dei documenti del cliente. Dichiarato qui.
 *
 * **Audit.** Una riga sul canale `audit` per ogni export riuscito, soggetto
 * l'Account e causer l'operatore: portare fuori tutti i dati di un cliente è
 * almeno sensibile quanto l'export del portafoglio (`EsportaClienti`, la
 * terza eccezione ad ADR-027), e questa è la quarta. Un export fallito cancella
 * lo zip parziale e non lascia la riga; e se è la riga a non scriversi, lo zip
 * si cancella lo stesso: su disco non resta mai un export senza traccia.
 *
 * **Permessi.** umask 077 per tutta la durata: cartelle 0700, CSV e zip 0600,
 * lavoro sotto `storage/` e non in `/tmp`. Le letture girano in una fotografia
 * sola su Postgres (`instantanea()`).
 *
 * 🔗 ERD §3-§8 · ADR-001/006/018 (isolamento), ADR-013 (lockout), ADR-026/027
 * (audit delle letture sensibili), ADR-032 (Account), ADR-040 (perimetro),
 * ADR-042 (disco `documenti`).
 */
final class EsportazioneTenant
{
    public const VERSIONE_FORMATO = 1;

    public const DESCRIZIONE_AUDIT = 'Esportazione dati tenant';

    /** La cartella di lavoro dell'export in corso, per `interrompi()`. */
    private ?string $inCorso = null;

    /**
     * Ciò che resta fuori, e perché: finisce nel manifest, così chi riceve lo
     * zip sa cosa non c'è invece di doverlo dedurre.
     */
    public const ESCLUSI = [
        'users.password, remember_token, two_factor_secret, two_factor_recovery_codes' => 'credenziali: un hash o un segreto 2FA consegnato permette di entrare al posto della persona',
        'accounts.stripe_id, pm_type, pm_last_four, trial_ends_at' => 'specchio locale di Stripe: la fonte dei dati di pagamento è Stripe',
        'accounts.locked_reason, stripe_lock_reason' => 'annotazioni operative interne di EasyLab (ADR-013)',
        'registrazioni.password_hash, stripe_session_id' => 'un segreto e un riferimento interno a Stripe',
        'occorrenze_errore.input, messaggio, stack_trace, percorso, impersonato_da' => 'corpo della richiesta e interni dell\'applicazione (possono portare dati di terzi o token), e l\'id di un operatore EasyLab',
        'dati personali dei tecnici EasyLab' => 'nel portafoglio restano solo come id (ADR-038, punto aperto)',
    ];

    /**
     * ⛔ La guardia. Pubblica perché ogni superficie futura la chiami **prima** di
     * costruire qualunque cosa; `esegui()` la richiama comunque.
     */
    public static function autorizza(?User $operatore): void
    {
        if (Auth::check()) {
            throw new EsportazioneNegata(
                'L\'export dei dati di un tenant non si chiama da una sessione (ADR-018): si usa `easylab:esporta-tenant` da console.'
            );
        }

        if ($operatore === null || $operatore->trashed()) {
            throw new EsportazioneNegata('Operatore sconosciuto: l\'export va attribuito a una persona.');
        }

        if (! $operatore->can(VistaPiattaforma::PERMESSO)) {
            throw new EsportazioneNegata(
                "L'operatore {$operatore->email} non ha il permesso di piattaforma ".VistaPiattaforma::PERMESSO.'.'
            );
        }
    }

    public function esegui(Account $account, ?User $operatore, string $destinazione): RisultatoEsportazione
    {
        self::autorizza($operatore);

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP zip assente: l\'export non si può comporre.');
        }

        if (File::exists($destinazione)) {
            throw new RuntimeException("Il file {$destinazione} esiste già: l'export non sovrascrive.");
        }

        // Tutto ciò che nasce da qui in poi (cartelle, CSV in chiaro, zip) è
        // leggibile solo dal proprietario del processo: sono i dati personali di
        // un cliente intero. Ripristinata nel `finally`.
        $umask = umask(0077);
        $lavoro = null;
        $zip = new ZipArchive;
        $aperto = false;
        $creato = false;

        try {
            File::ensureDirectoryExists(dirname($destinazione), 0700);
            $lavoro = self::cartellaLavoro().'/'.bin2hex(random_bytes(8));
            File::ensureDirectoryExists($lavoro, 0700);
            $this->inCorso = $lavoro;

            // `EXCL`: se il file compare fra il controllo e qui, l'apertura
            // fallisce e `$creato` resta falso, cioè il file altrui non si tocca.
            if ($zip->open($destinazione, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException("Impossibile creare {$destinazione}.");
            }
            $aperto = true;
            $creato = true;

            [$perimetro, $righe, $documenti, $mancanti] = $this->instantanea(
                fn () => $this->componi($account, $zip, $lavoro),
            );

            $aperto = false;
            if ($zip->close() !== true) {
                throw new RuntimeException("Chiusura dello zip {$destinazione} fallita.");
            }
            chmod($destinazione, 0600);

            $risultato = new RisultatoEsportazione(
                percorso: $destinazione,
                sha256: hash_file('sha256', $destinazione),
                byte: (int) filesize($destinazione),
                righe: $righe,
                documenti: $documenti,
                documentiMancanti: $mancanti,
            );

            // ⛔ Dentro il `try`: se la riga di audit non si scrive, lo zip si
            // cancella. Un export senza traccia non deve esistere su disco.
            $this->traccia($account, $operatore, $perimetro, $risultato);

            return $risultato;
        } catch (Throwable $e) {
            // ⚠️ Un `ZipArchive` aperto si scrive da sé quando viene distrutto:
            // senza scartare le modifiche, un export fallito lascerebbe uno zip
            // parziale su disco.
            if ($aperto) {
                $zip->unchangeAll();
                $zip->close();
            }
            if ($creato) {
                File::delete($destinazione);
            }

            throw $e;
        } finally {
            if ($lavoro !== null) {
                File::deleteDirectory($lavoro);
            }
            $this->inCorso = null;
            umask($umask);
        }
    }

    /**
     * Dove nascono i file di lavoro: sotto `storage/`, non in `/tmp`, che è
     * condiviso con ogni utente della macchina.
     */
    public static function cartellaLavoro(): string
    {
        return storage_path('app/private/esportazioni-gdpr/lavoro');
    }

    /**
     * Pulizia su SIGTERM/SIGINT (la chiama il comando, se `pcntl` c'è): il
     * `finally` non gira quando il processo viene ucciso, e i CSV in chiaro
     * resterebbero su disco.
     */
    public function interrompi(): void
    {
        if ($this->inCorso !== null) {
            File::deleteDirectory($this->inCorso);
        }
    }

    /**
     * Tutte le letture in **una fotografia sola** su Postgres: una transazione
     * `REPEATABLE READ READ ONLY`, così un intervento scritto a metà export non
     * compare in `interventi.csv` senza il suo strumento, e i conteggi del
     * manifest descrivono un istante. Solo se non c'è già una transazione aperta:
     * `SET TRANSACTION` dev'essere la prima istruzione, e dentro un savepoint
     * (i test con RefreshDatabase) Postgres la rifiuta. Su SQLite, dove l'export
     * gira solo nei test, le letture restano come sono.
     *
     * @template T
     *
     * @param  callable(): T  $letture
     * @return T
     */
    private function instantanea(callable $letture): mixed
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() > 0) {
            return $letture();
        }

        DB::beginTransaction();

        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $esito = $letture();
            DB::commit();

            return $esito;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Il piano esce col nome che il cliente legge in pagina, non col codice che
     * `accounts.piano` conserva (🔗 ADR-050).
     *
     * Il codice è un identificatore interno, e quello del piano senza canone è
     * `free`: nell'archivio che il cliente apre sarebbe l'unico posto in cui
     * leggerebbe una parola che Easy Lab non usa con lui. Un piano uscito dal
     * catalogo non ha un nome da dare, e resta com'è.
     */
    private static function colPianoInChiaro(object $riga): object
    {
        foreach (['piano', 'piano_proposto'] as $colonna) {
            if (is_string($riga->{$colonna}) && Piani::esiste($riga->{$colonna})) {
                $riga->{$colonna} = Piani::etichetta($riga->{$colonna});
            }
        }

        return $riga;
    }

    /**
     * Le letture e la composizione dello zip (senza chiuderlo).
     *
     * @return array{0: PerimetroTenant, 1: array<string, int>, 2: int, 3: list<int>}
     */
    private function componi(Account $account, ZipArchive $zip, string $lavoro): array
    {
        $perimetro = PerimetroTenant::di($account);
        $impronte = [];
        $righe = [];

        $aggiungi = function (string $file, string $nome) use ($zip, &$impronte): void {
            $impronte[$nome] = hash_file('sha256', $file);
            $zip->addFile($file, $nome);
        };

        $righe['account'] = $this->scriviCsv(
            "{$lavoro}/account.csv",
            PerimetroTenant::COLONNE_ACCOUNT,
            DB::table('accounts')->where('id', $account->getKey())->get(PerimetroTenant::COLONNE_ACCOUNT)
                ->map(fn (object $riga) => self::colPianoInChiaro($riga)),
        );
        $aggiungi("{$lavoro}/account.csv", 'dati/account.csv');

        foreach ($perimetro->query() as $tabella => $query) {
            $righe[$tabella] = $this->scriviCsv(
                "{$lavoro}/{$tabella}.csv",
                PerimetroTenant::colonne($tabella),
                $query->lazyById(PerimetroTenant::BLOCCO),
            );
            $aggiungi("{$lavoro}/{$tabella}.csv", "dati/{$tabella}.csv");
        }

        $righe['users'] = $this->scriviUtenti($perimetro, "{$lavoro}/users.csv");
        $aggiungi("{$lavoro}/users.csv", 'dati/users.csv');

        [$documenti, $mancanti] = $this->copiaDocumenti($perimetro, $lavoro, $aggiungi);

        $zip->addFromString('manifest.json', json_encode(
            $this->manifest($account, $perimetro, $righe, $documenti, $mancanti, $impronte),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return [$perimetro, $righe, $documenti, $mancanti];
    }

    /**
     * @param  list<string>  $colonne
     * @param  iterable<object>  $righe
     */
    private function scriviCsv(string $file, array $colonne, iterable $righe): int
    {
        $out = fopen($file, 'wb');
        fwrite($out, CsvSicuro::BOM);
        $this->riga($out, $colonne);

        $n = 0;
        foreach ($righe as $riga) {
            $valori = array_map(fn (string $c) => $riga->{$c} ?? null, $colonne);
            $this->riga($out, $valori);
            $n++;
        }

        fclose($out);

        return $n;
    }

    /** @param  resource  $out */
    private function riga($out, array $valori): void
    {
        // ⛔ Gli stessi cinque argomenti di `CsvSicuro::stringa()`: escape vuoto.
        fputcsv($out, array_map(
            fn (mixed $v) => CsvSicuro::cella(is_bool($v) ? (int) $v : $v),
            $valori,
        ), CsvSicuro::DELIMITATORE, '"', '');
    }

    /**
     * Le persone, a blocchi: i ruoli si leggono con una query per blocco, non una
     * per riga.
     */
    private function scriviUtenti(PerimetroTenant $perimetro, string $file): int
    {
        $colonne = [...PerimetroTenant::COLONNE_UTENTI, 'doppio_fattore_attivo', 'ruoli', 'membro_account'];

        $out = fopen($file, 'wb');
        fwrite($out, CsvSicuro::BOM);
        $this->riga($out, $colonne);

        $n = 0;
        $perimetro->utenti()->chunkById(PerimetroTenant::BLOCCO, function ($blocco) use ($out, &$n): void {
            $ruoli = PerimetroTenant::ruoliDi($blocco->pluck('id')->map(fn ($id) => (int) $id)->all());

            foreach ($blocco as $utente) {
                $valori = array_map(fn (string $c) => $utente->{$c} ?? null, PerimetroTenant::COLONNE_UTENTI);
                $valori[] = $utente->two_factor_confirmed_at !== null ? 1 : 0;
                $valori[] = implode(', ', $ruoli[(int) $utente->id] ?? []);
                $valori[] = (int) $utente->membro_account;
                $this->riga($out, $valori);
                $n++;
            }
        });

        fclose($out);

        return $n;
    }

    /**
     * I file dei documenti, cestinati compresi (il file resta nel bucket finché
     * la retention non lo toglie: è un dato detenuto). Un file che non si legge
     * non ferma l'export: finisce fra i mancanti del manifest.
     *
     * @return array{0: int, 1: list<int>}
     */
    private function copiaDocumenti(PerimetroTenant $perimetro, string $lavoro, callable $aggiungi): array
    {
        $disco = Storage::disk(Documento::DISCO);
        $copiati = 0;
        $mancanti = [];

        $query = DB::table('documenti')->whereIn('tenant_id', $perimetro->enti)->select(['id', 'nome', 'path', 'size']);

        foreach ($query->lazyById(PerimetroTenant::BLOCCO) as $documento) {
            $id = (int) $documento->id;
            $temporaneo = "{$lavoro}/documento-{$id}";

            if (! $this->copiaFile($disco, (string) $documento->path, $temporaneo, $documento->size === null ? null : (int) $documento->size)) {
                File::delete($temporaneo);
                $mancanti[] = $id;

                continue;
            }

            $aggiungi($temporaneo, "documenti/{$id}/".self::nomeSicuro((string) ($documento->nome ?: basename((string) $documento->path))));
            $copiati++;
        }

        return [$copiati, $mancanti];
    }

    /**
     * Copia per stream e **verifica**: un file più corto di `documenti.size` (una
     * lettura interrotta, un oggetto troncato nel bucket) non va certificato con
     * un'impronta nel manifest. Falso = il documento va fra i non leggibili.
     */
    private function copiaFile(Filesystem $disco, string $percorso, string $temporaneo, ?int $atteso): bool
    {
        $sorgente = null;
        $destinazione = null;

        try {
            $sorgente = $disco->readStream($percorso);

            if (! is_resource($sorgente)) {
                return false;
            }

            $destinazione = fopen($temporaneo, 'wb');
            $copiati = $destinazione === false ? false : stream_copy_to_stream($sorgente, $destinazione);

            return $copiati !== false && ($atteso === null || $copiati === $atteso);
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($destinazione)) {
                fclose($destinazione);
            }
            if (is_resource($sorgente)) {
                fclose($sorgente);
            }
        }
    }

    /** Un nome di voce dello zip che non esce dalla propria cartella. */
    public static function nomeSicuro(string $nome): string
    {
        $pulito = trim((string) preg_replace('/[^\pL\pN._ -]+/u', '_', $nome), ' .');

        return $pulito === '' ? 'file' : mb_substr($pulito, 0, 150);
    }

    /**
     * @param  array<string, int>  $righe
     * @param  list<int>  $mancanti
     * @param  array<string, string>  $impronte
     * @return array<string, mixed>
     */
    private function manifest(Account $account, PerimetroTenant $perimetro, array $righe, int $documenti, array $mancanti, array $impronte): array
    {
        return [
            'formato' => 'easylab-export-tenant',
            'versione' => self::VERSIONE_FORMATO,
            'generato_il' => now()->toIso8601String(),
            'account' => [
                'id' => $account->getKey(),
                'ragione_sociale' => $account->ragione_sociale,
                'bloccato' => (bool) $account->is_locked,
            ],
            'enti' => DB::table('unita_organizzativa')
                ->whereIn('id', $perimetro->enti)
                ->orderBy('id')
                ->get(['id', 'nome', 'deleted_at'])
                ->map(fn ($e) => ['id' => (int) $e->id, 'nome' => $e->nome, 'cestinato' => $e->deleted_at !== null])
                ->all(),
            'csv' => [
                'codifica' => 'UTF-8 con BOM',
                'delimitatore' => CsvSicuro::DELIMITATORE,
                'nota' => 'Le celle che iniziano con = + - @, una tabulazione o un ritorno carrello (\\r) sono precedute da un apostrofo, contro l\'esecuzione di formule nei fogli di calcolo.',
            ],
            'righe' => $righe,
            'documenti' => ['file' => $documenti, 'non_leggibili' => $mancanti],
            'impronte_sha256' => $impronte,
            'esclusi' => [...self::ESCLUSI, ...PerimetroTenant::TABELLE_ESCLUSE],
        ];
    }

    private function traccia(Account $account, User $operatore, PerimetroTenant $perimetro, RisultatoEsportazione $risultato): void
    {
        activity(AuditLog::NAME)
            ->causedBy($operatore)
            ->performedOn($account)
            ->withProperties([
                'superficie' => 'console',
                'enti' => $perimetro->enti,
                'righe' => $risultato->righe,
                'documenti' => $risultato->documenti,
                'documenti_non_leggibili' => count($risultato->documentiMancanti),
                'sha256' => $risultato->sha256,
                'byte' => $risultato->byte,
                'file' => basename($risultato->percorso),
            ])
            ->log(self::DESCRIZIONE_AUDIT);
    }
}

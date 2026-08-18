<?php

namespace App\Models;

use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToTenant;
use App\Support\AuditLog;
use Database\Factories\RicambioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Voce del catalogo ricambi (ERD §7.1 — ADR-008, raffinato da ADR-022).
 *
 * Il catalogo cresce incrementalmente: chi registra un pezzo scrive un NOME, e
 * il collega-o-crea decide se esiste già. La chiave è `(tenant_id,
 * nome_normalizzato)` e non il codice costruttore, perché sul campo il codice
 * spesso non è a portata di mano e si finirebbe per inventarlo — degradando
 * proprio la ricerca incrociata che ADR-008 doveva proteggere.
 *
 * Solo `BelongsToTenant`: a differenza di `RicambioUtilizzo`, il catalogo non ha
 * collocazione nell'albero organizzativo. Un ricambio è dell'Ente, non di un
 * reparto — il Responsabile lo vede tutto, e a essere ristrette dal livello 2
 * sono le righe di UTILIZZO, che sanno su quale strumento stanno.
 */
class Ricambio extends Model
{
    /** @use HasFactory<RicambioFactory> */
    use AuditsDomainWrites, BelongsToTenant, HasFactory, SoftDeletes;

    /** Eloquent indovinerebbe `ricambios`. */
    protected $table = 'ricambi';

    /**
     * `nome_normalizzato` è deliberatamente ESCLUSA: è un valore derivato,
     * ricalcolato a ogni salvataggio dall'hook `saving`. Fuori da qui nessun
     * payload (form, import, API future) può falsificare la chiave del
     * collega-o-crea e dell'indice unique. Stesso principio di
     * `Garanzia::data_scadenza_effettiva` e delle `forced_*` di Strumento.
     */
    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'nome',
        'codice',
        'descrizione',
    ];

    protected static function booted(): void
    {
        static::saving(function (Ricambio $ricambio): void {
            $ricambio->nome = self::ripulisciNome((string) $ricambio->nome);
            $ricambio->nome_normalizzato = self::normalizzaNome($ricambio->nome);
        });
    }

    /**
     * Il nome come lo si MOSTRA: trim e spazi multipli collassati, maiuscole
     * dell'operatore conservate — è lui che ha nominato il pezzo.
     *
     * La classe di caratteri include `\p{Z}` oltre a `\s` perché lo spazio
     * unificatore (NBSP, U+00A0) arriva davvero dai copia-incolla da PDF e
     * fogli di calcolo, e `\s` non lo intercetta nemmeno con il modificatore
     * `/u`. Due nomi identici a occhio ma diversi in byte creerebbero due voci
     * di catalogo, cioè esattamente il doppione che l'unique deve impedire.
     */
    public static function ripulisciNome(string $nome): string
    {
        $pulito = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $nome));

        return $pulito !== ''
            ? $pulito
            : throw new InvalidArgumentException('Ricambio: il nome non può essere vuoto (ADR-022).');
    }

    /**
     * Forma canonica (ERD §7.1): chiave del collega-o-crea, dell'indice unique
     * parziale e dell'autocomplete (S4 blocco 3). **Unica definizione**: si
     * chiama, non si riscrive — è il motivo per cui la colonna esiste invece di
     * un `LOWER(TRIM(...))` in query.
     *
     * Accetta input GREZZO, quindi ricollassa da sé invece di presumere un nome
     * già ripulito: il chiamante è l'autocomplete, che riceve ciò che l'utente
     * sta digitando.
     *
     * **Nessun accent-folding, ed è una scelta e non una dimenticanza.** La
     * decisione è asimmetrica nel tempo: aggiungerlo domani FONDE righe oggi
     * distinte, e se ci fossero collisioni il backfill fallirebbe in modo
     * rumoroso sull'unique — con rimedio il merge doppioni che ADR-008 già
     * prevede. Toglierlo domani richiederebbe di RI-SEPARARE righe fuse, cioè
     * informazione perduta. Si sceglie la direzione reversibile. In più
     * `iconv('UTF-8','ASCII//TRANSLIT')` dipende dalla locale del server: una
     * regola di normalizzazione che cambia da macchina a macchina, sotto un
     * indice unique, è una bomba a orologeria.
     *
     * `mb_strtolower` con encoding esplicito e non l'internal encoding
     * implicita: fa già il case-folding degli accentati (PERÒ → però).
     */
    public static function normalizzaNome(string $nome): string
    {
        return mb_strtolower(self::ripulisciNome($nome), 'UTF-8');
    }

    /**
     * Collega-o-crea del catalogo (ADR-008, con la chiave passata al nome da
     * ADR-022). È il mattone su cui il form intervento (S4 blocco 3) costruirà
     * la propria transazione: sta qui e non nel componente Livewire perché una
     * regola di persistenza dev'essere testabile senza montare una UI.
     *
     * **`$tenantId` è esplicito e non dedotto da `CurrentTenant`.** In console
     * (seeder, import, job) e per i ruoli bypass il `TenantScope` non filtra:
     * una ricerca non qualificata aggancerebbe la voce di catalogo di un altro
     * Ente, e da lì la riga di utilizzo mostrerebbe il nome di un pezzo altrui.
     * È la stessa difesa in profondità di `DepartmentThroughStrumentoScope`, che
     * riapplica il confine Ente dentro la propria subquery invece di fidarsi del
     * chiamante. Il filtro esplicito CONVIVE col global scope, non lo sostituisce.
     *
     * Due comportamenti sono decisioni, non dettagli:
     * - **la grafia del primo che ha inserito vince**: collegando non si
     *   riscrive `nome`, altrimenti l'etichetta cambierebbe sotto le righe
     *   storiche di altri;
     * - **un doppione cestinato non blocca la ricreazione**: `$trova` esclude i
     *   trashed e l'indice unique è parziale su `deleted_at is null`.
     */
    public static function collegaOCrea(string $nome, int $tenantId, ?string $codice = null): self
    {
        $normalizzato = self::normalizzaNome($nome);

        $trova = fn (): ?self => self::query()
            ->where('tenant_id', $tenantId)
            ->where('nome_normalizzato', $normalizzato)
            ->first();

        if ($esistente = $trova()) {
            return $esistente;
        }

        try {
            // ⚠️ La transazione ANNIDATA non è decorativa, ed è la correzione di
            // un bug che su SQLite non si vede. Su Postgres una violazione di
            // vincolo mette l'INTERA transazione in stato aborted (25P02): ogni
            // query successiva fallisce, e la ri-SELECT qui sotto tornerebbe
            // «current transaction is aborted» invece della voce vinta dalla
            // corsa. Su SQLite il rollback è per statement, quindi il codice
            // senza savepoint passa in locale e rompe in CI/produzione — la
            // stessa classe di errore delle date lessicografiche.
            //
            // Laravel compila una transaction annidata come SAVEPOINT su
            // entrambi i driver, quindi al throw si torna al savepoint e la
            // transazione del chiamante — quella che ADR-022 esige attorno a
            // intervento + righe — resta viva.
            //
            // `getConnection()` e non `DB::`: il savepoint deve stare sulla
            // connessione su cui gira l'INSERT, non sulla default. Fuori da una
            // transazione esterna diventa un BEGIN/COMMIT attorno a un solo
            // INSERT: un round-trip in più su un'operazione rara, in cambio di
            // un ramo in meno da testare.
            return (new self)->getConnection()->transaction(
                fn (): self => self::create(['tenant_id' => $tenantId, 'nome' => $nome, 'codice' => $codice])
            );
        } catch (QueryException $e) {
            // Corsa persa (doppio submit): l'unique ha fatto il suo lavoro e la
            // voce ora esiste. Si ri-cerca invece di ispezionare lo SQLSTATE,
            // che differisce fra i driver (23505 su Postgres, "UNIQUE constraint
            // failed" su SQLite). Se la ri-ricerca non trova nulla, l'errore non
            // era una collisione e va rilanciato.
            //
            // Nota: se il concorrente ha già committato, `$trova()` lo vede; se
            // è ancora dentro la propria transazione no, e l'eccezione originale
            // riparte — che è il comportamento giusto (il chiamante ritenta, non
            // inventa una riga).
            return $trova() ?? throw $e;
        }
    }

    /** Chiave del collega-o-crea: senza, l'audit registrerebbe il nome grezzo e non l'effetto. */
    protected function attributiDerivatiTracciati(): array
    {
        return ['nome_normalizzato'];
    }

    /** Righe di montaggio che citano questa voce: base della ricerca incrociata. */
    public function utilizzi(): HasMany
    {
        return $this->hasMany(RicambioUtilizzo::class, 'ricambio_id');
    }

    /**
     * Unisce questa voce di catalogo in un'altra (ADR-008 «l'admin può unire
     * doppioni», peso aumentato da ADR-022 — S4 STRETCH).
     *
     * **Perché serve, e perché ADR-022 l'ha reso più probabile**: la chiave del
     * collega-o-crea è il NOME normalizzato, non il codice costruttore, e sul
     * campo si scrive quello che si ha in testa. «Guarnizione O-Ring» e
     * «Guarnizione OR» sono lo stesso pezzo per un tecnico e due voci per il
     * database — e ogni voce di troppo degrada la ricerca incrociata, che è la
     * ragione per cui il catalogo esiste.
     *
     * **Sposta i montaggi, non li ricrea.** Un `update` sulla FK conserva id,
     * date, garanzie agganciate e tracce di audit: ricrearli avrebbe rotto le
     * garanzie ricambio (che puntano a `ricambio_utilizzo`, non al catalogo) e
     * riscritto uno storico che è la prova di cosa è stato montato e quando.
     *
     * **Update by-query e non riga per riga**, che è l'eccezione al «sempre i
     * metodi di dominio» di questo progetto: gli hook di `RicambioUtilizzo`
     * verificano che il ricambio sia dello stesso Ente — cosa già garantita qui
     * dal confronto sui `tenant_id` — e su una voce con centinaia di montaggi
     * quella verifica costerebbe una query ciascuna per riconfermare un fatto
     * già stabilito. Il prezzo è che `updated_at` delle righe non cambia, ed è
     * corretto: il montaggio non è stato modificato, è stato **rietichettato**.
     *
     * La voce sorgente si CESTINA e non si cancella: l'unique parziale del
     * catalogo è `where deleted_at is null`, quindi il nome resta riusabile, e
     * un merge sbagliato si può leggere nello storico invece di sparire.
     *
     * @return int quanti montaggi sono passati alla destinazione
     */
    public function unisciIn(self $destinazione): int
    {
        if ($destinazione->id === $this->id) {
            throw new InvalidArgumentException('Ricambio: non si può unire una voce con sé stessa.');
        }

        // Il confine di Ente è già imposto dagli scope a chi ha risolto i due
        // model, ma questo metodo è di dominio e deve reggere anche in console,
        // dove gli scope non filtrano (seeder, comandi, import futuri).
        if ((int) $destinazione->tenant_id !== (int) $this->tenant_id) {
            throw new InvalidArgumentException('Ricambio: le due voci appartengono a Enti diversi.');
        }

        if ($destinazione->trashed()) {
            throw new InvalidArgumentException('Ricambio: la destinazione è cestinata.');
        }

        return DB::transaction(function () use ($destinazione): int {
            $spostati = RicambioUtilizzo::withoutGlobalScopes()
                ->where('ricambio_id', $this->id)
                ->update(['ricambio_id' => $destinazione->id]);

            // ⚠️ `disableLogging()` PRIMA di cestinare, o il gesto produrrebbe
            // DUE righe di audit: la «Cancellazione ricambio» del trait e
            // l'unione qui sotto. E la prima sarebbe pure fuorviante — dice che
            // la voce è stata cancellata, mentre è confluita in un'altra, che è
            // un'altra cosa per chi legge il registro. La cancellazione della
            // sorgente non è un evento a sé: è una PARTE dell'unione.
            $this->disableLogging()->delete();

            // `activity()` esplicita, e il criterio è quello di ADR-027: ciò che
            // conta qui NON è una colonna. In tabella si legge un `deleted_at`
            // valorizzato, che è indistinguibile da una cancellazione qualunque;
            // l'informazione — «questa voce è confluita in quest'altra, e con
            // essa N montaggi» — non esiste da nessuna parte se non la si scrive.
            activity(AuditLog::NAME)
                ->causedBy(auth()->user())
                ->performedOn($destinazione)
                ->withProperties([
                    'unita_da_id' => $this->id,
                    'unita_da_nome' => $this->nome,
                    'montaggi_spostati' => $spostati,
                ])
                ->log("Unito il ricambio «{$this->nome}» in «{$destinazione->nome}»");

            return $spostati;
        });
    }
}

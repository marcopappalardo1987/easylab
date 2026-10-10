<?php

namespace App\Models;

use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToOrgNodeThroughStrumento;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SerializzaGiorniCivili;
use App\Models\Contracts\ReachesStrumento;
use App\Models\Scopes\GaranziaRicambioPrivacyScope;
use Carbon\CarbonInterface;
use Database\Factories\RicambioUtilizzoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pezzo montato su una macchina (ERD §7.2 — ADR-008/022): l'anello che tiene
 * insieme strumento, voce di catalogo e intervento in cui è stato montato.
 *
 * È anche il ponte del **doppio salto** `garanzie → ricambio_utilizzo →
 * strumenti` con cui ADR-020 fa pesare la garanzia del pezzo sul semaforo dello
 * strumento. Da qui discendevano tre consegne per i blocchi successivi di S4,
 * scritte qui perché è il posto in cui sarebbero state cercate. Le prime due
 * sono **saldate** (blocchi 2 e 4), e restano scritte perché il motivo per cui
 * valgono non è cambiato:
 *
 * 1. **Una riga cestinata non esiste per nessuna lettura di dominio, semaforo
 *    compreso.** Un pezzo smontato per errore non deve accendere l'arancione.
 *    Vale in due punti, entrambi con un test: `GaranziaDepartmentScope` e
 *    `Garanzia::scopeDeiPezziMontati()`.
 * 2. Il livello 2 sulle righe `garanzie` di soggetto `ricambio` ricalca
 *    `DepartmentThroughStrumentoScope`, che gira la propria subquery con
 *    `withoutGlobalScopes()` per rompere la ricorsione col futuro scope Tecnico
 *    (ADR-007) — **e `withoutGlobalScopes()` rimuove anche `SoftDeletingScope`**.
 *    Quella subquery si porta quindi un `whereNull('ricambio_utilizzo.deleted_at')`
 *    esplicito, o le righe cestinate tornerebbero a contare. Stessa ragione per
 *    cui il `join` del semaforo, che non passa dal model, lo scrive a mano.
 * 3. **Saldata il 15 Ago 2026**: la cancellazione "di dominio" cestina in
 *    transazione **sia** l'utilizzo **sia** la sua garanzia, e vive in un solo
 *    metodo — `cestinaConGaranzia()` — perché i posti che cancellano un pezzo
 *    sono ora tre (tab Ricambi, form intervento, cancellazione dell'intervento
 *    intero) e la prima copia dimenticata lascerebbe una garanzia viva e orfana
 *    a pesare sul semaforo (ADR-020).
 *
 * Nessun vincolo "deve avere una garanzia": ADR-022 la rende obbligatoria di
 * FLUSSO, non di schema, perché la riga nasce prima della sua garanzia dentro
 * la stessa transazione (ERD §7.2).
 */
class RicambioUtilizzo extends Model implements ReachesStrumento
{
    /** @use HasFactory<RicambioUtilizzoFactory> */
    use AuditsDomainWrites, BelongsToOrgNodeThroughStrumento, BelongsToTenant, HasFactory, SerializzaGiorniCivili, SoftDeletes;

    protected $table = 'ricambio_utilizzo';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'strumento_id',
        'ricambio_id',
        'intervento_id',
        // Da chi è stato comprato questo pezzo (ADR-051): facoltativo.
        'fornitore_id',
        'quantita',
        'data',
    ];

    /**
     * `data_manuale` è fuori da `$fillable`: dice **chi** ha scritto la data, e
     * un payload che potesse impostarlo renderebbe la protezione una gentilezza
     * del chiamante invece di un fatto. Si scrive solo da `fissaMontaggio()`.
     */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'data_manuale' => 'boolean',
            'quantita' => 'integer',
        ];
    }

    /**
     * Fissa la data di montaggio del pezzo, e allinea l'inizio della garanzia.
     *
     * **La regola dell'allineamento vive QUI e in nessun altro posto.** Prima
     * stava dentro `Intervento::allineaRicambiAllaEsecuzione()`, che è
     * `protected` e si esercita solo chiudendo un intervento: due copie del
     * confine — una per la chiusura, una per la correzione a mano — sarebbero
     * state due cose libere di divergere, e la seconda non l'avrebbe mai vista
     * un test del tab.
     *
     * ⚠️ La `data_inizio` della garanzia segue il montaggio **solo se resta
     * prima della scadenza dichiarata**: il model esige quel confine, e
     * spostare l'inizio oltre una scadenza ravvicinata farebbe fallire
     * un'operazione per colpa di un refuso in una riga. In quel caso la
     * garanzia si lascia com'è — un dato informativo incoerente è meno grave
     * di un gesto che non si può più completare. La scadenza, che è l'unica
     * grandezza a pilotare il semaforo, non si tocca in nessun caso.
     *
     * `$manuale` marca la riga come corretta da una persona: da quel momento
     * `segnaFatto()` non la riscrive più (vedi la migration di `data_manuale`).
     */
    public function fissaMontaggio(?CarbonInterface $data, bool $manuale = false): bool
    {
        $this->data = $data;

        if ($manuale) {
            $this->data_manuale = true;
        }

        $salvato = $this->save();

        if ($data === null) {
            return $salvato;
        }

        // SENZA il privacy scope: la relazione passa da
        // `GaranziaRicambioPrivacyScope` e per un causer che non ha titolo a
        // vedere le righe ricambio tornerebbe NULL — la garanzia resterebbe con
        // una data d'inizio che il permesso di chi ha agito ha reso invisibile.
        // Il permesso governa il dettaglio mostrato, non l'integrità del dato.
        $garanzia = $this->garanzia()
            ->withoutGlobalScope(GaranziaRicambioPrivacyScope::class)
            ->first();

        if ($garanzia?->data_scadenza_dichiarata?->gt($data)) {
            $garanzia->data_inizio = $data;
            $garanzia->save();
        }

        return $salvato;
    }

    /**
     * Cestina il pezzo **e la sua garanzia**, in una transazione.
     *
     * È la consegna n.3 di questo stesso docblock, e vale ovunque si cancelli:
     * dal tab Ricambi, dal form intervento, e dalla cancellazione di un
     * intervento intero. Senza, nel tab Garanzie resterebbe una riga che non
     * punta a nulla di visibile — e, peggio, quella garanzia continuerebbe a
     * pesare sul semaforo dello strumento (ADR-020), perché il doppio salto
     * guarda `ricambio_utilizzo.deleted_at` e non l'esistenza del pezzo.
     *
     * Il bypass del privacy scope per la stessa ragione di `fissaMontaggio()`:
     * una garanzia orfana e viva sarebbe ADR-020 letto al contrario.
     */
    public function cestinaConGaranzia(): void
    {
        DB::transaction(function (): void {
            $this->garanzia()
                ->withoutGlobalScope(GaranziaRicambioPrivacyScope::class)
                ->first()?->delete();

            $this->delete();
        });
    }

    /**
     * Le guardie stanno su `creating`/`updating` e **non su `saving`**, che è
     * invece la sede abituale degli invarianti in questo progetto (`Garanzia`,
     * `Intervento`). La differenza non è stilistica:
     *
     *   Model::save() → evento `saving` → performInsert() → evento `creating`
     *
     * e `BelongsToTenant` registra il proprio listener su `creating`, dove
     * RISCRIVE `tenant_id` col tenant dell'utente corrente. Una guardia in
     * `saving` confronterebbe quindi un valore che il trait sta per sostituire:
     * un Admin dell'Ente A che forgia uno `strumento_id` dell'Ente B passerebbe
     * indenne, e la riga finirebbe salvata con `tenant_id = A` e strumento di B
     * — cioè la rottura esatta dell'invariante su cui poggia
     * `DepartmentThroughStrumentoScope`, che filtra per `strumento_id` fidandosi
     * che il tenant coincida.
     *
     * Registrandole in `booted()` girano DOPO i listener dei trait:
     * `bootIfNotBooted()` chiama `boot()` (→ `bootTraits()`) e solo dopo
     * `booted()`, e i listener scattano nell'ordine di registrazione.
     */
    protected static function booted(): void
    {
        static::creating(fn (RicambioUtilizzo $utilizzo) => $utilizzo->verificaCoerenza());
        static::updating(fn (RicambioUtilizzo $utilizzo) => $utilizzo->verificaCoerenza());
    }

    /**
     * Coerenza delle FK con il tenant della riga, più la quantità minima.
     *
     * Query builder e non Eloquent, di proposito: i global scope
     * nasconderebbero proprio la riga che serve controllare, e un riferimento
     * cross-tenant si presenterebbe come un "non trovato" dal messaggio
     * fuorviante. Stessa scelta delle migration di backfill di ADR-019.
     *
     * ⚠️ `Intervento` ha l'invariante `tenant_id == strumenti.tenant_id` solo
     * nel docblock e **non la impone**. Qui si diverge consapevolmente: quel
     * debito è noto, ma un debito noto non è un motivo per ereditarlo — e su
     * questa tabella l'invariante regge sia il livello 2 dello scope sia il
     * doppio salto del semaforo.
     */
    protected function verificaCoerenza(): void
    {
        // Un update della sola `data` non ripaga tre SELECT.
        if ($this->exists && ! $this->isDirty(['tenant_id', 'strumento_id', 'ricambio_id', 'intervento_id', 'fornitore_id', 'quantita'])) {
            return;
        }

        if (($this->quantita ?? 1) < 1) {
            // Nel model e non come `unsignedInteger` in colonna: SQLite ignora
            // l'unsigned, quindi il vincolo esisterebbe solo su Postgres — cioè
            // la divergenza fra driver che il progetto ha già pagato. È la
            // lezione di `durata_mesi >= 1` (ADR-019).
            throw new InvalidArgumentException('RicambioUtilizzo: `quantita` deve essere almeno 1 (ERD §7.2).');
        }

        $tenantStrumento = DB::table('strumenti')->where('id', $this->strumento_id)->value('tenant_id');

        if ($tenantStrumento === null || (int) $tenantStrumento !== (int) $this->tenant_id) {
            throw new InvalidArgumentException(
                'RicambioUtilizzo: lo strumento deve appartenere allo stesso Ente della riga — è l\'invariante su cui poggia il livello 2 del global scope (ADR-006).'
            );
        }

        $tenantRicambio = DB::table('ricambi')->where('id', $this->ricambio_id)->value('tenant_id');

        if ($tenantRicambio === null || (int) $tenantRicambio !== (int) $this->tenant_id) {
            // Non è ordine, è una fuga: la ricerca incrociata di ADR-008 parte
            // da `ricambio_id`, e una riga che punta al catalogo di un altro
            // Ente ne mostrerebbe il NOME del pezzo.
            throw new InvalidArgumentException(
                'RicambioUtilizzo: il ricambio deve appartenere allo stesso Ente della riga (ERD §7.1).'
            );
        }

        if ($this->fornitore_id !== null) {
            // 🔗 ADR-051, stessa regola di `Strumento` (ADR-023): l'anagrafica
            // fornitori è per Ente, e una riga che puntasse a quella di un
            // altro ne mostrerebbe la ragione sociale.
            $tenantFornitore = DB::table('fornitori')->where('id', $this->fornitore_id)->value('tenant_id');

            if ($tenantFornitore === null || (int) $tenantFornitore !== (int) $this->tenant_id) {
                throw new InvalidArgumentException(
                    'RicambioUtilizzo: il fornitore deve appartenere allo stesso Ente della riga (ADR-051).'
                );
            }
        }

        if ($this->intervento_id !== null) {
            // Sussume il controllo di tenant sull'intervento, che a sua volta è
            // vincolato allo strumento: nessuna quarta query.
            $strumentoIntervento = DB::table('interventi')->where('id', $this->intervento_id)->value('strumento_id');

            if ($strumentoIntervento === null || (int) $strumentoIntervento !== (int) $this->strumento_id) {
                throw new InvalidArgumentException(
                    'RicambioUtilizzo: l\'intervento deve essere dello stesso strumento — altrimenti il tab Ricambi mostrerebbe una riga collegata a un intervento di un\'altra macchina (ADR-022).'
                );
            }
        }
    }

    /**
     * «Creazione ricambio montato», non «Creazione ricambioutilizzo».
     *
     * Difetto in produzione dal 9 Ago 2026: il trait c'era, questo override no,
     * e `mb_strtolower('RicambioUtilizzo')` ha prodotto per una settimana righe
     * con una parola che nessuno direbbe. `AuditRicambiTest` non se n'era
     * accorto perché asseriva solo `toStartWith('Creazione')` — un test che
     * guarda il prefisso non vede il sostantivo. Le righe già scritte restano
     * come sono: l'audit non si riscrive.
     */
    protected function nomeDominio(): string
    {
        return 'ricambio montato';
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }

    public function ricambio(): BelongsTo
    {
        return $this->belongsTo(Ricambio::class, 'ricambio_id');
    }

    /**
     * Il fornitore da cui il pezzo è stato comprato, se è stato detto
     * (🔗 ADR-051).
     *
     * `withTrashed()` come `Strumento::fornitore()`: un fornitore cestinato
     * resta leggibile sulla riga, o la cella vuota si leggerebbe «mai
     * inserito».
     *
     * @return BelongsTo<Fornitore, $this>
     */
    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class, 'fornitore_id')->withTrashed();
    }

    public function intervento(): BelongsTo
    {
        return $this->belongsTo(Intervento::class, 'intervento_id');
    }

    /**
     * Garanzia del pezzo (ERD §6.1, `soggetto = ricambio`).
     *
     * ⚠️ Passa da `GaranziaRicambioPrivacyScope`, quindi per chi non ha
     * `garanzie.ricambio.view` restituisce NULL. È corretto per il DETTAGLIO;
     * il calcolo del semaforo (ADR-020) è invece un AGGREGATO dovuto a tutti e
     * legge quelle righe da `Garanzia::scopeDeiPezziMontati()`, che bypassa lo
     * scope — l'unico punto del progetto autorizzato a farlo.
     */
    public function garanzia(): HasOne
    {
        return $this->hasOne(Garanzia::class, 'ricambio_utilizzo_id');
    }
}

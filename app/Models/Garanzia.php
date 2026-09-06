<?php

namespace App\Models;

use App\Enums\SoggettoGaranzia;
use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SerializzaGiorniCivili;
use App\Models\Contracts\ReachesStrumento;
use App\Models\Scopes\GaranziaDepartmentScope;
use App\Models\Scopes\GaranziaRicambioPrivacyScope;
use App\Support\Semaforo;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\GaranziaFactory;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * Garanzia del macchinario o del singolo ricambio montato (ERD §6.1 — ADR-004,
 * ADR-019).
 *
 * Il motore è "sdoppiato" nel **soggetto** (macchina / ricambio) ma UNICO nel
 * calcolo: tutto si normalizza in `data_scadenza_effettiva`, e da lì in poi
 * semaforo e notifiche ragionano solo su date.
 *
 * La garanzia "a ore" di ADR-004 **non esiste** (ADR-019): era un
 * fraintendimento del briefing di scoping. Resta un'unica forma —
 * `data_inizio + durata_mesi` — e con essa sono spariti `tipo_scadenza`,
 * `soglia_ore`, `data_scadenza_prevista` e le letture contaore.
 *
 * Dei tre debiti dichiarati in S3, **due sono saldati l'8 Ago 2026**: la FK su
 * `ricambio_utilizzo_id` è viva, e il livello 2 raggiunge ora anche le righe
 * `ricambio` col doppio salto garanzie → ricambio_utilizzo → strumenti
 * (`GaranziaDepartmentScope`, che ha sostituito il trait generico).
 *
 * Il terzo — ADR-020, lettera **(c)** — è saldato dal blocco 4: le righe
 * `ricambio` pesano ora sul semaforo dello strumento che monta il pezzo, lette
 * senza GaranziaRicambioPrivacyScope da `scopeDeiPezziMontati()`, e la colonna
 * "Prossima scadenza" degrada a dicitura neutra per chi non ha il permesso.
 *
 * Resta:
 * - ADR-015: al trasferimento cross-tenant le garanzie devono seguire lo
 *   strumento con un update by-query (BelongsToTenant blocca il cambio sul model).
 *
 * ⚠️ Il tab Garanzie della scheda **non** mostra le righe ricambio, e non per
 * via degli scope: a escluderle è la clausola `strumento_id` della relazione
 * `Strumento::garanzie()`. Compaiono dal 15 Ago 2026 nel **tab Ricambi**, dove
 * vivono accanto al pezzo e alle azioni che le riguardano.
 */
class Garanzia extends Model implements ReachesStrumento
{
    /**
     * @use HasFactory<GaranziaFactory>
     *
     * Niente `BelongsToOrgNodeThroughStrumento`: il livello 2 vive in
     * `GaranziaDepartmentScope`, perché qui le strade verso lo strumento sono
     * due — `strumento_id` sulle righe macchina, il doppio salto via
     * `ricambio_utilizzo` su quelle ricambio. Il trait generico ne conosce una
     * sola. Vedi il docblock dello scope.
     */
    use AuditsDomainWrites, BelongsToTenant, HasFactory, SerializzaGiorniCivili, SoftDeletes;

    protected $table = 'garanzie';

    /**
     * `data_scadenza_effettiva` è deliberatamente ESCLUSA: è un valore derivato,
     * ricalcolato a ogni salvataggio dall'hook `saving`. Fuori da qui, nessun
     * payload (form, import, API future) può falsificare il campo che pilota
     * il semaforo. Stesso principio delle colonne `forced_*` di Strumento.
     *
     * **Anche `data_scadenza_dichiarata` è esclusa**, per una ragione diversa e
     * altrettanto concreta: se fosse assegnabile, un payload potrebbe portare
     * insieme `durata_mesi` e scadenza e andrebbe a sbattere sull'XOR con
     * un'eccezione invece che con un errore di validazione. Fuori di qui, la
     * coppia si tocca solo da `fissaScadenzaDichiarata()`/`fissaDurata()`, che
     * azzerano sempre l'altro lato.
     */
    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'soggetto',
        'strumento_id',
        'ricambio_utilizzo_id',
        'data_inizio',
        'durata_mesi',
    ];

    /**
     * `data_scadenza_effettiva` è il campo che pilota il semaforo, e
     * `data_scadenza_dichiarata` è l'input che lo determina nel ramo ADR-022:
     * senza queste due, l'audit registrerebbe la durata e non l'effetto.
     */
    protected function attributiDerivatiTracciati(): array
    {
        return ['data_scadenza_dichiarata', 'data_scadenza_effettiva'];
    }

    protected function casts(): array
    {
        return [
            'soggetto' => SoggettoGaranzia::class,
            'data_inizio' => 'date',
            'data_scadenza_dichiarata' => 'date',
            'data_scadenza_effettiva' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new GaranziaDepartmentScope);
        static::addGlobalScope(new GaranziaRicambioPrivacyScope);

        static::saving(function (Garanzia $garanzia): void {
            $garanzia->verificaSoggetto();
            $garanzia->normalizzaScadenza();
        });
    }

    /**
     * Invariante ERD §6.1: esattamente una fra `strumento_id` e
     * `ricambio_utilizzo_id`, coerente col soggetto. Una garanzia appesa a
     * nulla (o a entrambi) renderebbe ambiguo a chi si riferisce.
     */
    protected function verificaSoggetto(): void
    {
        $coerente = match ($this->soggetto) {
            SoggettoGaranzia::Macchina => $this->strumento_id !== null && $this->ricambio_utilizzo_id === null,
            SoggettoGaranzia::Ricambio => $this->ricambio_utilizzo_id !== null && $this->strumento_id === null,
        };

        if (! $coerente) {
            throw new InvalidArgumentException(
                'Garanzia: va valorizzato esattamente uno fra strumento_id e ricambio_utilizzo_id, coerente col soggetto (ERD §6.1).'
            );
        }
    }

    /**
     * Normalizzazione ADR-004/019, estesa da ADR-022 — l'unico punto in cui si
     * scrive `data_scadenza_effettiva`, ricalcolata a OGNI salvataggio:
     *
     *   durata_mesi valorizzata  →  data_inizio + durata_mesi   (ADR-019)
     *   scadenza dichiarata      →  la data dichiarata          (ADR-022)
     *
     * **`data_scadenza_effettiva` resta derivata al 100%.** La forma "a data" di
     * ADR-022 non l'ha resa un input: ha aggiunto una colonna di INPUT propria
     * (`data_scadenza_dichiarata`). La differenza non è di stile — oggi quel
     * campo è inattaccabile per due ragioni, essere fuori da `$fillable` **e**
     * essere riscritto incondizionatamente da qui; un ramo che lo leggesse come
     * input distruggerebbe la seconda, e nessun docblock la ricostruirebbe. La
     * falla non si chiude con una guardia: si chiude non aprendola.
     *
     * **XOR e non due `if` indipendenti**: entrambe valorizzate significherebbe
     * due verità sulla stessa scadenza, entrambe nulle una garanzia che non
     * scade mai. Nessuna delle due è rappresentabile.
     *
     * Si assegna un Carbon e non una stringa: su SQLite le colonne `date`
     * diventano 'Y-m-d 00:00:00', lo stesso formato di `interventi.data_scadenza`,
     * e il confronto fra le due colonne nell'ordinamento dell'elenco resta valido.
     *
     * **I confini sono imposti QUI e non solo dal form.** Il `min:1` e l'`after:`
     * della validazione coprono l'utente, non gli altri chiamanti: seeder,
     * import e migration di backfill scrivono senza passare dal form, ed è
     * esattamente così che la migration ADR-019 ha prodotto 90 righe con durata
     * 0 — una garanzia che scade il giorno in cui inizia. Guardia su due
     * livelli, come per l'invariante `stato = fatto ⇔ data_esecuzione`.
     */
    protected function normalizzaScadenza(): void
    {
        $inizio = $this->data_inizio ?? throw new InvalidArgumentException('Garanzia: `data_inizio` è obbligatoria.');

        if (($this->durata_mesi === null) === ($this->data_scadenza_dichiarata === null)) {
            throw new InvalidArgumentException(
                'Garanzia: va valorizzato esattamente uno fra `durata_mesi` e `data_scadenza_dichiarata` (ADR-019 + ADR-022).'
            );
        }

        if ($this->durata_mesi !== null) {
            if ($this->durata_mesi < 1) {
                throw new InvalidArgumentException('Garanzia: `durata_mesi` deve essere almeno 1 — una garanzia che scade il giorno in cui inizia non è una garanzia (ADR-019).');
            }

            $this->data_scadenza_effettiva = $inizio->copy()->addMonths($this->durata_mesi);

            return;
        }

        // Stessa soglia del ramo durata, espressa in date.
        if ($this->data_scadenza_dichiarata->lte($inizio)) {
            throw new InvalidArgumentException('Garanzia: la scadenza dichiarata deve essere successiva a `data_inizio` (ADR-022).');
        }

        $this->data_scadenza_effettiva = $this->data_scadenza_dichiarata->copy();
    }

    /**
     * Fissa la fine della garanzia come DATA dichiarata dall'operatore (ADR-022):
     * è ciò che si scrive nel form intervento per il pezzo montato, dove i mesi
     * non si conoscono e convertirli sposterebbe la scadenza.
     *
     * Non salva, ed è una scelta: questa garanzia nasce dentro la transazione
     * del form, agganciata a una riga `ricambio_utilizzo` che deve esistere
     * prima — un save qui dentro costringerebbe a due scritture.
     *
     * Azzera `durata_mesi`, perché le due forme sono alternative: insieme ai
     * gemello `fissaDurata()` è il motivo per cui l'XOR non è violabile da chi
     * passa dai metodi di dominio. `data_scadenza_dichiarata` è fuori da
     * `$fillable` proprio perché questa sia l'unica via.
     */
    public function fissaScadenzaDichiarata(CarbonInterface|string $scadenza): static
    {
        $this->data_scadenza_dichiarata = Carbon::parse($scadenza)->startOfDay();
        $this->durata_mesi = null;

        return $this;
    }

    /** Simmetrico: la forma "inizio + durata" di ADR-019, usata dalla garanzia macchina. */
    public function fissaDurata(int $mesi): static
    {
        $this->durata_mesi = $mesi;
        $this->data_scadenza_dichiarata = null;

        return $this;
    }

    /**
     * Garanzie che pesano sul semaforo: scadute o in scadenza entro la soglia
     * (ADR-005). Gemello SQL del confronto fatto da Semaforo::calcola.
     *
     * Nessun limite inferiore, di proposito: una garanzia scaduta è una fine
     * garanzia avvenuta, e resta rilevante come un intervento scaduto-non-fatto.
     * Non è un'attività che si "fa": si risolve rinnovandola o forzando il
     * verde con motivazione (ADR-005). Un limite inferiore inventerebbe una
     * seconda soglia che gli ADR non prevedono.
     *
     * Confine espresso come `< oggi+soglia+1` e MAI `<=`: vedi
     * Intervento::scopeApertiEntroSoglia (su SQLite le date sono stringhe).
     *
     * Colonna QUALIFICATA: da ADR-020 in poi questo scope gira anche dentro la
     * JOIN di `deiPezziMontati()`, e una colonna nuda in una query a due tabelle
     * è ambiguità che aspetta solo che qualcuno aggiunga un omonimo.
     *
     * @param  Builder<Garanzia>  $query
     */
    public function scopeEntroSoglia(Builder $query): void
    {
        $query->where(
            $query->getModel()->qualifyColumn('data_scadenza_effettiva'),
            '<',
            today()->addDays(Semaforo::giorniImminente() + 1)->toDateString()
        );
    }

    /**
     * Garanzie dei pezzi MONTATI su uno strumento: il primo anello del doppio
     * salto `garanzie → ricambio_utilizzo → strumenti` di ADR-020. Chi chiama
     * aggiunge il confine sullo strumento (`ricambio_utilizzo.strumento_id`) e
     * le colonne che gli servono.
     *
     * ⚠️ **È l'unico punto del progetto autorizzato a leggere le righe
     * `soggetto = ricambio` senza `GaranziaRicambioPrivacyScope`** (ADR-020 ·
     * Policy di Code Review §aree rosse · Schema Ruoli §6), e per questo esiste
     * come scope invece che ripetuto nei quattro chiamanti: un'eccezione a una
     * regola di privacy vale finché è UNA, e quattro copie sono quattro cose
     * libere di divergere — la prima corretta da sola aprirebbe un buco muto.
     *
     * Il bypass è legittimo perché il pallino è un **aggregato dovuto a tutti**:
     * il Tenant non vede le righe (ADR-004) ma vede l'arancione che ne deriva,
     * che è informazione sul proprio bene. Il DETTAGLIO resta protetto, e il
     * modo per non farlo trapelare non è una guardia ma la selezione: i
     * chiamanti prendono id e data, mai il nome del pezzo (vedi
     * `Strumento::scadenzeGaranzieRicambi()`).
     *
     * Restano attivi `TenantScope`, `GaranziaDepartmentScope` (che raggiunge
     * queste stesse righe col proprio doppio salto) e il soft delete di
     * `Garanzia`. Quello di `ricambio_utilizzo` NO — la join non passa dal
     * model — e va quindi riapplicato a mano: è la consegna n.1 scritta nel
     * docblock di `RicambioUtilizzo`, «una riga cestinata non esiste per nessuna
     * lettura di dominio, semaforo compreso». Un pezzo smontato per errore non
     * deve accendere l'arancione.
     *
     * **«Montati» è preso alla lettera, ed è `data` a dirlo.** Una riga con
     * `data` NULL è un pezzo registrato ma non ancora installato — «montaggio
     * ancora non effettuato», perché la data di montaggio segue la CHIUSURA
     * dell'intervento — e un pezzo che non è sulla macchina non ne descrive lo
     * stato: la sua garanzia non deve accendere il semaforo finché il lavoro
     * non è fatto. Il filtro non era nel testo di ADR-020, che definiva la
     * fonte col solo doppio salto: allora `data` era NOT NULL e la distinzione
     * non esisteva: è nata il 9 Ago 2026 rendendola nullable, e la si è vista
     * solo provando il flusso vero in browser — un intervento pianificato per
     * il 2027 accendeva l'arancione oggi.
     *
     * Nessun filtro su `soggetto`: la FK `ricambio_utilizzo_id` è valorizzata
     * sse il soggetto è `ricambio` (invariante di `verificaSoggetto()`), quindi
     * la join lo impone già — e un secondo controllo sarebbe una seconda regola
     * che può divergere dalla prima.
     *
     * @param  Builder<Garanzia>  $query
     */
    public function scopeDeiPezziMontati(Builder $query): void
    {
        $query->withoutGlobalScope(GaranziaRicambioPrivacyScope::class)
            ->join('ricambio_utilizzo', 'ricambio_utilizzo.id', '=', 'garanzie.ricambio_utilizzo_id')
            ->whereNull('ricambio_utilizzo.deleted_at')
            ->whereNotNull('ricambio_utilizzo.data');
    }

    /**
     * Le garanzie dei pezzi montati sulla riga `strumenti` della query esterna
     * (ADR-020), pronte per un `whereExists` o per un aggregato correlato.
     *
     * Esiste come scope, e non ripetuta nei chiamanti, per la ragione che vale
     * già per `deiPezziMontati()` un gradino più su: la **correlazione** è
     * parte della regola quanto il doppio salto, e due copie sono due cose
     * libere di divergere. La usano il filtro per stato, l'ordinamento per
     * stato e quello per prossima scadenza — cioè i tre punti in cui la terza
     * fonte dell'arancione entra in SQL.
     *
     * `ricambio_utilizzo.strumento_id` e non `garanzie.strumento_id`: su una
     * riga `soggetto = ricambio` quella colonna è NULL per invariante, e la
     * macchina si raggiunge solo attraverso il montaggio.
     *
     * @param  Builder<Garanzia>  $query
     */
    public function scopeDeiPezziMontatiSullaRiga(Builder $query): void
    {
        $query->deiPezziMontati()->whereColumn('ricambio_utilizzo.strumento_id', 'strumenti.id');
    }

    /** True se la garanzia è già finita. */
    public function isScaduta(): bool
    {
        return $this->data_scadenza_effettiva->lt(today());
    }

    /**
     * Implementazione di `ReachesStrumento` (ADR-030) — e il caso per cui quel
     * contratto chiede una RESTRIZIONE invece di una colonna.
     *
     * Le strade verso lo strumento sono due, le stesse di
     * `GaranziaDepartmentScope` (ERD §6.1):
     *
     *   soggetto = macchina  →  garanzie.strumento_id
     *   soggetto = ricambio  →  garanzie.ricambio_utilizzo_id → ricambio_utilizzo.strumento_id
     *
     * Con un contratto "dammi la colonna" il secondo ramo sarebbe inesprimibile:
     * su una riga `ricambio` `strumento_id` è NULL e `NULL IN (...)` è UNKNOWN,
     * quindi il Tecnico non vedrebbe le garanzie dei pezzi montati sulla
     * macchina che gli è stata assegnata — fail-closed, ma sbagliato, perché il
     * permesso ce l'ha (è lo stesso difetto che il blocco 2 aveva corretto per
     * il Responsabile).
     *
     * ⚠️ **Le due clausole stanno in un gruppo di parentesi proprio.** Fuori dal
     * gruppo l'OR si legherebbe alla condizione applicata prima — il portafoglio
     * — e il risultato sarebbe «(portafoglio OR strumento assegnato) OR utilizzo
     * assegnato» invece dell'unione voluta: un ramo senza il vincolo che lo
     * precede. Uno scope mal parentesizzato non restringe, ALLARGA.
     *
     * ⚠️ **La subquery interna NON riapplica `CurrentTenant::id()`**, ed è la
     * differenza rispetto a `GaranziaDepartmentScope`: per un tecnico ESTERNO
     * quel valore è NULL, e `where tenant_id = null` non seleziona nulla — il
     * ramo ricambio morirebbe in silenzio proprio per chi ne ha più bisogno. Il
     * confine non si perde: `$strumenti` contiene già i soli strumenti a cui il
     * tecnico ha titolo, e il tenant di una riga `ricambio_utilizzo` è per
     * invariante quello del suo strumento.
     *
     * `withoutGlobalScopes()` per non annidare un secondo livello 2 dentro il
     * primo; il soft delete va quindi riapplicato a mano — una riga cestinata
     * non esiste per nessuna lettura di dominio.
     *
     * @param  Builder<*>  $query
     * @param  Builder<*>|BuilderContract  $strumenti
     */
    public function vincolaAStrumenti(Builder $query, Builder|BuilderContract $strumenti): void
    {
        $query->where(fn (Builder $strade) => $strade
            ->whereIn($this->qualifyColumn('strumento_id'), $strumenti)
            ->orWhereIn(
                $this->qualifyColumn('ricambio_utilizzo_id'),
                RicambioUtilizzo::withoutGlobalScopes()
                    ->whereNull('ricambio_utilizzo.deleted_at')
                    ->whereIn('ricambio_utilizzo.strumento_id', $strumenti)
                    ->select('ricambio_utilizzo.id')
            ));
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }

    /**
     * Riga di montaggio a cui la garanzia si riferisce, valorizzata sse
     * `soggetto = ricambio` (ERD §6.1). Primo anello del doppio salto
     * `garanzie → ricambio_utilizzo → strumenti` di ADR-020.
     */
    public function ricambioUtilizzo(): BelongsTo
    {
        return $this->belongsTo(RicambioUtilizzo::class, 'ricambio_utilizzo_id');
    }
}

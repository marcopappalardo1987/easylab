<?php

namespace App\Models;

use App\Enums\TipoUnitaOrganizzativa;
use App\Enums\VisibilitaGaranzieRicambio;
use App\Models\Concerns\BelongsToOrgNode;
use App\Models\Concerns\BelongsToTenant;
use App\Support\AuditLog;
use Database\Factories\UnitaOrganizzativaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Nodo dell'albero organizzativo del cliente (ERD §4.1 — ADR-006/015).
 * Il nodo radice `tipo = ente` È il tenant.
 *
 * Scope: BelongsToTenant (livello 1, isolamento tra Enti) + BelongsToOrgNode
 * (livello 2, sotto-albero Responsabile — qui ristretto per il proprio `id`).
 */
class UnitaOrganizzativa extends Model
{
    /** @use HasFactory<UnitaOrganizzativaFactory> */
    use BelongsToOrgNode, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'unita_organizzativa';

    /**
     * Il default di `visibilita_garanzie_ricambio` (ADR-029) è dichiarato QUI
     * oltre che in colonna, e non è una ripetizione oziosa: il default del DB
     * si applica all'INSERT, quindi un'istanza appena creata lo porterebbe a
     * `null` finché qualcuno non la rilegge — e la Policy, trovando null,
     * ricadrebbe sul proprio fallback. Due strade per lo stesso valore sono
     * accettabili solo perché il valore è uno e sta scritto nell'enum.
     */
    protected $attributes = [
        'visibilita_garanzie_ricambio' => 'modifica',
    ];

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'parent_id',
        'tipo',
        'nome',
        'note',
        'soglia_obsolescenza_anni',
    ];

    /**
     * `visibilita_garanzie_ricambio` è deliberatamente ESCLUSA dal
     * mass-assignment (ADR-029): è una clausola del rapporto commerciale che
     * solo il Superadmin decide, e il form dell'anagrafica — dove l'Admin
     * dell'Ente scrive nome, note e soglia — passa proprio di lì. Tenendola
     * fuori, l'unica via è `fissaVisibilitaGaranzieRicambio()`, che è anche il
     * punto in cui il controllo del ruolo è esercitato. Stesso principio delle
     * colonne `forced_*` di Strumento e di `data_scadenza_effettiva` su Garanzia.
     *
     * Fuori anche `account_id` (ADR-032): dice a quale rapporto commerciale
     * appartiene l'Ente, e lo scrivono solo provisioning e backfill, mai un
     * form. Un Admin che potesse forgiarlo sposterebbe il proprio Ente sotto
     * l'abbonamento di qualcun altro.
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoUnitaOrganizzativa::class,
            // Solo sul nodo ente (ADR-014); sugli altri resta al default e non si legge.
            'soglia_obsolescenza_anni' => 'integer',
            // Idem (ADR-029): ha senso solo sull'Ente.
            'visibilita_garanzie_ricambio' => VisibilitaGaranzieRicambio::class,
        ];
    }

    /**
     * Unica via per cambiare la visibilità delle garanzie ricambio (ADR-029).
     *
     * Il metodo esiste perché la colonna è fuori da `$fillable`: senza, il
     * form dell'anagrafica potrebbe scriverla per mass-assignment insieme a
     * nome e note, e il controllo «solo il Superadmin» vivrebbe unicamente
     * nella vista — cioè nel posto più facile da aggirare.
     */
    public function fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio $visibilita): bool
    {
        $precedente = $this->visibilita_garanzie_ricambio;
        $this->visibilita_garanzie_ricambio = $visibilita;

        $salvato = $this->save();

        // Tracciata a mano e non col trait (ADR-027: o l'uno o le altre).
        // ADR-029 ha scartato la concessione RBAC caso-per-caso con l'argomento
        // che «un'eccezione concessa a mano non lascia traccia di CHI l'ha
        // decisa»: senza questa riga, l'impostazione scelta al suo posto non ne
        // lascerebbe neanche lei, e l'argomento che regge la decisione non
        // sarebbe soddisfatto dall'implementazione della decisione stessa.
        //
        // È una clausola del rapporto commerciale con un cliente: qui conta chi
        // e quando, non l'elenco dei campi — ed è il criterio per cui questo
        // gesto vuole l'esplicita e non il trait.
        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($this)
            ->withProperties([
                'da' => $precedente?->value,
                'a' => $visibilita->value,
            ])
            ->log('Visibilità garanzie ricambio modificata');

        return $salvato;
    }

    /**
     * Un nodo è ristretto dal livello 2 in base al proprio id (non a una
     * colonna di collocazione esterna).
     */
    public function orgNodeColumn(): string
    {
        return 'id';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Nodo ente radice (= tenant) a cui appartiene questo nodo.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'tenant_id');
    }

    /**
     * L'account intestatario (ADR-032) — valorizzato solo sul nodo `ente`.
     * `Account` non è tenant-scoped, quindi nessun bypass serve.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Primo `booted()` di questo model, per un invariante che a DB non può
     * stare: «`account_id` vive solo sul nodo ente» sarebbe un CHECK, ma
     * SQLite non ha ADD CONSTRAINT e la migration ramificherebbe per driver.
     * Qui la guardia vale per CRUD, console e seeder insieme (il backfill di
     * schema passa dal query builder e tocca per costruzione solo righe ente).
     */
    protected static function booted(): void
    {
        static::saving(function (self $nodo): void {
            if ($nodo->account_id !== null && $nodo->tipo !== TipoUnitaOrganizzativa::Ente) {
                throw new RuntimeException(
                    "account_id appartiene solo ai nodi ente (ADR-032): il nodo «{$nodo->nome}» è {$nodo->tipo->value}."
                );
            }
        });
    }

    public function responsabili(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'responsabile_unita')->withTimestamps();
    }
}

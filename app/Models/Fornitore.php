<?php

namespace App\Models;

use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FornitoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Fornitore da cui una macchina è stata acquistata (ERD §7.3 — ADR-023).
 *
 * **Niente `BelongsToOrgNode`**, ed è la stessa scelta di `Ricambio`: il
 * fornitore è dell'ENTE e non di un reparto, quindi non ha collocazione
 * nell'albero. A essere ristretto dal livello 2 è ciò che sa su quale strumento
 * sta — cioè `strumenti.fornitore_id`, che eredita lo scope di `Strumento` —
 * non l'anagrafica. Un Responsabile Reparto vede quindi tutti i fornitori del
 * proprio Ente: è corretto, e un lettore veloce lo darebbe per un difetto.
 *
 * Il trait di audit c'è perché il meta-test di copertura (ADR-027) obbliga a
 * scegliere: qui la domanda «chi ha cambiato questo recapito» ha risposta nelle
 * colonne, quindi il trait basta e le `activity()` esplicite non servono.
 */
class Fornitore extends Model
{
    /** @use HasFactory<FornitoreFactory> */
    use AuditsDomainWrites, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'fornitori';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'ragione_sociale',
        'email',
        'telefono',
        'note',
    ];

    /**
     * La guardia sta nel MODEL e non nel componente, come `forzaSemaforo()`
     * tiene la propria: vale per il CRUD di oggi, per l'import di domani e per
     * la console — e cancellare un fornitore ancora associato a delle macchine
     * lascerebbe la scheda con un riferimento che non si può più leggere.
     *
     * Il soft delete non è una scusa per saltarla: una riga cestinata sparisce
     * comunque dalle letture di dominio, e la scheda mostrerebbe un vuoto.
     */
    protected static function booted(): void
    {
        static::deleting(function (Fornitore $fornitore): void {
            if ($fornitore->strumenti()->exists()) {
                throw new RuntimeException(
                    'Fornitore: non si cancella un fornitore ancora associato a delle macchine (ADR-023). Riassegnale prima.'
                );
            }

            // 🔗 ADR-051: lo stesso vale per i pezzi che ha venduto.
            if ($fornitore->ricambiMontati()->exists()) {
                throw new RuntimeException(
                    'Fornitore: non si cancella un fornitore ancora associato a dei ricambi montati (ADR-051). Riassegnali prima.'
                );
            }
        });
    }

    /** @return HasMany<Strumento, $this> */
    public function strumenti(): HasMany
    {
        return $this->hasMany(Strumento::class, 'fornitore_id');
    }

    /**
     * I pezzi montati comprati da questo fornitore (🔗 ADR-051).
     *
     * @return HasMany<RicambioUtilizzo, $this>
     */
    public function ricambiMontati(): HasMany
    {
        return $this->hasMany(RicambioUtilizzo::class, 'fornitore_id');
    }

    protected function nomeDominio(): string
    {
        return 'fornitore';
    }
}

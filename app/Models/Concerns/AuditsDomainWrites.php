<?php

namespace App\Models\Concerns;

use App\Support\AuditLog;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Traccia le scritture di dominio sul canale `audit` (ADR-027).
 *
 * ADR-027 ha sostituito un divieto con una traccia: il Tecnico ha
 * `garanzie.ricambio.manage` perché è chi monta il pezzo, **e ogni sua
 * scrittura è tracciata**. Le due metà di quel patto vanno insieme — questo
 * trait è la seconda.
 *
 * **Regola, scritta una volta sola: un model usa O questo trait O le chiamate
 * `activity()` esplicite, mai entrambi.** `Strumento` logga a mano perché i
 * suoi due gesti (`forzaSemaforo`/`rimuoviForzatura`) hanno un messaggio che
 * vale più dell'elenco dei campi cambiati, e perché le colonne `forced_*` sono
 * fuori da `$fillable` e il set di default non le vedrebbe. Mettere anche il
 * trait produrrebbe due righe per un gesto solo, e la vista Audit di S6 le
 * mostrerebbe entrambe.
 *
 * `dontLogEmptyChanges()` non è cosmesi: l'hook `saving` di `Garanzia` riscrive
 * `data_scadenza_effettiva` a ogni salvataggio, quindi senza questa opzione
 * ogni save innocuo lascerebbe una riga di audit.
 */
trait AuditsDomainWrites
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(AuditLog::NAME)
            ->logOnly(array_values(array_diff(
                array_merge($this->getFillable(), $this->attributiDerivatiTracciati()),
                ['reseller_id'] // predisposto e sempre NULL in V1 (ADR-002): rumore
            )))
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $evento) => AuditLog::descrizione($evento, $this->nomeDominio()));
    }

    /**
     * Colonne DERIVATE, fuori da `$fillable` ma che contano per l'audit: sono
     * spesso quelle che decidono qualcosa (la scadenza che pilota il semaforo,
     * la chiave del collega-o-crea). Senza, l'audit registrerebbe l'input e non
     * l'effetto.
     *
     * @return list<string>
     */
    protected function attributiDerivatiTracciati(): array
    {
        return [];
    }

    /** Sostantivo per la descrizione. Default: il nome della classe. */
    protected function nomeDominio(): string
    {
        return mb_strtolower(class_basename($this));
    }
}

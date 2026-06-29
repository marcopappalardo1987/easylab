<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Modello usa-e-getta per testare il trait BelongsToTenant / TenantScope
 * prima che esistano i modelli di business reali (S2 punti 4-5).
 * La tabella `tenant_things` è creata al volo nel test.
 */
class TenantThing extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_things';

    protected $guarded = [];
}

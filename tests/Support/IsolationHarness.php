<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Batteria riusabile di assert d'isolamento multi-tenant (ADR-001), generica:
 * serve solo la classe modello (con BelongsToTenant), gli id visibili
 * all'utente corrente e un id appartenente a un altro tenant. Verifica che
 * NESSUN vettore di query permetta di leggere o scrivere il dato estraneo.
 *
 * Va invocata DOPO `actingAs()` di un utente tenant-bound. Riusabile su ogni
 * modello dei punti 4-5: una chiamata per modello.
 */
class IsolationHarness
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $ownIds  id visibili all'utente corrente
     * @param  int  $foreignId  id di una riga di un altro tenant
     */
    public static function assertReadIsolation(string $modelClass, array $ownIds, int $foreignId): void
    {
        // Lettura: la riga estranea non compare in nessun set.
        $visible = $modelClass::pluck('id')->all();
        assertCount(count($ownIds), $visible, 'Conteggio righe visibili inatteso');
        assertSame(
            [],
            array_values(array_intersect([$foreignId], $visible)),
            'Una riga di un altro tenant è visibile'
        );

        assertNull($modelClass::find($foreignId), 'find() ha restituito una riga di un altro tenant');
        assertFalse($modelClass::where('id', $foreignId)->exists(), 'exists() vede una riga di un altro tenant');

        $threw = false;
        try {
            $modelClass::findOrFail($foreignId);
        } catch (ModelNotFoundException) {
            $threw = true;
        }
        assertTrue($threw, 'findOrFail() non ha lanciato ModelNotFound (route-binding non protetto)');

        // Scrittura by-query: update/delete non toccano la riga estranea.
        $affectedUpdate = $modelClass::where('id', $foreignId)->update(['updated_at' => now()]);
        assertSame(0, $affectedUpdate, 'update() ha modificato una riga di un altro tenant');

        $modelClass::where('id', $foreignId)->delete();

        $survivor = $modelClass::withoutGlobalScopes();
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
        if ($softDeletes) {
            $survivor->withTrashed();
        }
        $row = $survivor->find($foreignId);
        assertNotNull($row, 'delete() ha cancellato una riga di un altro tenant');
        if ($softDeletes) {
            assertFalse($row->trashed(), 'delete() ha soft-deleted una riga di un altro tenant');
        }
    }
}

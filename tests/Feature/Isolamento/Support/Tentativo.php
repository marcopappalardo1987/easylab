<?php

namespace Tests\Feature\Isolamento\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Un'azione Livewire (o un gesto qualunque) tentata con un id dell'Ente B.
 *
 * Due esiti ammessi, entrambi fail-closed: l'azione si rompe su «non trovato /
 * non autorizzato», oppure prosegue senza aver toccato nulla. In ogni caso la
 * riga estranea resta identica e nessun marcatore dell'Ente B esce nell'HTML.
 */
final class Tentativo
{
    /**
     * @param  callable(): mixed  $gesto  restituisce il Testable (o null)
     * @param  list<string>|null  $marcatori  testo che non deve uscire; default: tutto l'Ente B
     */
    public static function senzaEffetto(Model $estranea, callable $gesto, ?array $marcatori = null): void
    {
        $prima = self::fotografia($estranea);

        $esito = null;
        try {
            $esito = $gesto();
        } catch (ModelNotFoundException|AuthorizationException) {
            // fail-closed: la riga «non esiste» per chi chiede
        } catch (HttpException $e) {
            assertContains($e->getStatusCode(), [403, 404], 'Eccezione HTTP inattesa: '.$e->getStatusCode());
        }

        if ($esito !== null && method_exists($esito, 'html')) {
            $html = $esito->html();
            foreach ($marcatori ?? MondoDueEnti::marcatori('B') as $marcatore) {
                assertStringNotContainsString($marcatore, $html, "Il marcatore {$marcatore} è uscito nell'HTML");
            }
        }

        $dopo = self::fotografia($estranea);
        assertNotNull($dopo, 'La riga dell\'altro Ente è stata cancellata');
        assertEquals($prima, $dopo, 'La riga dell\'altro Ente è stata modificata');
    }

    /** @return array<string, mixed>|null */
    private static function fotografia(Model $riga): ?array
    {
        $query = $riga::withoutGlobalScopes();
        if (in_array(SoftDeletes::class, class_uses_recursive($riga), true)) {
            $query->withTrashed();
        }

        $fresca = $query->find($riga->getKey());
        if ($fresca === null) {
            return null;
        }
        if (in_array(SoftDeletes::class, class_uses_recursive($riga), true)) {
            assertFalse($fresca->trashed(), 'La riga dell\'altro Ente è finita nel cestino');
        }

        return $fresca->getAttributes();
    }
}

<?php

namespace App\Support;

use App\Enums\StatoSemaforo;
use Carbon\CarbonInterface;

/**
 * Motore semaforo calcolato (ADR-005 — S3 punto 4). Funzione pura su
 * attività + garanzie: `stato_semaforo_calcolato` è derivato, MAI persistito.
 * Non produce mai Rosso: il rosso è solo esito di forzatura manuale (punto 5).
 *
 * La definizione di "scaduto" NON vive qui ma in Intervento::isScaduto() /
 * scopeScadute() (unica fonte): il motore riceve solo scadenze di interventi
 * già filtrati come APERTI, e i casi "scaduto" (< oggi) e "imminente"
 * (<= oggi+soglia) collassano nello stesso confronto — non esiste una seconda
 * copia della regola che possa divergere.
 */
final class Semaforo
{
    /**
     * Soglia "imminente" in giorni (config/easylab.php). Decisione S3:
     * 30 giorni uguali per tutti gli Enti; la personalizzazione per-tenant
     * è rinviata (post-V1) e cambierà solo questo metodo.
     */
    public static function giorniImminente(): int
    {
        return (int) config('easylab.semaforo.giorni_imminente', 30);
    }

    /**
     * Regola pura ADR-005.
     *
     * $prossimaScadenza = scadenza minima tra gli interventi APERTI
     * (non_fatto) dello strumento: se è nel passato c'è uno scaduto-non-fatto,
     * se è entro la soglia è imminente — in entrambi i casi Arancione.
     *
     * $scadenzaGaranzia = min(data_scadenza_effettiva) delle garanzie
     * (ADR-004) — arriva col punto 7; una garanzia scaduta o imminente
     * accende comunque l'Arancione, mai il Rosso.
     *
     * Confini (coerenti con isScaduto, che esclude oggi):
     *   scaduto   ⇔ data < oggi
     *   imminente ⇔ oggi <= data <= oggi + soglia  (oggi+soglia INCLUSO)
     *   verde     ⇔ data > oggi + soglia
     */
    public static function calcola(
        ?CarbonInterface $prossimaScadenza,
        ?CarbonInterface $scadenzaGaranzia = null,
    ): StatoSemaforo {
        $limite = today()->addDays(self::giorniImminente());

        foreach ([$prossimaScadenza, $scadenzaGaranzia] as $data) {
            if ($data !== null && $data->copy()->startOfDay()->lte($limite)) {
                return StatoSemaforo::Arancione; // scaduto O imminente
            }
        }

        return StatoSemaforo::Verde;
    }
}

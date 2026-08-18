<?php

namespace App\Support;

use App\Enums\StatoSemaforo;
use App\Enums\TipoMotivoSemaforo;
use Carbon\CarbonInterface;

/**
 * Motore semaforo calcolato (ADR-005 — S3 punto 4; esteso da ADR-024).
 * Funzione pura su attività + garanzie: `stato_semaforo_calcolato` è derivato,
 * MAI persistito. Non produce mai Rosso: il rosso è solo esito di forzatura
 * manuale (punto 5).
 *
 * La definizione di "scaduto" NON vive qui ma in Intervento::isScaduto() /
 * scopeScadute() (unica fonte): il motore riceve solo scadenze di interventi
 * già filtrati come APERTI, e i casi "scaduto" (< oggi) e "imminente"
 * (<= oggi+soglia) collassano nello stesso confronto — non esiste una seconda
 * copia della regola che possa divergere.
 *
 * **ADR-020.** Le fonti "garanzia" sono due — macchina e pezzi montati — ma il
 * motore non lo sa: riceve candidati e confronta scadenze. È il segno che il
 * refactor di ADR-024 ha fatto il proprio lavoro, perché il blocco 4 di S4 ha
 * aggiunto una fonte senza toccare la regola.
 *
 * **ADR-024.** Il motore restituisce una `DiagnosiSemaforo` (stato + motivi):
 * finora sapeva dire *se* accendere l'arancione ma non *perché*, e con le
 * cause sparse fra i tab quella era l'informazione che mancava all'utente.
 * `calcola()` resta come involucro sottile per i chiamanti che vogliono il
 * solo stato — l'elenco strumenti su tutti.
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
     * Diagnosi (ADR-024): dai motivi CANDIDATI trattiene quelli che accendono
     * l'arancione e ne deriva lo stato.
     *
     * Riceve i candidati già costruiti — chi li assembla è `Strumento`, che sa
     * quali sono le proprie fonti; il motore sa solo quale scadenza conta.
     * Ordinati per scadenza (il più urgente in cima), a parità per id così
     * l'ordine è stabile fra un caricamento e l'altro.
     */
    public static function diagnostica(MotivoSemaforo ...$candidati): DiagnosiSemaforo
    {
        $motivi = array_values(array_filter(
            $candidati,
            fn (MotivoSemaforo $motivo) => self::entroSoglia($motivo->scadenza),
        ));

        usort($motivi, fn (MotivoSemaforo $a, MotivoSemaforo $b) => [$a->scadenza->getTimestamp(), $a->riferimentoId ?? 0]
            <=> [$b->scadenza->getTimestamp(), $b->riferimentoId ?? 0]);

        return new DiagnosiSemaforo($motivi);
    }

    /**
     * Regola pura ADR-005, oggi involucro sottile sopra la diagnosi.
     *
     * $prossimaScadenza = scadenza minima tra gli interventi APERTI
     * (non_fatto) dello strumento: se è nel passato c'è uno scaduto-non-fatto,
     * se è entro la soglia è imminente — in entrambi i casi Arancione.
     *
     * $scadenzaGaranzia = min(data_scadenza_effettiva) delle garanzie MACCHINA
     * (ADR-004) — una garanzia scaduta o imminente accende comunque
     * l'Arancione, mai il Rosso.
     *
     * $scadenzaGaranziaRicambio = idem per le garanzie dei pezzi MONTATI sullo
     * strumento (ADR-020, S4 blocco 4): stessa scala e stessa soglia, nessuna
     * seconda costante. È un terzo parametro e non un secondo minimo fuso col
     * precedente perché la colonna "Prossima scadenza" deve poter dire da quale
     * delle due fonti viene la data — e con permessi diversi.
     *
     * Prende date sciolte e non i model, perché il calcolo bulk
     * dell'elenco (una query per pagina) ha solo quelle: i motivi che ne
     * costruisce sono quindi anonimi e vengono scartati, resta lo stato.
     *
     * Confini (coerenti con isScaduto, che esclude oggi):
     *   scaduto   ⇔ data < oggi
     *   imminente ⇔ oggi <= data <= oggi + soglia  (oggi+soglia INCLUSO)
     *   verde     ⇔ data > oggi + soglia
     */
    public static function calcola(
        ?CarbonInterface $prossimaScadenza,
        ?CarbonInterface $scadenzaGaranzia = null,
        ?CarbonInterface $scadenzaGaranziaRicambio = null,
    ): StatoSemaforo {
        $candidati = [];

        if ($prossimaScadenza !== null) {
            $candidati[] = MotivoSemaforo::anonimo(TipoMotivoSemaforo::Intervento, $prossimaScadenza);
        }

        if ($scadenzaGaranzia !== null) {
            $candidati[] = MotivoSemaforo::anonimo(TipoMotivoSemaforo::GaranziaMacchina, $scadenzaGaranzia);
        }

        if ($scadenzaGaranziaRicambio !== null) {
            $candidati[] = MotivoSemaforo::anonimo(TipoMotivoSemaforo::GaranziaRicambio, $scadenzaGaranziaRicambio);
        }

        return self::diagnostica(...$candidati)->stato;
    }

    /**
     * L'UNICO confronto con la soglia di tutto il motore. Sta qui, privato, e
     * non nei chiamanti: `diagnostica()` e `calcola()` lo condividono, quindi
     * non possono divergere sul confine.
     *
     * `startOfDay()` perché le date arrivano castate e il confronto deve
     * essere sul giorno, non sull'istante.
     */
    private static function entroSoglia(CarbonInterface $scadenza): bool
    {
        return $scadenza->copy()->startOfDay()->lte(today()->addDays(self::giorniImminente()));
    }
}

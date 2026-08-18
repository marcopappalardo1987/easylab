<?php

namespace App\Support;

use App\Enums\TipoMotivoSemaforo;
use App\Models\Garanzia;
use App\Models\Intervento;
use Carbon\CarbonInterface;

/**
 * Un motivo della diagnosi semaforo (ADR-024): la riga che accende
 * l'arancione, con la propria scadenza e il riferimento alla fonte.
 *
 * **Perché `readonly`** — primo caso nel progetto, quindi vale spiegarlo. Un
 * motivo è una fotografia presa al momento della diagnosi: se fosse mutabile,
 * qualcuno potrebbe cambiargli la scadenza dopo che lo stato è stato derivato,
 * e stato e motivi divergerebbero. Sarebbe la "terza verità" che ADR-005/024
 * esistono per impedire.
 *
 * **Costruttore privato, solo named constructor.** `scaduto` non si calcola
 * qui: arriva da `Intervento::isScaduto()` / `Garanzia::isScaduta()`, che sono
 * le uniche definizioni di "scaduto" nel progetto. Lasciare il costruttore
 * pubblico significherebbe permettere a un chiamante di dichiarare scaduto ciò
 * che non lo è — cioè creare a mano quella copia della regola.
 */
final class MotivoSemaforo
{
    /**
     * @param  bool|null  $scaduto  true = scaduto, false = imminente, null = non
     *                              determinato (motivo anonimo, vedi `anonimo()`)
     * @param  int|null  $riferimentoId  id della riga d'origine; null quando non
     *                                   c'è una riga mostrabile
     * @param  string|null  $dettaglio  testo della riga d'origine (es. descrizione
     *                                  dell'intervento). Il blade lo mostra SOLO a
     *                                  chi ha il permesso dell'area di provenienza.
     */
    private function __construct(
        public readonly TipoMotivoSemaforo $tipo,
        public readonly CarbonInterface $scadenza,
        public readonly ?bool $scaduto,
        public readonly ?int $riferimentoId,
        public readonly ?string $dettaglio,
    ) {}

    /** Motivo da un intervento aperto: `scaduto` dalla regola unica del model. */
    public static function daIntervento(Intervento $intervento): self
    {
        return new self(
            tipo: TipoMotivoSemaforo::Intervento,
            scadenza: $intervento->data_scadenza,
            scaduto: $intervento->isScaduto(),
            riferimentoId: $intervento->id,
            dettaglio: $intervento->descrizione,
        );
    }

    /** Motivo dalla garanzia del macchinario: `scaduto` da `Garanzia::isScaduta()`. */
    public static function daGaranziaMacchina(Garanzia $garanzia): self
    {
        return new self(
            tipo: TipoMotivoSemaforo::GaranziaMacchina,
            scadenza: $garanzia->data_scadenza_effettiva,
            scaduto: $garanzia->isScaduta(),
            riferimentoId: $garanzia->id,
            dettaglio: null,
        );
    }

    /**
     * Motivo dalla garanzia di un pezzo montato (ADR-020, S4 blocco 4).
     *
     * `dettaglio` è **null e resta null**: il nome del pezzo è il dato che
     * ADR-004 protegge, e la riga da cui questo motivo nasce arriva da
     * `Strumento::scadenzeGaranzieRicambi()`, che seleziona apposta le sole
     * colonne id e scadenza. Non c'è quindi nulla da nascondere qui, perché non
     * c'è nulla da mostrare: la protezione è nella forma della query, non in una
     * guardia che qualcuno può dimenticare. Il nome del pezzo compare nel tab
     * Ricambi (S4 blocco 5), dove il permesso è controllato una volta per tutta
     * la schermata.
     *
     * `scaduto` da `Garanzia::isScaduta()` come per la garanzia macchina: una
     * sola definizione di "scaduta", che qui funziona anche su un model
     * idratato con due sole colonne.
     */
    public static function daGaranziaRicambio(Garanzia $garanzia): self
    {
        return new self(
            tipo: TipoMotivoSemaforo::GaranziaRicambio,
            scadenza: $garanzia->data_scadenza_effettiva,
            scaduto: $garanzia->isScaduta(),
            riferimentoId: $garanzia->id,
            dettaglio: null,
        );
    }

    /**
     * Motivo costruito da una data nuda, senza la riga che la origina.
     *
     * Serve solo a `Semaforo::calcola()`, che riceve due date sciolte e vuole
     * il solo stato: `scaduto` resta **null** perché senza il model non c'è
     * modo di saperlo, e inventarlo sarebbe una copia della regola. Un motivo
     * anonimo non arriva mai alla UI — la Panoramica legge quelli di
     * `Strumento::diagnosiSemaforo()`, tutti costruiti dai model.
     */
    public static function anonimo(TipoMotivoSemaforo $tipo, CarbonInterface $scadenza): self
    {
        return new self($tipo, $scadenza, scaduto: null, riferimentoId: null, dettaglio: null);
    }
}

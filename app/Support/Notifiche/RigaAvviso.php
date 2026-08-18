<?php

namespace App\Support\Notifiche;

use App\Enums\TipoMotivoSemaforo;
use App\Enums\TransizioneAvviso;
use App\Models\Garanzia;
use App\Models\Intervento;
use Carbon\CarbonInterface;

/**
 * Una riga del digest delle scadenze (ADR-011, scheduler S5).
 *
 * Gemello di `MotivoSemaforo` per forma e per ragioni: `readonly` perché è la
 * fotografia di una scadenza al momento dell'invio — l'email che parte domani
 * deve dire ciò che il comando ha visto oggi — e costruttore privato perché
 * `transizione` non si decide qui. La distinzione imminente/scaduta arriva da
 * `Intervento::isScaduto()` e `Garanzia::isScaduta()`, che restano le uniche
 * definizioni di «scaduto» del progetto; lasciarla dichiarare al chiamante
 * significherebbe permettergli di scrivere «scaduta» in un'email su qualcosa che
 * scade domani.
 *
 * Differenza con `MotivoSemaforo`, ed è la ragione per cui è una classe a parte:
 * quello risponde a «perché questo strumento è arancione» e vive dentro una
 * scheda già autorizzata; questo risponde a «cosa scrivo nell'email» e viaggia
 * fuori dall'applicazione, dove non c'è nessuna schermata a fare da gate. Porta
 * quindi anche lo strumento (nome e nodo, per raggruppare e per filtrare il
 * sotto-albero del Responsabile) e il tecnico assegnato.
 */
final class RigaAvviso
{
    /**
     * @param  string|null  $dettaglio  testo mostrabile della riga d'origine.
     *                                  **Null per entrambe le garanzie**: vedi
     *                                  `daGaranziaRicambio()`.
     * @param  int|null  $tecnicoId  assegnatario, solo per gli interventi: è il
     *                               canale con cui il Tecnico entra fra i
     *                               destinatari (ADR-007/030).
     */
    private function __construct(
        public readonly TipoMotivoSemaforo $tipo,
        public readonly TransizioneAvviso $transizione,
        public readonly CarbonInterface $scadenza,
        public readonly int $riferimentoId,
        public readonly int $strumentoId,
        public readonly string $strumentoNome,
        public readonly ?int $unitaId,
        public readonly ?string $dettaglio,
        public readonly ?int $tecnicoId,
    ) {}

    public static function daIntervento(
        Intervento $intervento,
        string $strumentoNome,
        ?int $unitaId,
    ): self {
        return new self(
            tipo: TipoMotivoSemaforo::Intervento,
            transizione: $intervento->isScaduto() ? TransizioneAvviso::Scaduta : TransizioneAvviso::Imminente,
            scadenza: $intervento->data_scadenza,
            riferimentoId: $intervento->id,
            strumentoId: $intervento->strumento_id,
            strumentoNome: $strumentoNome,
            unitaId: $unitaId,
            dettaglio: $intervento->descrizione,
            tecnicoId: $intervento->tecnico_id,
        );
    }

    public static function daGaranziaMacchina(
        Garanzia $garanzia,
        int $strumentoId,
        string $strumentoNome,
        ?int $unitaId,
    ): self {
        return new self(
            tipo: TipoMotivoSemaforo::GaranziaMacchina,
            transizione: $garanzia->isScaduta() ? TransizioneAvviso::Scaduta : TransizioneAvviso::Imminente,
            scadenza: $garanzia->data_scadenza_effettiva,
            riferimentoId: $garanzia->id,
            strumentoId: $strumentoId,
            strumentoNome: $strumentoNome,
            unitaId: $unitaId,
            dettaglio: null,
            tecnicoId: null,
        );
    }

    /**
     * Garanzia di un pezzo montato (ADR-020).
     *
     * `dettaglio` è null e resta null, per la ragione di
     * `MotivoSemaforo::daGaranziaRicambio()` e una in più che vale solo qui: il
     * digest è un documento che esce dall'applicazione e può essere inoltrato a
     * chiunque. Il nome del pezzo non arriva neppure fin qui — la query del
     * comando seleziona le sole colonne id e scadenza — quindi non c'è una
     * guardia da ricordarsi, c'è un dato che non è stato letto.
     */
    public static function daGaranziaRicambio(
        Garanzia $garanzia,
        int $strumentoId,
        string $strumentoNome,
        ?int $unitaId,
    ): self {
        return new self(
            tipo: TipoMotivoSemaforo::GaranziaRicambio,
            transizione: $garanzia->isScaduta() ? TransizioneAvviso::Scaduta : TransizioneAvviso::Imminente,
            scadenza: $garanzia->data_scadenza_effettiva,
            riferimentoId: $garanzia->id,
            strumentoId: $strumentoId,
            strumentoNome: $strumentoNome,
            unitaId: $unitaId,
            dettaglio: null,
            tecnicoId: null,
        );
    }

    /** Forma serializzabile per il payload della notifica in-app. */
    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo->value,
            'transizione' => $this->transizione->value,
            'scadenza' => $this->scadenza->toDateString(),
            'riferimento_id' => $this->riferimentoId,
            'strumento_id' => $this->strumentoId,
            'strumento_nome' => $this->strumentoNome,
            'dettaglio' => $this->dettaglio,
        ];
    }
}

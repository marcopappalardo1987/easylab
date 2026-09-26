<?php

namespace App\Support\Notifiche;

use App\Models\Strumento;
use Carbon\CarbonInterface;

/**
 * Una riga dell'avviso di obsolescenza (🔗 ADR-014, 27 Ago 2026).
 *
 * Gemello di `RigaAvviso` per forma — `readonly`, costruttore privato, una
 * factory che legge dal model — e classe **a parte** per una ragione precisa,
 * non per simmetria: `RigaAvviso` porta un `TipoMotivoSemaforo`, e
 * l'obsolescenza NON è un motivo del semaforo (ADR-024). Riusarla avrebbe
 * costretto ad aggiungere un caso a quell'enum, cioè a far esplodere con
 * `UnhandledMatchError` i due `match` esaustivi che ci insistono sopra
 * (`mail/digest-scadenze.blade.php`, `strumenti/_panoramica.blade.php`) — nel
 * corpo di un'email, dove nessun test di lista se ne accorgerebbe.
 *
 * 🔴 **Non porta `tecnicoId`, e l'assenza del campo È la guardia.** Una macchina
 * vecchia non ha un assegnatario: l'obsolescenza è una valutazione sul parco,
 * che riguarda chi decide gli acquisti (Admin, Tenant) e chi risponde di un
 * reparto (Responsabile), non chi ripara. Senza il campo, il canale «anche al
 * tecnico» non può essere aperto per distrazione da chi passerà di qui: andrebbe
 * prima aggiunto un dato, che è un gesto che si nota in review.
 *
 * Non porta neppure una `transizione`: qui ce n'è **una sola**, e un campo che
 * può assumere un valore solo è un invito a farne assumere un secondo.
 */
final readonly class RigaObsolescenza
{
    /**
     * @param  int|null  $unitaId  il nodo su cui la macchina è collocata: serve
     *                             a filtrare il sotto-albero del Responsabile
     *                             (`DestinatariEnte`), esattamente come in
     *                             `RigaAvviso`.
     * @param  CarbonInterface  $dataInstallazione  la data NUDA, non
     *                                              «installazione + soglia»:
     *                                              vedi `AvvisiObsolescenza`.
     */
    private function __construct(
        public int $strumentoId,
        public string $strumentoNome,
        public ?int $unitaId,
        public CarbonInterface $dataInstallazione,
    ) {}

    /**
     * ⚠️ Si costruisce solo da uno `Strumento` già letto con la
     * `data_installazione` valorizzata: `Strumento::scopeObsoleti()` mette un
     * `whereNotNull` proprio lì, quindi una macchina senza data non arriva mai
     * fin qui — e se ci arrivasse sarebbe un errore di chi chiama, non un caso
     * da gestire in silenzio con un null.
     */
    public static function daStrumento(Strumento $strumento): self
    {
        return new self(
            strumentoId: $strumento->id,
            strumentoNome: $strumento->nome,
            unitaId: $strumento->unita_organizzativa_id,
            dataInstallazione: $strumento->data_installazione,
        );
    }

    /**
     * Anni compiuti dalla macchina, per l'email.
     *
     * Calcolato al momento della lettura e mai persistito, come `isObsoleto()`:
     * l'età è una funzione del calendario, e una colonna che la contenesse
     * sarebbe sbagliata il giorno dopo.
     */
    public function eta(): int
    {
        return (int) $this->dataInstallazione->diffInYears(today());
    }

    /** Forma serializzabile per il payload della notifica in-app. */
    public function toArray(): array
    {
        return [
            'strumento_id' => $this->strumentoId,
            'strumento_nome' => $this->strumentoNome,
            'data_installazione' => $this->dataInstallazione->toDateString(),
            'eta' => $this->eta(),
        ];
    }
}

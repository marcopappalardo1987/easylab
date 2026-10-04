<?php

namespace App\Support\Billing;

use App\Models\Piano;
use Laravel\Cashier\Subscription;

/**
 * Cosa un account può comprare **adesso**, e per quale strada (🔗 ADR-045,
 * ADR-032 l'Account intestatario, ADR-013 il lockout).
 *
 * È il **valore** che separa «decidere» da «parlare con Stripe»: lo costruisce
 * `PianiAcquistabili`, da colonne locali e senza rete; lo leggono la pagina
 * `/abbonamento`, che mostra, e `SceltaPianoAbbonamento`, che rifà la domanda
 * sul POST. Le due superfici guardano lo stesso oggetto, quindi non possono
 * offrire una cosa e accettarne un'altra.
 *
 * ## I due modi, che non sceglie il cliente
 *
 * - **attivazione** — nessun abbonamento in corso: si paga sul Checkout
 *   ospitato di Stripe, e il piano arriva col webhook, come ogni altro incasso;
 * - **cambio** — abbonamento in corso e sano: si cambia il prezzo della
 *   subscription che c'è. Aprire un checkout qui ne creerebbe una **seconda**,
 *   fatturata accanto alla prima.
 *
 * ## Gli impedimenti, e perché sono tre soli
 *
 * Un impedimento è «oggi da qui non si compra», detto con una frase. Due hanno
 * un rimedio che il cliente può compiere da solo, e lo nominano; il terzo no,
 * ed è generico **di proposito**: copre il blocco disposto a mano e i prezzi
 * fuori listino, e la pagina è raggiungibile in lockout — dire quale dei due
 * sarebbe mostrare al bloccato la sorgente del blocco, che ADR-013 tiene
 * interna.
 */
final readonly class OffertaPiani
{
    /** Nessun abbonamento in corso: si apre un checkout. */
    public const ATTIVAZIONE = 'attivazione';

    /** Abbonamento in corso e sano: si cambia prezzo alla subscription esistente. */
    public const CAMBIO = 'cambio';

    /** Stripe sta ancora inseguendo un incasso: prima si salda, poi si cambia. */
    public const PAGAMENTO_IN_SOSPESO = 'pagamento_in_sospeso';

    /** Cambiare prezzo annullerebbe la disdetta senza che nessuno l'abbia chiesto. */
    public const DISDETTA_PROGRAMMATA = 'disdetta_programmata';

    /** Blocco disposto a mano, piano o prezzo fuori listino: serve una persona. */
    public const NON_DISPONIBILE = 'non_disponibile';

    /**
     * @param  list<Piano>  $piani  nell'ordine del listino
     */
    public function __construct(
        public string $modo,
        public array $piani = [],
        public ?string $impedimento = null,
        public ?Subscription $abbonamento = null,
    ) {}

    /** Niente da offrire, e la ragione. */
    public static function impedita(string $impedimento, ?Subscription $abbonamento = null): self
    {
        return new self(
            modo: $abbonamento === null ? self::ATTIVAZIONE : self::CAMBIO,
            impedimento: $impedimento,
            abbonamento: $abbonamento,
        );
    }

    /**
     * ⛔ La domanda che il POST rifà, e l'unica che conta: un codice che non
     * sta in **questa** lista non si compra, qualunque cosa dica il form. È
     * qui che «non si torna a un piano che costa meno» smette di essere un
     * bottone nascosto e diventa una regola.
     */
    public function accetta(mixed $codice): bool
    {
        return is_string($codice) && $this->piano($codice) !== null;
    }

    public function piano(string $codice): ?Piano
    {
        foreach ($this->piani as $piano) {
            if ($piano->codice === $codice) {
                return $piano;
            }
        }

        return null;
    }

    public function eUnCambio(): bool
    {
        return $this->modo === self::CAMBIO;
    }

    /**
     * La frase per il cliente, la stessa sulla pagina e nel rifiuto del POST.
     *
     * ⛔ Nessuna cita il motivo di un blocco: vedi il docblock di classe.
     */
    public function messaggioImpedimento(): ?string
    {
        return match ($this->impedimento) {
            null => null,
            self::PAGAMENTO_IN_SOSPESO => 'C\'è un pagamento in sospeso: regolarizzalo dal portale di fatturazione, poi potrai cambiare piano.',
            self::DISDETTA_PROGRAMMATA => 'Hai una disdetta programmata: per cambiare piano annullala prima dal portale di fatturazione.',
            default => 'Da questa pagina non è possibile attivare o cambiare piano in questo momento. Contatta EasyLab.',
        };
    }
}

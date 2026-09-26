<?php

namespace App\Support\Piattaforma;

/**
 * Cosa si porta via l'eliminazione di un cliente, contato **prima** di farlo
 * (🔗 ADR-040).
 *
 * Un dato e non un array: la modale ne legge sei campi e il testo cambia a
 * seconda di quali sono a zero, e una chiave scritta male in un Blade è un
 * numero che non compare invece di un errore.
 *
 * ⚠️ I conteggi finiscono **nel registro di audit** dopo l'eliminazione, ed è
 * l'unica cosa che resta a rispondere «cosa c'era»: le righe non ci sono più,
 * e nessun backup le riporta indietro.
 */
final readonly class ConseguenzeEliminazione
{
    public function __construct(
        public int $sedi,
        public int $strumenti,
        public int $interventi,
        public int $documenti,
        public int $membri,
        /**
         * Le persone che restano **senza nessun altro contratto**, e che quindi
         * vengono eliminate insieme al cliente.
         *
         * ⚠️ Sottoinsieme di `$membri`, non un suo sinonimo: chi appartiene
         * anche ad altri account sopravvive, e mostrare i due numeri distinti è
         * ciò che permette a chi conferma di accorgersene.
         */
        public int $personeSenzaAltriContratti,
        /** L'abbonamento attivo che verrà chiuso su Stripe, se ce n'è uno. */
        public bool $abbonamentoAttivo,
    ) {}

    /** Un cliente senza niente: la modale non deve allarmare per uno storico che non esiste. */
    public function nienteDaPerdere(): bool
    {
        return $this->strumenti === 0
            && $this->interventi === 0
            && $this->documenti === 0
            && ! $this->abbonamentoAttivo;
    }

    /** @return array<string, int|bool> Per il registro di audit. */
    public function perAudit(): array
    {
        return [
            'sedi' => $this->sedi,
            'strumenti' => $this->strumenti,
            'interventi' => $this->interventi,
            'documenti' => $this->documenti,
            'membri' => $this->membri,
            'persone_eliminate' => $this->personeSenzaAltriContratti,
            'abbonamento_attivo' => $this->abbonamentoAttivo,
        ];
    }
}

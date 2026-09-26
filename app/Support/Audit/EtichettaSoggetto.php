<?php

namespace App\Support\Audit;

/**
 * Come si chiama, in pagina, il soggetto di una riga di audit.
 *
 * Value object e non stringa perché i casi da distinguere sono **quattro**, e
 * fonderli renderebbe la colonna muta proprio dove serve di più: c'è differenza
 * fra «questa riga non ha un soggetto» (login, 2FA), «il soggetto c'è ed è
 * questo», «c'era ed è stato cestinato» e «c'era e non esiste più».
 */
final class EtichettaSoggetto
{
    private function __construct(
        public readonly ?string $sostantivo,
        public readonly ?string $nome,
        public readonly bool $cestinato = false,
        public readonly bool $mancante = false,
    ) {}

    /** Nessun soggetto: la riga descrive un atto che non ricade su una riga. */
    public static function assente(): self
    {
        return new self(null, null);
    }

    /**
     * Il soggetto c'era ma la riga non c'è più — hard delete, oppure un
     * `Ricambio` confluito in un altro (ADR-008).
     *
     * Distinto da `assente()`: là non c'era niente, qui c'era qualcosa e si è
     * perso. Una cella vuota li confonderebbe.
     */
    public static function mancante(string $sostantivo, int|string|null $id = null): self
    {
        // ⚠️ L'id **può essere nullo**: `nullableMorphs()` rende le due colonne
        // indipendenti, quindi lo schema permette una riga con `subject_type`
        // valorizzato e `subject_id` a NULL. Senza questo, una riga malformata
        // faceva cadere l'intera pagina con un TypeError — la stessa forma del
        // difetto che l'eager load filtrato evita, ma senza nemmeno servire una
        // classe cancellata.
        return new self($sostantivo, $id === null ? null : '#'.$id, mancante: true);
    }

    public static function di(string $sostantivo, string $nome, bool $cestinato = false): self
    {
        return new self($sostantivo, $nome, cestinato: $cestinato);
    }

    /** «Strumento · Autoclave Rossi», o solo «Strumento» se un nome non c'è. */
    public function testo(): string
    {
        if ($this->sostantivo === null) {
            return '';
        }

        return $this->nome === null
            ? $this->sostantivo
            : $this->sostantivo.' · '.$this->nome;
    }
}

<?php

namespace App\Support\Gdpr;

/**
 * Ciò che resta di un export GDPR riuscito: dove sta lo zip, la sua impronta e
 * i conteggi del manifest. È anche il contenuto della riga di audit.
 * 🔗 `EsportazioneTenant`, ADR-013.
 */
final readonly class RisultatoEsportazione
{
    /**
     * @param  array<string, int>  $righe  righe per file CSV
     * @param  list<int>  $documentiMancanti  id dei documenti il cui file non si è potuto leggere
     */
    public function __construct(
        public string $percorso,
        public string $sha256,
        public int $byte,
        public array $righe,
        public int $documenti,
        public array $documentiMancanti,
    ) {}
}

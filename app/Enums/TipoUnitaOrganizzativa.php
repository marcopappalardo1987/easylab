<?php

namespace App\Enums;

/**
 * Tipo di nodo nell'albero organizzativo (ERD §4.1). Profondità libera:
 * il valore è indicativo del livello, non vincola la struttura dell'albero.
 */
enum TipoUnitaOrganizzativa: string
{
    case Ente = 'ente';
    case Dipartimento = 'dipartimento';
    case Sottolaboratorio = 'sottolaboratorio';

    /**
     * Il nome della sezione che mostra la struttura di una sede: la voce del
     * menù, il titolo della pagina, le briciole (🔗 ADR-053).
     *
     * Fino al 10 Ott 2026 si chiamava «Anagrafica».
     */
    public const SEZIONE = 'Laboratori';

    /**
     * Come si chiama un nodo di questo tipo, per chi legge (🔗 ADR-053).
     *
     * 🔴 **Il livello sotto la sede si chiama «laboratorio», non più
     * «dipartimento»** (decisione di Marco del 10 Ott 2026). `dipartimento`
     * resta il **valore** della colonna `tipo` e il nome del case: è un
     * identificatore, come `free` per il piano, e rinominarlo vorrebbe dire
     * migrare ogni nodo di ogni cliente per una parola che nessuno vede.
     *
     * Le viste non scrivono più queste parole per conto loro: le leggono qui.
     */
    public function etichetta(): string
    {
        return match ($this) {
            self::Ente => 'Ente',
            self::Dipartimento => 'Laboratorio',
            self::Sottolaboratorio => 'Sotto-laboratorio',
        };
    }

    public function plurale(): string
    {
        return match ($this) {
            self::Ente => 'Enti',
            self::Dipartimento => 'Laboratori',
            self::Sottolaboratorio => 'Sotto-laboratori',
        };
    }
}

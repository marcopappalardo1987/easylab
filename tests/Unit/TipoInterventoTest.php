<?php

use App\Enums\TipoIntervento;

/**
 * Guardia sull'elenco chiuso di ADR-021. L'enum non è un dettaglio interno:
 * è la nomenclatura che il cliente ha dettato, e una voce aggiunta o tolta
 * in silenzio cambia il contratto senza che nessuno se ne accorga.
 */
it('exposes exactly the five tipologie agreed with the client', function () {
    expect(array_map(fn (TipoIntervento $t) => $t->value, TipoIntervento::cases()))
        ->toBe([
            'manutenzione_ordinaria',
            'manutenzione_straordinaria',
            'manutenzione_full_risk',
            'taratura_e_certificazione',
            'altro',
        ]);
});

/**
 * `taratura` e `certificazione` separate sono l'errore di lettura dell'elenco
 * del cliente, non una versione precedente legittima: "taratura e
 * certificazione" era una voce unica fin dal briefing.
 */
it('has no trace of the tipologie removed or split by mistake', function () {
    foreach (['manutenzione', 'ispezione', 'riparazione', 'taratura', 'certificazione'] as $rimosso) {
        expect(TipoIntervento::tryFrom($rimosso))->toBeNull();
    }
});

/**
 * Il motivo per cui `label()` esiste: con i valori composti il vecchio
 * `ucfirst($value)` produrrebbe "Manutenzione_full_risk" in tabella, nel
 * select e nella colonna "Prossima scadenza" dell'elenco.
 */
it('renders a human label that is never the raw value', function () {
    foreach (TipoIntervento::cases() as $tipo) {
        expect($tipo->label())
            ->not->toContain('_')
            ->and($tipo->label())->not->toBe($tipo->value);
    }

    expect(TipoIntervento::ManutenzioneFullRisk->label())->toBe('Manutenzione full risk');
});

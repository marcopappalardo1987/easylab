<?php

use App\Models\Ricambio;
use App\Models\UnitaOrganizzativa;

/**
 * Normalizzazione del nome (ERD §7.1 — ADR-022): è la chiave del collega-o-crea
 * e dell'indice unique, quindi ogni sua regola è congelata qui.
 */
beforeEach(function () {
    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
});

it('collapses whitespace, trims and case-folds the normalised name', function () {
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create([
        'nome' => "  Guarnizione   O-RING\tGrande  ",
    ]);

    expect($ricambio->nome_normalizzato)->toBe('guarnizione o-ring grande');
});

it('collapses the non-breaking space that copy-paste brings along', function () {
    // U+00A0: `\s` non lo intercetta nemmeno con /u, `\p{Z}` sì. Due nomi
    // identici a occhio ma diversi in byte creerebbero due voci di catalogo.
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create([
        'nome' => "Filtro\u{00A0}HEPA",
    ]);

    expect($ricambio->nome_normalizzato)->toBe('filtro hepa');
});

it('preserves accents, on purpose', function () {
    // Regola conservativa e reversibile: aggiungere l'accent-folding domani
    // FONDE righe oggi distinte (e il merge doppioni è già previsto da ADR-008);
    // toglierlo richiederebbe di ri-separarle, cioè informazione perduta.
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Pistone PERÒ']);

    expect($ricambio->nome_normalizzato)->toBe('pistone però');
});

it('keeps the capitalisation the operator typed in nome', function () {
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => '  Valvola   EV-12  ']);

    // `nome` è ripulito ma non abbassato: è l'etichetta che l'utente rivedrà.
    expect($ricambio->nome)->toBe('Valvola EV-12')
        ->and($ricambio->nome_normalizzato)->toBe('valvola ev-12');
});

it('recomputes the normalised name on every save', function () {
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create(['nome' => 'Cinghia']);

    $ricambio->nome = 'Cinghia Dentata';
    $ricambio->save();

    expect($ricambio->fresh()->nome_normalizzato)->toBe('cinghia dentata');
});

it('ignores a forged nome_normalizzato in the payload', function () {
    // Fuori da $fillable E ricalcolata: due livelli, come per
    // Garanzia::data_scadenza_effettiva.
    $ricambio = Ricambio::create([
        'tenant_id' => $this->ente->id,
        'nome' => 'Sensore PT100',
        'nome_normalizzato' => 'qualcosa-di-inventato',
    ]);

    expect($ricambio->nome_normalizzato)->toBe('sensore pt100');
});

it('rejects a name that is empty or only whitespace', function () {
    expect(fn () => Ricambio::factory()->forTenant($this->ente)->create(['nome' => "   \u{00A0} "]))
        ->toThrow(InvalidArgumentException::class);
});

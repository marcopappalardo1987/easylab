<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Isolamento\Support\MondoDueEnti;

/**
 * Isolamento multi-tenant sulle superfici di ELENCO, RICERCA e CONTATORI
 * (ADR-001/018): ogni pagina del tenant, servita per intero all'Admin
 * dell'Ente A, non contiene nessun marcatore dell'Ente B.
 *
 * Il positivo (i marcatori di A ci sono) serve a dimostrare che la pagina ha
 * davvero reso i dati: una pagina vuota passerebbe il negativo per niente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();
});

/**
 * @return array<string, array{0: string, 1: list<string>}> rotta => marcatori di A attesi
 */
function paginePerElenco(): array
{
    return [
        'dashboard' => ['/dashboard', ['Lo stato delle macchine di Ente-SEGRETO-A']],
        'strumenti' => ['/strumenti', ['Strumento-SEGRETO-A']],
        'strumenti ricerca' => ['/strumenti?search=SEGRETO', ['Strumento-SEGRETO-A']],
        'modelli' => ['/strumenti/modelli', ['MOD-SEGRETO-A']],
        'scadenzario' => ['/scadenzario', ['Intervento-SEGRETO-A']],
        'scadenzario ricerca' => ['/scadenzario?search=SEGRETO', ['Intervento-SEGRETO-A']],
        'documenti' => ['/documenti', ['Documento-SEGRETO-A']],
        'documenti ricerca' => ['/documenti?cerca=SEGRETO', ['Documento-SEGRETO-A']],
        'fornitori' => ['/fornitori', ['Fornitore-SEGRETO-A']],
        'ricambi' => ['/ricambi?search=SEGRETO', ['Ricambio-SEGRETO-A']],
        'anagrafica' => ['/anagrafica', ['Reparto-SEGRETO-A']],
        'utenti' => ['/utenti', ['Utente-SEGRETO-A']],
        'campo' => ['/campo', []],
    ];
}

/**
 * Filtri via querystring con un id dell'Ente B: il parametro è scritto dal
 * client, quindi deve cadere nello scope e non allargarlo.
 *
 * @return array<string, array{0: Closure(MondoDueEnti): string}>
 */
function filtriConIdEstraneo(): array
{
    return [
        'strumenti ?enteId=B' => [fn (MondoDueEnti $m) => '/strumenti?enteId='.$m->riga('B', 'ente')->id],
        'strumenti ?ubicazioneId=reparto B' => [fn (MondoDueEnti $m) => '/strumenti?ubicazioneId='.$m->riga('B', 'reparto')->id],
        'documenti ?strumentoId=B' => [fn (MondoDueEnti $m) => '/documenti?strumentoId='.$m->riga('B', 'strumento')->id],
    ];
}

it('never shows data of another Ente on a tenant page', function (string $url, array $attesi) {
    $html = $this->actingAs($this->mondo->riga('A', 'admin'))->get($url)->assertOk()->getContent();

    foreach ($attesi as $marcatore) {
        expect($html)->toContain($marcatore);
    }
    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
})->with(paginePerElenco());

/** @return list<string> */
function marcatoriFuoriReparto(): array
{
    return ['RepartoNonAssegnato-A', 'StrumentoNonAssegnato-A', 'MAT-NONASSEGNATO-A', 'InterventoNonAssegnato-A', 'DocumentoNonAssegnato-A', 'MOD-NONASSEGNATO-A'];
}

it('never shows nodes outside the assigned subtree to a Responsabile, nor another Ente', function (string $url, array $attesi) {
    $risposta = $this->actingAs($this->mondo->riga('A', 'responsabile'))->get($url);

    if ($risposta->status() === 403) {
        // La pagina non gli compete: nessun dato servito, cella soddisfatta.
        expect($risposta->getContent())->not->toContain('SEGRETO-B');

        return;
    }

    $html = $risposta->assertOk()->getContent();

    // Positivo: ciò che sta nel reparto assegnato si vede (il Responsabile è
    // assegnato proprio al reparto dei marcatori SEGRETO-A). La frase della
    // dashboard nomina l'Ente, che il Responsabile oggi non legge (D-T2-4 in
    // board): lì il positivo è il contatore di DettaglioIsolationTest.
    foreach (str_starts_with($url, '/dashboard') ? [] : $attesi as $marcatore) {
        expect($html)->toContain($marcatore);
    }
    foreach ([...marcatoriFuoriReparto(), ...MondoDueEnti::marcatori('B')] as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
})->with(paginePerElenco());

it('shows the Tecnico their own interventi on the campo page, and nothing of another Ente', function () {
    // `campo` per l'Admin è vuota (nessun intervento assegnato a lui): il
    // positivo si prova col Tecnico, a cui l'intervento SEGRETO-A è assegnato.
    $html = $this->actingAs($this->mondo->riga('A', 'tecnico'))->get('/campo')->assertOk()->getContent();

    expect($html)->toContain('Intervento-SEGRETO-A');
    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
});

it('never widens the scope through a filter carrying an id of another Ente', function (Closure $url) {
    $html = $this->actingAs($this->mondo->riga('A', 'admin'))->get($url($this->mondo))->assertOk()->getContent();

    foreach (MondoDueEnti::marcatori('B') as $marcatore) {
        expect($html)->not->toContain($marcatore);
    }
})->with(filtriConIdEstraneo());

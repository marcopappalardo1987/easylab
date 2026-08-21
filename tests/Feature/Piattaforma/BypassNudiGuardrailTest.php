<?php

use Symfony\Component\Finder\Finder;

/**
 * Meta-test: nessun bypass **nudo** nuovo (ADR-018, Policy di Code Review).
 *
 * `withoutGlobalScopes()` senza argomenti non toglie «gli scope di tenancy»:
 * toglie **tutti** gli scope globali, `SoftDeletingScope` compreso. Il progetto
 * l'ha già pagato una volta — in `Account::enti()` faceva contare le sedi
 * cestinate, che occupavano uno slot del piano per sempre — e la correzione fu
 * elencare i due scope per nome.
 *
 * ⚠️ **Perché questo guardrail è ristretto alla forma nuda, e non conta tutti i
 * bypass.** La prima idea era un'allowlist di ogni `withoutGlobalScope(s)` del
 * progetto: sarebbe stata inutile o rossa il primo giorno, perché ce ne sono già
 * una ventina e sarebbero finiti tutti «graziati» in blocco, catturando solo il
 * ventunesimo. Questo test guarda invece la cosa che è **davvero** andata
 * storta, e la difende su tutto il codice invece che su un elenco.
 *
 * I file elencati qui sotto sono i bypass nudi **preesistenti**, ciascuno con la
 * sua ragione già scritta nel proprio docblock (rottura di ricorsione fra
 * scope, risalite gerarchiche, risoluzione di un id prima di una guardia). Non
 * sono un condono: sono il confine da cui si misura ciò che si aggiunge. Chi ne
 * scrive uno nuovo passa di qui, e la scelta fra «nominare gli scope» e
 * «dichiarare perché il nudo è giusto» diventa esplicita invece che implicita.
 */
const BYPASS_NUDI_ATTESI = [
    // Rompono la ricorsione di uno scope su sé stesso: il nudo è la forma
    // giusta, perché va tolto TUTTO per non rientrare nel ciclo.
    'app/Models/Scopes/GaranziaDepartmentScope.php' => 1,
    'app/Support/Tenancy/AccessibleStrumenti.php' => 1,
    'app/Support/Tenancy/AccessibleNodes.php' => 1,
    'app/Support/Tenancy/AccessoTecnico.php' => 1,

    // Risalite gerarchiche e letture che il confine ce l'hanno nella chiave.
    'app/Models/User.php' => 2,
    'app/Models/Strumento.php' => 2,
    'app/Models/Garanzia.php' => 1,
    'app/Models/Ricambio.php' => 1,

    // Risolvono un id PRIMA della guardia che lo autorizza.
    'app/Livewire/Tenancy/SwitcherEnte.php' => 1,
    'app/Http/Controllers/FugaDaLockout.php' => 1,
    'app/Livewire/Strumenti/ElencoStrumenti.php' => 1,
];

it('never grows a new bare withoutGlobalScopes()', function () {
    $trovati = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        // I commenti si tolgono prima di contare: questi docblock spiegano per
        // esteso perché il nudo è pericoloso, e un guardrail che legge il testo
        // invece del codice punisce chi documenta. (Stessa forma del meta-test
        // sull'audit, che aveva già incontrato il problema.)
        // Si toglie anche `T_WHITESPACE`, e non è pedanteria: senza,
        // `withoutGlobalScopes( )` o una parentesi mandata a capo dal formatter
        // **sfuggono al conteggio** — verificato. Un guardrail aggirabile da un
        // ritorno a capo è peggio di nessun guardrail, perché sembra coprire.
        $codice = collect(token_get_all($file->getContents()))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)
            ->implode('');

        $n = substr_count($codice, 'withoutGlobalScopes()');

        if ($n > 0) {
            $trovati[str_replace(base_path().'/', '', $file->getRealPath())] = $n;
        }
    }

    ksort($trovati);
    $attesi = BYPASS_NUDI_ATTESI;
    ksort($attesi);

    expect($trovati)->toBe(
        $attesi,
        "Bypass nudo non dichiarato.\n\n".
        "`withoutGlobalScopes()` senza argomenti toglie anche il soft delete: in una vista aggregata\n".
        "significa contare righe cestinate, ed è già costato uno slot di piano occupato per sempre.\n\n".
        "Se ti servono i soli scope di tenancy, elencali per nome:\n".
        "    ->withoutGlobalScopes([TenantScope::class, DepartmentScope::class])\n\n".
        "Se il nudo è davvero la forma giusta (rottura di ricorsione fra scope), scrivi il perché nel\n".
        'docblock, aggiungi il file a BYPASS_NUDI_ATTESI e dagli il suo test negativo di non-trapelamento.'
    );
});

it('keeps the platform door naming its scopes', function () {
    // La porta di S6 non compare nell'elenco sopra, e non è un caso: toglie
    // TenantScope e DepartmentScope per nome, così i cestinati restano fuori
    // dai KPI. Questo test lo congela dal lato opposto — se qualcuno
    // «semplificasse» la porta col nudo, il guardrail sopra diventa rosso e
    // questo spiega perché.
    // I commenti si tolgono anche qui: il docblock della porta cita la forma
    // nuda proprio per spiegare perché non si usa, e un test che leggesse il
    // testo grezzo punirebbe quella spiegazione. (Se ne è accorto lui stesso,
    // diventando rosso alla prima esecuzione.)
    $codice = collect(token_get_all(file_get_contents(app_path('Support/Tenancy/VistaPiattaforma.php'))))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    // L'invariante vero è **l'assenza della forma nuda**; gli scope si asseriscono
    // singolarmente e non come stringa esatta dell'array, o il giorno in cui ne
    // arriva un terzo — scenario che il docblock della porta prevede — questo
    // test diventerebbe rosso parlando della cosa sbagliata.
    expect($codice)->not->toContain('withoutGlobalScopes()')
        ->and($codice)->toContain('TenantScope::class')
        ->and($codice)->toContain('DepartmentScope::class');
});

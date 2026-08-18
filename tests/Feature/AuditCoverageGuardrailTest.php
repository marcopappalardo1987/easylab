<?php

use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Guardrail della copertura audit (🔗 ADR-027), sul modello di
 * `TenantScopeGuardrailTest`: una rete che rende RUMOROSA la prossima omissione
 * invece di lasciarla scoprire in S6 davanti a una vista che non mostra nulla.
 *
 * ADR-027 dice che «chiudere a metà una casella che sembra chiusa è peggio che
 * lasciarla aperta». Chiuderla senza elencare le esenzioni ricrea lo stesso
 * problema un livello più su: la lista qui sotto **è** la dichiarazione onesta
 * della copertura, e chi aggiungerà `Fornitore` o `Documento` dovrà scegliere
 * invece di dimenticare.
 */
/** Modelli di business che NON usano il trait, ciascuno col proprio perché. */
const ESENZIONI = [
    Strumento::class => 'logga a mano: i suoi gesti (forzaSemaforo/rimuoviForzatura) hanno un messaggio che vale più dell\'elenco dei campi, e le colonne forced_* sono fuori da $fillable (ADR-005/027).',
    UnitaOrganizzativa::class => 'logga a mano la sola scrittura che conta — la visibilità garanzie ricambio (ADR-029). Il resto dell\'anagrafica non è tracciato: ADR-027 §3, «il resto quando serve».',
    User::class => 'identità e sessioni, già coperte da AuditLogSubscriber su un altro canale (login, 2FA, impersonation).',
];

/** Il sostantivo atteso nella descrizione, per ogni modello che usa il trait. */
const SOSTANTIVI = [
    // Aggiunto il 15 Ago 2026 col blocco Fornitore, e non a mano: è stato il
    // meta-test a fermare la suite appena il model è nato. È il lavoro per cui
    // esiste — la scelta «trait o esenzione» si presenta invece di poter essere
    // dimenticata.
    // Aggiunto il 15 Ago 2026 col blocco Documenti — di nuovo su richiesta del
    // meta-test, che ha fermato la suite appena il model è nato. Due blocchi
    // consecutivi, due volte la scelta presentata invece che dimenticata.
    Documento::class => 'Creazione documento',
    Fornitore::class => 'Creazione fornitore',
    Garanzia::class => 'Creazione garanzia',
    Ricambio::class => 'Creazione ricambio',
    RicambioUtilizzo::class => 'Creazione ricambio montato',
    Intervento::class => 'Creazione intervento',
    SpostamentoStrumento::class => 'Creazione spostamento',
];

/** @return list<class-string> i modelli di business in app/Models (non i concerns, non gli scope) */
function modelliDiBusiness(): array
{
    return collect(glob(app_path('Models/*.php')))
        ->map(fn (string $f) => 'App\\Models\\'.basename($f, '.php'))
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, Model::class))
        ->values()->all();
}

it('covers every business model, or declares why not', function () {
    $scoperti = collect(modelliDiBusiness())
        ->reject(fn (string $c) => in_array(AuditsDomainWrites::class, class_uses_recursive($c), true))
        ->reject(fn (string $c) => array_key_exists($c, ESENZIONI))
        ->values()->all();

    expect($scoperti)->toBe([], 'Modello senza traccia e senza esenzione dichiarata: aggiungi il trait, o dichiara il perché in ESENZIONI.');
});

it('never lets a model use both the trait and explicit activity() calls', function () {
    // La regola «o l'uno o le altre» (ADR-027) resa meccanica.
    //
    // ⚠️ I COMMENTI VANNO TOLTI PRIMA DI CERCARE, e non è un dettaglio: al
    // primo giro questo test ha bocciato `Intervento`, il cui docblock spiega
    // *perché* non usa `activity()`. Un guardrail che legge il testo invece del
    // codice punisce chi documenta — cioè esattamente il contrario di ciò che
    // questo progetto chiede.
    $codiceSenzaCommenti = function (string $classe): string {
        $sorgente = file_get_contents(app_path('Models/'.class_basename($classe).'.php'));

        return collect(token_get_all($sorgente))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)
            ->implode('');
    };

    // ⚠️ **Un'eccezione dichiarata, e che deve guadagnarsi il posto.**
    // `Ricambio::unisciIn()` (ADR-008) scrive una riga esplicita perché
    // l'informazione «questa voce è confluita in quest'altra, con N montaggi»
    // NON è una colonna: in tabella resta solo un `deleted_at`, indistinguibile
    // da una cancellazione qualunque. Il gesto resta però UNO SOLO, perché il
    // metodo chiama `disableLogging()` prima di cestinare la sorgente — ed è
    // esattamente ciò che questa regola vuole impedire, cioè due righe per un
    // gesto. Che sia davvero una sola lo verifica `UnioneDoppioniTest`; togliere
    // quel `disableLogging()` non fa cadere QUESTO test, fa cadere quello.
    $ammessi = [Ricambio::class];

    $doppi = collect(modelliDiBusiness())
        ->filter(fn (string $c) => in_array(AuditsDomainWrites::class, class_uses_recursive($c), true))
        ->reject(fn (string $c) => in_array($c, $ammessi, true))
        ->filter(fn (string $c) => str_contains($codiceSenzaCommenti($c), 'activity('))
        ->values()->all();

    expect($doppi)->toBe([], 'Un modello col trait non deve chiamare activity(): sarebbero due righe per un gesto solo.');
});

it('keeps the domain nouns readable', function () {
    // «Creazione ricambioutilizzo» è passato per una settimana perché il test
    // che c'era guardava il prefisso e non il sostantivo. Qui la mappa è
    // esplicita: cambiarne uno obbliga a cambiarlo anche qui, cioè a saperlo.
    foreach (SOSTANTIVI as $classe => $atteso) {
        // `descriptionForEvent` è una Closure pubblica su LogOptions, non un
        // metodo: si invoca, non si chiama.
        $descrizione = (new ReflectionClass($classe))->newInstanceWithoutConstructor()
            ->getActivitylogOptions()->descriptionForEvent;

        expect($descrizione('created'))->toBe($atteso, "Sostantivo inatteso per {$classe}");
    }
});

it('lists every model that uses the trait, so the coverage is a fact and not a claim', function () {
    $conTrait = collect(modelliDiBusiness())
        ->filter(fn (string $c) => in_array(AuditsDomainWrites::class, class_uses_recursive($c), true))
        ->sort()->values()->all();

    expect($conTrait)->toEqualCanonicalizing(array_keys(SOSTANTIVI));
});

<?php

use Symfony\Component\Finder\Finder;

/**
 * 🔴 Meta-test: **il Parco clienti legge da una porta sola** (🔗 ADR-037).
 *
 * `App\Support\Piattaforma\ParcoClienti` è la sola classe autorizzata a togliere
 * gli scope di tenancy per questa funzione, ed è l'unico posto in cui il
 * perimetro scelto dal browser viene rivalidato contro l'insieme legittimo. Una
 * porta che si può aggirare non è una porta: se un componente si scrivesse da sé
 * `Strumento::withoutGlobalScopes([TenantScope::class])`, o chiamasse
 * `VistaPiattaforma::strumenti()` a mano, otterrebbe **le righe di tutti i
 * clienti ignorando il filtro** — e il guasto non sarebbe un errore, sarebbe una
 * tabella plausibile che mostra più di quanto è stato chiesto.
 *
 * ⚠️ È la stessa forma di difetto che questo progetto ha già pagato con l'export
 * che ignorava i filtri e portava via l'intero elenco invece della pagina che
 * l'utente stava guardando. Lì costava un CSV di troppo; qui costerebbe le righe
 * di un cliente dentro la vista di un altro.
 *
 * ## I tre invarianti, e perché sono tre e non uno
 *
 * 1. **Nessuna mano libera sugli scope** in tutto lo strato UI di piattaforma:
 *    chi ha bisogno di una lettura non-scopata la scrive in una porta
 *    (`VistaPiattaforma` per la cabina, `ParcoClienti` per il parco), col suo
 *    gate e il suo test negativo.
 * 2. **Il parco non legge dalla porta della cabina.** I builder di
 *    `VistaPiattaforma` sono non filtrati per cliente di proposito — servono a
 *    contare, non a elencare — quindi chiamarli da una schermata del parco
 *    significa **saltare il perimetro**, cioè mostrare «tutti» mentre il filtro
 *    dice altro. `VistaPiattaforma::PERMESSO` resta lecito: è una costante, non
 *    una query, ed è ciò che i componenti dichiarano già.
 * 3. **Nessuna scrittura concatenata alla porta del parco.** ADR-037 sposta il
 *    confine dalla lettura alla **scrittura**: i builder consegnati sono
 *    scrivibili, `Builder::update()` non emette eventi e gli hook di
 *    `BelongsToTenant` non girano. Un update di massa toccherebbe ogni cliente
 *    dietro un permesso che si chiama `.view_all`, e senza la riga di audit
 *    dell'impersonazione che è la contropartita dell'intera decisione.
 *
 * ⚠️ **Limite dichiarato**, lo stesso dei guardrail gemelli: l'analisi è
 * **per-file** e testuale. Un metodo che restituisse il builder e venisse
 * chiamato da un'**altra** classe non verrebbe seguito. Si coprono le forme che
 * il progetto usa davvero — diretta, per variabile, per metodo che restituisce —
 * e si scrive quale resta fuori, invece di lasciar credere che sia tutto.
 *
 * 🔗 `BypassNudiGuardrailTest` (la forma nuda), `VistaPiattaformaTest` (le
 * scritture sulla porta della cabina), `ScrittureErroriGuardrailTest` (la forma
 * dell'allowlist onesta).
 */

// ─── Invariante 1: nessuna mano libera sugli scope nello strato UI ───────────

it('never lets the platform UI strip global scopes by hand', function () {
    $colpevoli = collect(sorgentiDiPiattaforma())
        ->filter(fn (string $codice) => str_contains($codice, 'withoutGlobalScope'))
        ->keys()
        ->values();

    expect($colpevoli->all())->toBe(
        [],
        "Scope globali tolti a mano dentro lo strato UI di piattaforma.\n\n".
        "Una lettura cross-tenant non si scrive in un componente: si scrive in una PORTA, che porta con sé\n".
        "il gate su `tenants.view_all`, il filtro del perimetro e i propri test negativi.\n\n".
        "  - per contare i clienti (cabina):  App\\Support\\Tenancy\\VistaPiattaforma\n".
        "  - per elencarne le righe (parco):  App\\Support\\Piattaforma\\ParcoClienti\n\n".
        "Se ti serve un modello che nessuna delle due copre, aggiungi il METODO alla porta giusta —\n".
        "togliendo gli scope per NOME, mai `withoutGlobalScopes()` nudo — e dagli il suo negativo.\n\n".
        'Trovato in: '.$colpevoli->implode(', ')
    );
});

// ─── Invariante 2: il parco passa dalla propria porta ────────────────────────

it('keeps the parco reading through its own door, never straight from the platform view', function () {
    $colpevoli = collect(sorgentiDelParco())
        ->filter(fn (string $codice) => preg_match('/VistaPiattaforma::\w+\(/', $codice) === 1)
        ->keys()
        ->values();

    expect($colpevoli->all())->toBe(
        [],
        "Il Parco clienti legge dalla porta della CABINA, saltando il perimetro.\n\n".
        "I builder di `VistaPiattaforma` non sono filtrati per cliente: servono a contare tutti i clienti,\n".
        "non a elencare le righe di quelli scelti. Chiamarli da una schermata del parco significa mostrare\n".
        "«tutti» mentre il filtro in cima alla pagina dice altro — che è l'export-che-ignora-i-filtri, di nuovo.\n\n".
        "Si legge da `App\\Support\\Piattaforma\\ParcoClienti`, passandogli il `Perimetro`.\n".
        "(`VistaPiattaforma::PERMESSO` resta lecito: è una costante, non una query.)\n\n".
        'Trovato in: '.$colpevoli->implode(', ')
    );
});

// ─── Invariante 3: dalla porta del parco si LEGGE ────────────────────────────

it('never lets a write be chained onto the parco door', function () {
    $colpevoli = collect(sorgentiApplicative())
        ->filter(fn (string $codice) => scrittureSullaPortaDelParco($codice) !== [])
        ->keys()
        ->values();

    expect($colpevoli->all())->toBe(
        [],
        "Scrittura concatenata alla porta del Parco clienti.\n\n".
        "ADR-037 concede la LETTURA cross-cliente e lascia la scrittura all'impersonazione, che è per\n".
        "cliente e lascia una riga di audit con dentro chi agiva e per conto di chi. Un `update()`/`delete()`\n".
        "sul builder della porta passa dal query builder, non emette eventi e non fa girare gli hook di\n".
        "`BelongsToTenant`: riscriverebbe righe di OGNI cliente, senza quella riga di audit.\n\n".
        "Si legge da qui e si SCRIVE impersonando. Se un giorno servisse davvero una scrittura di\n".
        "piattaforma, è qui che va discussa l'eccezione — non in un componente.\n\n".
        'Trovato in: '.$colpevoli->implode(', ')
    );
});

// ─── I guardrail dei guardrail ───────────────────────────────────────────────

it('keeps the scan honest about the files it actually reads', function () {
    // ⚠️ Il compagno obbligatorio dei tre test qui sopra: uno scanner che non
    // legge niente è verde per sempre. Su elenchi paralleli questo progetto ha
    // già perso due volte (`letture_contaore.*`, `fornitori.view`), e la
    // disciplina adottata là — «una voce in allowlist deve continuare a parlare
    // di qualcosa di vero» — qui si applica al rovescio: le cartelle scandite
    // devono continuare a contenere i file per cui il guardrail esiste.
    $piattaforma = array_keys(sorgentiDiPiattaforma());
    $parco = array_keys(sorgentiDelParco());

    // ⚠️ Anche lo strato di QUERY, o gli invarianti tornerebbero ciechi
    // proprio sui file che decidono l'isolamento — che è il buco del 29 Ago.
    expect($piattaforma)->toContain('app/Support/Piattaforma/Perimetro.php')
        // …e la porta resta l'unica esente, per nome.
        ->and($piattaforma)->not->toContain('app/Support/Piattaforma/ParcoClienti.php')
        ->and($piattaforma)->toContain('app/Livewire/Piattaforma/ParcoGlobale.php')
        ->and($parco)->toContain('app/Livewire/Piattaforma/ParcoGlobale.php')
        ->and(count($parco))->toBeGreaterThan(1)
        ->and(array_keys(sorgentiApplicative()))->toContain('app/Support/Piattaforma/ParcoClienti.php');
});

it('catches the bypass in every form it can take', function (string $php, bool $atteso) {
    // 🔴 **Il guardrail del guardrail.** La forma diretta è la sola che si
    // scriverebbe per distrazione; le altre sono quelle che si scrivono
    // *scrivendo bene*, cioè estraendo — ed è già successo in questo progetto:
    // una cancellazione di massa scritta come `$this->filtrata()->delete()`
    // lasciava la suite tutta verde, sulla tabella append-only per definizione.
    //
    // ⚠️ E il ROVESCIO conta quanto il dritto: un guardrail che grida su ogni
    // riga onesta viene disattivato entro la settimana. `->firstOrFail()`
    // materializza un model, e da lì `$riga->update([...])` tocca UNA riga
    // emettendo i propri eventi — gli hook di `BelongsToTenant` girano, ed è
    // corretto.
    expect(scrittureSullaPortaDelParco($php) !== [])->toBe($atteso);
})->with([
    'diretta' => ['<?php ParcoClienti::ricambi($p)->update(["x" => 1]);', true],
    'per variabile' => ['<?php class A { function f() { $q = ParcoClienti::strumenti($p); $q->where("a", 1)->delete(); } }', true],
    'per metodo che restituisce' => ['<?php class A {
        private function filtrata(): Builder { return ParcoClienti::interventi($this->perimetro()); }
        public function purga(): void { $this->filtrata()->forceDelete(); }
    }', true],
    'mandata a capo dal formatter' => ['<?php ParcoClienti::garanzie($p)
        ->where("a", 1)
        ->update(["x" => 1]);', true],
    'sola lettura' => ['<?php class A { function f() { $q = ParcoClienti::strumenti($p); return $q->where("a", 1)->paginate(); } }', false],
    'un model materializzato' => ['<?php class A { function f() { $r = ParcoClienti::ricambi($p)->firstOrFail(); $r->update(["x" => 1]); } }', false],
    'un file che non nomina la porta' => ['<?php Strumento::query()->update(["x" => 1]);', false],
]);

it('does not mistake the permission constant for a read from the platform view', function () {
    // Il rovescio dell'invariante 2, e non è teorico: i tre componenti
    // segnaposto del parco dichiarano `public const PERMESSO =
    // VistaPiattaforma::PERMESSO;`. Un guardrail che cercasse la sola stringa
    // `VistaPiattaforma::` sarebbe rosso il giorno in cui è stato scritto — e
    // verrebbe allentato invece che capito.
    $costante = '<?php class A { const PERMESSO = VistaPiattaforma::PERMESSO; }';
    $lettura = '<?php class A { function f() { return VistaPiattaforma::strumenti(); } }';

    expect(preg_match('/VistaPiattaforma::\w+\(/', $costante))->toBe(0)
        ->and(preg_match('/VistaPiattaforma::\w+\(/', $lettura))->toBe(1);
});

it('keeps the door itself naming the scopes it takes off', function () {
    // Il lato opposto di `BypassNudiGuardrailTest`: se qualcuno «semplificasse»
    // la porta col nudo, quel guardrail diventa rosso e questo spiega perché.
    // I commenti si tolgono perché il docblock della porta cita la forma nuda
    // proprio per dire che non si usa: un test che leggesse il testo grezzo
    // punirebbe la spiegazione.
    $codice = codiceRipulito(file_get_contents(app_path('Support/Piattaforma/ParcoClienti.php')));

    expect($codice)->not->toContain('withoutGlobalScopes()')
        ->and($codice)->toContain('TenantScope::class')
        ->and($codice)->toContain('DepartmentThroughStrumentoScope::class')
        ->and($codice)->toContain('GaranziaDepartmentScope::class');
});

// ─── Gli scanner ─────────────────────────────────────────────────────────────

/**
 * Lo strato UI di piattaforma: componenti Livewire e viste.
 *
 * ⚠️ La porta **non** è qui dentro: vive in `app/Support/Piattaforma`, che
 * questo scanner non guarda. È voluto — il posto in cui gli scope si tolgono
 * legittimamente è quello, e includerlo renderebbe l'invariante 1 rosso il
 * giorno in cui è stato scritto.
 *
 * @return array<string, string> percorso relativo → codice senza commenti
 */
function sorgentiDiPiattaforma(): array
{
    // 🔴 **`app/Support/Piattaforma` va scandita, ed era il buco.**
    //
    // La prima stesura guardava il solo strato UI, con una ragione scritta nel
    // docblock: «la porta non è qui dentro, includerla renderebbe l'invariante
    // 1 rosso il giorno in cui è stato scritto». Vera per la porta — falsa per
    // tutto il resto della cartella. Lì vive lo **strato di query delle tre
    // schede**, cioè esattamente i file che decidono l'isolamento, e i due
    // invarianti erano ciechi proprio su quelli.
    //
    // ⚠️ E `BypassNudiGuardrailTest` non chiudeva il buco: conta la forma NUDA
    // `withoutGlobalScopes()`, mentre lì dentro una rimozione **per nome**
    // sarebbe stata invisibile a entrambe le reti. Segnalato dal correttore
    // dello scadenzario, che il file non poteva toccarlo perché condiviso con
    // gli altri due agenti.
    //
    // Si esenta la SOLA porta, per nome: è l'unico file a cui togliere gli
    // scope è il mestiere.
    $sorgenti = codiceDelle([
        app_path('Livewire/Piattaforma'),
        app_path('Support/Piattaforma'),
        resource_path('views/livewire/piattaforma'),
        resource_path('views/components/parco'),
    ]);

    unset($sorgenti['app/Support/Piattaforma/ParcoClienti.php']);

    return $sorgenti;
}

/**
 * I soli file del **Parco clienti**, riconosciuti dal nome.
 *
 * ⚠️ **Limite dichiarato**: la convenzione è il nome. Un file che servisse il
 * parco chiamandosi in un altro modo sfuggirebbe all'invariante 2 — resterebbe
 * comunque coperto dall'invariante 1, che guarda tutta la cartella. Si è scelto
 * il nome perché è ciò che la funzione usa davvero (tre componenti `Parco*`,
 * tre viste `parco-*`, i partial in `components/parco`) e perché una lista
 * chiusa di percorsi sarebbe già scaduta al primo file nuovo.
 *
 * @return array<string, string>
 */
function sorgentiDelParco(): array
{
    return array_filter(
        sorgentiDiPiattaforma(),
        fn (string $_, string $percorso) => str_contains(strtolower($percorso), 'parco'),
        ARRAY_FILTER_USE_BOTH
    );
}

/**
 * Tutto il codice applicativo: `app/` e le viste.
 *
 * La porta del parco può essere chiamata da qualunque punto dell'applicazione,
 * non solo dalle sue schermate — l'invariante 3 va quindi cercato ovunque.
 *
 * ⛔ `tests/` resta fuori di proposito: le fixture contengono codice scritto
 * apposta sbagliato, comprese le forme di questo stesso file.
 *
 * @return array<string, string>
 */
function sorgentiApplicative(): array
{
    return codiceDelle([app_path(), resource_path('views')]);
}

/**
 * Legge le cartelle date e restituisce il codice **senza commenti**.
 *
 * I commenti si tolgono prima di cercare: questi docblock spiegano per esteso
 * perché una forma è pericolosa, e un guardrail che legge il testo invece del
 * codice punisce chi documenta. (È la lezione del meta-test sull'audit, e
 * `BypassNudiGuardrailTest` l'ha rifatta.)
 *
 * @param  list<string>  $cartelle
 * @return array<string, string>
 */
function codiceDelle(array $cartelle): array
{
    $esistenti = array_values(array_filter($cartelle, 'is_dir'));

    $sorgenti = [];

    foreach (Finder::create()->files()->in($esistenti)->name(['*.php', '*.blade.php']) as $file) {
        $relativo = str_replace(base_path().'/', '', $file->getRealPath());
        $sorgenti[$relativo] = codiceRipulito($file->getContents());
    }

    ksort($sorgenti);

    return $sorgenti;
}

/**
 * Il codice di un file, senza commenti e senza spazi.
 *
 * ⚠️ **Le viste si trattano col testo grezzo**, e non è pigrizia:
 * `token_get_all` su un `.blade.php` vede il PHP dentro `@php` come inline HTML,
 * quindi tokenizzare darebbe una **falsa pulizia** — cioè un guardrail che
 * sembra coprire le viste e non le copre. Si tolgono i soli commenti Blade, che
 * sono l'unica forma di commento che una vista può davvero contenere.
 *
 * ⚠️ Si toglie anche lo **spazio**: senza, una catena mandata a capo dal
 * formatter — cioè come Pint la scriverebbe — sfuggirebbe a ogni ricerca. Un
 * guardrail aggirabile da un ritorno a capo è peggio di nessun guardrail, perché
 * **sembra** coprire.
 */
function codiceRipulito(string $contenuto): string
{
    if (str_contains($contenuto, '{{--')) {
        $contenuto = preg_replace('/\{\{--.*?--\}\}/s', '', $contenuto);
    }

    if (! str_contains($contenuto, '<?php')) {
        return preg_replace('/\s+/', '', $contenuto);
    }

    return collect(token_get_all($contenuto))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');
}

/**
 * I metodi di scrittura concatenati a un builder che viene da `ParcoClienti` —
 * direttamente, attraverso una variabile o attraverso un metodo che lo
 * restituisce.
 *
 * ⚠️ **Perché non riusa `metodiConcatenatiAllaPorta()`** di
 * `VistaPiattaformaTest`: quell'analizzatore è legato per nome all'altra porta —
 * esce subito se il file non contiene la stringa `VistaPiattaforma` e costruisce
 * le sorgenti su quel prefisso. Generalizzarlo significherebbe toccare il
 * guardrail che protegge la decisione centrale del progetto per far posto a una
 * decisione nuova, e il prezzo del riuso sarebbe pagato dal file sbagliato.
 *
 * ⚠️ La distinzione fra **builder** e **model materializzato** è ciò che tiene
 * il guardrail utilizzabile: `ParcoClienti::ricambi($p)->firstOrFail()` è un
 * model, e `$riga->update([...])` tocca una riga emettendo i propri eventi.
 * Contarla come violazione farebbe gridare il guardrail su ogni riga onesta.
 *
 * @param  string  $php  sorgente grezzo, o già ripulito: viene ripulito qui
 * @return list<string> i verbi di scrittura trovati, vuoto se il file è pulito
 */
function scrittureSullaPortaDelParco(string $php): array
{
    if (! str_contains($php, 'ParcoClienti')) {
        return [];
    }

    $token = codiceRipulito($php);
    $scritture = ['update', 'delete', 'forceDelete', 'increment', 'decrement', 'truncate', 'upsert', 'insert'];
    $materializzano = 'find|findOrFail|findOr|first|firstOr|firstOrFail|firstWhere|sole|get|pluck|count|sum|avg|max|min|exists|doesntExist|paginate|simplePaginate|cursorPaginate|value|cursor|toArray';

    $sorgenti = ['ParcoClienti::\w+\('];

    preg_match_all('/(\$\w+)=ParcoClienti::\w+\(([^;]*)/', $token, $variabili, PREG_SET_ORDER);

    foreach ($variabili as [$_, $variabile, $coda]) {
        if (preg_match('/->('.$materializzano.')\(/', $coda) === 1) {
            continue;
        }

        $sorgenti[] = preg_quote($variabile, '/');
    }

    foreach (metodiCheRestituisconoIlParco($token) as $metodo) {
        $sorgenti[] = '\$this->'.preg_quote($metodo, '/').'\(\)';
    }

    $trovate = [];

    foreach (array_unique($sorgenti) as $sorgente) {
        foreach ($scritture as $scrittura) {
            if (preg_match('/'.$sorgente.'[^;]*->'.$scrittura.'\(/', $token) === 1) {
                $trovate[] = $scrittura;
            }
        }
    }

    return array_values(array_unique($trovate));
}

/**
 * I metodi del file il cui `return` è la porta del parco.
 *
 * Le graffe si **contano** invece di fermarsi alla prima chiusa: il corpo di un
 * metodo ne contiene altre (`if`, `foreach`, closure), e un `[^}]*` si
 * fermerebbe alla prima, tagliando via proprio il `return` finale.
 *
 * @return list<string>
 */
function metodiCheRestituisconoIlParco(string $token): array
{
    preg_match_all('/function(\w+)\(/', $token, $trovati, PREG_OFFSET_CAPTURE);

    $metodi = [];

    foreach ($trovati[1] as [$nome, $posizione]) {
        $apertura = strpos($token, '{', $posizione);

        if ($apertura === false) {
            continue;
        }

        $profondita = 0;
        $corpo = '';

        for ($i = $apertura, $n = strlen($token); $i < $n; $i++) {
            $corpo .= $token[$i];
            $profondita += match ($token[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($profondita === 0) {
                break;
            }
        }

        if (preg_match('/return(ParcoClienti::\w+\(|\$\w+;)/', $corpo) === 1 && str_contains($corpo, 'ParcoClienti::')) {
            $metodi[] = $nome;
        }
    }

    return array_values(array_unique($metodi));
}

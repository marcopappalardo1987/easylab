<?php

use Illuminate\Support\Facades\File;

/**
 * 🔴 Meta-test: **nessuno scrive `errori` e `occorrenze_errore` fuori dai due
 * file sanzionati** (S6 — error tracker interno).
 *
 * ## Perché è l'unica rete di questa feature, e non una ridondanza
 *
 * `errori` e `occorrenze_errore` sono tabelle **globali**, senza `tenant_id`:
 * un'eccezione PHP non appartiene a un Ente, nasce anche in console e in coda. Ne
 * segue che l'error tracker **non passa da `VistaPiattaforma`** — non c'è alcuno
 * scope da togliere, e un `VistaPiattaforma::errori()` sarebbe il «bypass finto»
 * che il docblock della porta rifiuta per nome. Il prezzo di quella scelta è che
 * queste due tabelle **non ereditano** il guardrail «nessuna scrittura
 * concatenata alla porta»: la loro rete è questo file, e non ce n'è una seconda.
 * È la stessa forma, e la stessa ragione, di `ScrittureRbacGuardrailTest`.
 *
 * ## Cosa si difende
 *
 * **Una** delle due scritture legittime sta dentro il gestore delle eccezioni
 * (`CatturaErrori`), dove valgono regole che nessun altro punto del progetto ha:
 * non lanciare **mai**, non chiamare `report()` (ricorsione), costare due query,
 * tenere la transazione **attorno alla query** e non attorno alla cattura (su
 * Postgres una query fallita aborta l'intera transazione, 25P02). L'altra sta sui
 * tre gesti del model, che devono lasciare una riga nel registro di audit e usare
 * `forceFill()` perché `$fillable` scarterebbe `stato` in silenzio.
 *
 * Una terza scrittura scritta altrove non erediterebbe **nessuna** di quelle
 * regole. In concreto: cambierebbe lo stato di una issue **senza** la riga di
 * audit — cioè la sola cosa che tiene sotto controllo l'unico ruolo che questa
 * pagina può aprire — oppure creerebbe una issue senza impronta, o riaprirebbe un
 * `ignorato`, che è l'unico interruttore di silenzio del tracker.
 *
 * ## Le tre forme, i due versi, e il rovescio
 *
 * Si cercano la forma **diretta**, quella **per variabile** e quella **per metodo
 * che restituisce** — le ultime due sono ciò che si scrive *scrivendo bene*, cioè
 * estraendo — più la forma che qui è la più realistica di tutte: il **parametro
 * tipizzato** (`function chiudi(Errore $errore)`), che nessuna analisi delle sole
 * assegnazioni vedrebbe.
 *
 * ⚠️ E la relazione **in entrambi i versi**, che è la lezione già pagata sul
 * pivot RBAC: là il guardrail guardava il solo `Role::permissions()` e
 * `Permission::roles()` gli sfuggiva. Qui `Errore::occorrenzeErrore()` e
 * `OccorrenzaErrore::errore()` sono i due lati della stessa FK, e un
 * `$occorrenza->errore()->update([...])` fa esattamente la scrittura vietata.
 *
 * ⚠️ **Limite dichiarato**, lo stesso del guardrail RBAC: l'analisi è
 * **per-file**. Un metodo che restituisse il builder e venisse chiamato da
 * un'altra classe non verrebbe seguito, e le migration non si guardano affatto —
 * sono il posto in cui quelle tabelle si creano. Si coprono le forme che il
 * progetto usa davvero e si scrive quale resta fuori, invece di lasciar credere
 * che sia tutto.
 *
 * 🔗 `App\Support\Errori\CatturaErrori`, `App\Models\Errore`,
 * `ScrittureRbacGuardrailTest` (la forma), `VistaPiattaformaTest` (la variabile e
 * il metodo).
 */

/**
 * I due soli file che possono scrivere, e **il gesto che lo prova**.
 *
 * ⚠️ La seconda metà non è decorazione: è la disciplina del guardrail di
 * `sbloccaPerStripe()` e della mappa dei soggetti di vendor — una voce in
 * allowlist deve continuare a parlare di qualcosa di vero, o resta «un permesso
 * aperto su qualcosa che non esiste più». Il giorno in cui `CatturaErrori`
 * smettesse di scrivere, questa riga lo direbbe invece di lasciare un'esenzione
 * muta.
 *
 * ⚠️ Il token di `Errore.php` è `forceFill(` e **non** una scrittura statica: il
 * model scrive **sé stesso** (`$this->forceFill(...)->save()`), che è la sola
 * forma che l'analizzatore qui sotto non può riconoscere da fuori — dentro il
 * model `$this` *è* la tabella, e non c'è modo di distinguerlo senza sapere in
 * che file si sta guardando. Va detto, invece di lasciar credere che il rilevatore
 * copra anche quello.
 *
 * @var array<string, string>
 */
const SCRITTURE_ERRORI_CONSENTITE = [
    'app/Models/Errore.php' => '$this->forceFill(array_merge($altre',
    'app/Support/Errori/CatturaErrori.php' => 'OccorrenzaErrore::create(',
];

it('never writes the tracker tables outside the two sanctioned files', function () {
    $colpevoli = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getRealPath()))
        ->reject(fn (string $relativo) => array_key_exists($relativo, SCRITTURE_ERRORI_CONSENTITE))
        ->filter(fn (string $relativo) => scrittureSugliErrori(file_get_contents(base_path($relativo))) !== [])
        ->values();

    // Le viste si guardano col **testo grezzo**: `token_get_all` su un
    // `.blade.php` vede il PHP dentro `@php` come inline HTML, quindi
    // tokenizzare darebbe una falsa pulizia. Si cercano lì le sole forme che una
    // vista potrebbe davvero contenere.
    $viste = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => scritturaSugliErroriInUnaVista(file_get_contents($f->getPathname())))
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getRealPath()))
        ->values();

    expect($colpevoli->merge($viste)->all())->toBe(
        [],
        "Scrittura su `errori` / `occorrenze_errore` fuori dai due file sanzionati.\n\n".
        "Quelle due tabelle non passano da `VistaPiattaforma` (sono globali, non c'è scope da togliere),\n".
        "quindi non ereditano nessun guardrail: una scrittura scritta altrove non porta con sé né la riga\n".
        "di audit dei tre gesti, né le regole del gestore delle eccezioni (non lanciare mai, non chiamare\n".
        "report(), due query, transazione attorno alla query).\n\n".
        "Si scrive da `App\\Models\\Errore` (i gesti umani) o da `App\\Support\\Errori\\CatturaErrori`\n".
        "(la cattura). Da fuori si LEGGE.\n\n".
        'Trovato in: '.$colpevoli->merge($viste)->implode(', ')
    );
});

it('keeps the allowlist honest about what actually writes', function () {
    // Il compagno obbligatorio del precedente: un'allowlist è un secondo elenco
    // parallelo, e su elenchi paralleli questo progetto ha già perso due volte
    // (`letture_contaore.*`, `fornitori.view`).
    foreach (SCRITTURE_ERRORI_CONSENTITE as $file => $token) {
        expect(file_exists(base_path($file)))
            ->toBeTrue("Il file in allowlist non esiste più: {$file}")
            ->and(str_contains(file_get_contents(base_path($file)), $token))
            ->toBeTrue("«{$token}» non c'è più in {$file}: quel file scrive ancora sull'error tracker?");
    }
});

it('catches the write in every form it can take', function (string $php) {
    // 🔴 **Il guardrail del guardrail.** La forma diretta è la sola che si
    // scriverebbe per distrazione; le altre sono quelle che si scrivono
    // *scrivendo bene* — ed è già successo in questo progetto: una cancellazione
    // di massa scritta come `$this->filtrata()->delete()` lasciava la suite tutta
    // verde, sulla tabella append-only per definizione (`VistaPiattaformaTest`).
    //
    // ⚠️ Si tolgono anche gli **spazi** prima di confrontare: senza, una catena
    // mandata a capo dal formatter — cioè come Pint la scriverebbe — sfuggirebbe.
    // Un guardrail aggirabile da un ritorno a capo è peggio di nessun guardrail,
    // perché **sembra** coprire.
    //
    // ⚠️ I casi hanno un **nome** perché il rosso dica *quale forma* è sfuggita:
    // con una catena di dodici `expect` il messaggio sarebbe «Expecting [] not to
    // be empty», che non nomina niente e costringe a bisecare a mano.
    expect(scrittureSugliErrori($php))->not->toBeEmpty();
})->with([
    'diretta' => '<?php class A { function f() { Errore::query()->where("stato", "aperto")->update(["stato" => "risolto"]); } }',
    'spezzata dal formatter' => "<?php class A { function f() { Errore::query()\n            ->where('stato', 'aperto')\n            ->delete(); } }",
    'statica, senza freccia' => '<?php class A { function f() { Errore::destroy([1, 2]); } }',
    'per variabile' => '<?php class A { function f() { $q = Errore::query(); $q->update(["stato" => "ignorato"]); } }',
    'per metodo che restituisce' => '<?php class A {
        private function chiuse() { return Errore::query()->where("stato", "risolto"); }
        public function svuota(): void { $this->chiuse()->delete(); }
    }',
    'per parametro tipizzato' => '<?php class A { function chiudi(Errore $errore): void { $errore->forceFill(["stato" => "risolto"])->save(); } }',
    'per proprieta tipizzata' => '<?php class A { public Errore $errore; function chiudi(): void { $this->errore->forceFill(["stato" => "risolto"])->save(); } }',
    'per relazione' => '<?php class A { function f() { $errore->occorrenzeErrore()->delete(); } }',
    'per relazione inversa' => '<?php class A { function f() { $occorrenza->errore()->update(["stato" => "ignorato"]); } }',
    'per relazione inversa, via variabile' => '<?php class A { function f() { $q = $occorrenza->errore(); $q->increment("occorrenze"); } }',
    // ⚠️ Le due forme che **sfuggivano**, trovate con file veri in `app/`.
    'per istanza appena costruita' => '<?php class A { function f() { $e = new Errore; $e->forceFill(["stato" => "ignorato"])->save(); } }',
    'per relazione inversa come proprieta' => '<?php class A { function f() { $occorrenza->errore->forceFill(["stato" => "ignorato"])->save(); } }',
    'con la tabella nominata' => '<?php class A { function f() { DB::table("occorrenze_errore")->insert(["errore_id" => 1]); } }',
    'con SQL grezzo' => '<?php class A { function f() { DB::statement("delete from errori where stato = 1"); } }',
]);

it('lets an honest read of the tracker be written like any other', function (string $php) {
    // ⚠️ Il rovescio, e conta quanto l'altro: un guardrail che grida su ogni riga
    // onesta viene disattivato entro la settimana. L'elenco e la scheda
    // **leggono** queste due tabelle a ogni render, e devono poterlo fare senza
    // cerimonie.
    expect(scrittureSugliErrori($php))->toBeEmpty();
})->with([
    'una lettura vera' => '<?php class A { function f() { return Errore::query()->where("impronta", $i)->first(); } }',
    'un conteggio' => '<?php class A { function f() { $n = Errore::query()->count(); return $n; } }',
    'un elenco paginato' => '<?php class A { function f() { return $this->errore->occorrenzeErrore()->orderByDesc("id")->paginate(10); } }',
    // La relazione inversa vale come sorgente **solo** se ci si scrive: chi la
    // interroga per risalire alla issue non sta violando niente.
    'una lettura dalla relazione inversa' => '<?php class A { function f() { return $occorrenza->errore()->first(); } }',
    // Una variabile che ha già materializzato in qualcosa che **non è un model**:
    // da lì in poi non è più la tabella, è una collection di stringhe.
    'una collection gia materializzata' => '<?php class A { function f() { $classi = Errore::query()->pluck("classe"); $classi->push("x"); } }',
    // E il commento che spiega il divieto non è una violazione del divieto: un
    // guardrail che legge il testo invece del codice punisce chi documenta.
    'un commento che spiega il divieto' => '<?php /** Mai `Errore::query()->delete()`, e mai DB::table("errori"). */ class A {}',
    // ⚠️ **I quattro falsi positivi che il progetto contiene davvero.** «errori»
    // è una parola comune: sta nella chiave di config (`easylab.errori`), nel nome
    // di rotta, nel nome della vista e in una colonna dell'import strumenti. Un
    // guardrail che cercasse la stringa nuda griderebbe su tutti e quattro — e
    // verrebbe spento entro la settimana.
    'la chiave di config' => '<?php class A { function f() { return config("easylab.errori.contesti_per_errore"); } }',
    'il nome di rotta' => '<?php class A { function f() { return route("piattaforma.errori"); } }',
    'il nome della vista' => '<?php class A { function f() { return view("livewire.piattaforma.errori", ["errori" => $errori]); } }',
    "la chiave di array dell'import" => '<?php class A { function f() { return collect($this->righe)->filter(fn ($r) => $r["errori"] === []); } }',
]);

it('never mistakes a similarly named class for the tracker', function () {
    // ⚠️ `SchedaErrore` e `CatturaErrori` **finiscono per «Errore»/«Errori»**, e
    // `OccorrenzaErrore` **contiene** `Errore`: senza un confine di parola
    // l'analizzatore prenderebbe `SchedaErrore::class` per il model e griderebbe
    // sul componente che si limita a mostrarlo. È il difetto che rende un
    // guardrail inutilizzabile — grida dove non deve, e lo si spegne.
    $omonima = '<?php class A { function f() { SchedaErrore::query()->delete(); } }';
    $variabileOmonima = '<?php class A { function f(SchedaErrore $errore) { $errore->delete(); } }';

    expect(scrittureSugliErrori($omonima))->toBeEmpty()
        ->and(scrittureSugliErrori($variabileOmonima))->toBeEmpty()
        // …ma il nome pienamente qualificato del model vero sì, perché lì il
        // carattere che precede è una barra rovescia, non una lettera.
        ->and(scrittureSugliErrori('<?php class A { function f() { \App\Models\Errore::query()->delete(); } }'))
        ->not->toBeEmpty();
});

// ─── Il rilevatore ───────────────────────────────────────────────────────────

/** I metodi che scrivono davvero sul database. */
const SCRITTURE_DI_ELOQUENT = [
    'create', 'createMany', 'forceCreate', 'firstOrCreate', 'updateOrCreate', 'createOrFirst',
    'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert',
    'update', 'updateOrFail', 'updateQuietly', 'increment', 'decrement', 'incrementEach', 'decrementEach',
    'save', 'saveMany', 'saveQuietly', 'push', 'touch',
    'delete', 'deleteQuietly', 'forceDelete', 'destroy', 'truncate', 'restore',
];

/**
 * I due model, come alternativa di regex, **con un confine di parola davanti**.
 *
 * ⚠️ Il `(?<![A-Za-z0-9_])` è ciò che distingue `Errore` da `SchedaErrore` e da
 * `CatturaErrori`. La barra rovescia è **esclusa** dal divieto di proposito: un
 * `\App\Models\Errore::` pienamente qualificato è il model vero, e deve passare
 * per la strada del divieto e non per quella dell'esenzione.
 */
const MODELLI_DEL_TRACKER = '(?<![A-Za-z0-9_])(?:OccorrenzaErrore|Errore)';

/**
 * Le scritture che in questo sorgente raggiungono `errori` o `occorrenze_errore`.
 *
 * Due passaggi, perché i due modi di arrivarci non si assomigliano.
 *
 * **(a) La tabella nominata.** Il nome vive dentro una **stringa**, quindi si
 * guardano le stringhe letterali — ma non nude: «errori» è una parola comune, e
 * il progetto la usa già come chiave di config (`easylab.errori`), nome di rotta
 * (`piattaforma.errori`), nome di vista e chiave di array nell'import strumenti.
 * Si cerca quindi la stringa **nel posto in cui vale una tabella**: dentro un
 * `table()`, o dopo una parola chiave SQL.
 *
 * **(b) Il model e le sue relazioni.** Concatenati a un metodo di scrittura,
 * direttamente, attraverso una variabile, attraverso un metodo che li
 * restituisce, o attraverso un **parametro/proprietà tipizzati** — che è la forma
 * più realistica e quella che l'analisi delle sole assegnazioni non vedrebbe. Qui
 * le stringhe si tolgono davvero: una parentesi dentro una stringa spezzerebbe la
 * scansione della catena fino al `;`.
 *
 * @return list<string> i gesti trovati, vuoto se il file è pulito
 */
function scrittureSugliErrori(string $php): array
{
    $token = token_get_all($php);
    $trovate = [];

    // ⚠️ **Gli spazi si NORMALIZZANO a uno, non si tolgono**, ed è la differenza
    // che questo file ha pagato scrivendosi. Il guardrail RBAC li toglie del
    // tutto e può permetterselo; qui no, perché qui serve un **confine di
    // parola** davanti al nome del model — o `SchedaErrore::` e `CatturaErrori`
    // verrebbero presi per `Errore`. Togliendo gli spazi, `return Errore::query()`
    // diventa `returnErrore::query()`: il confine sparisce, il rilevatore non
    // vede più niente e il guardrail resta verde proprio sulla forma estratta,
    // cioè quella che si scrive *scrivendo bene*. Verificato: tre delle dodici
    // forme sfuggivano.
    //
    // Uno spazio solo, e non gli originali: una catena mandata a capo dal
    // formatter deve restare riconoscibile.
    $ripulito = fn (array $scarta) => collect($token)
        ->reject(fn ($t) => is_array($t) && in_array($t[0], $scarta, true))
        ->map(fn ($t) => is_array($t) && $t[0] === T_WHITESPACE ? ' ' : (is_array($t) ? $t[1] : $t))
        ->implode('');

    // (a) La tabella nominata, sul codice che conserva le stringhe.
    $conStringhe = $ripulito([T_COMMENT, T_DOC_COMMENT]);

    if (preg_match('/(?:DB\s*::|->)\s*table\(\s*[\'"](errori|occorrenze_errore)[\'"]/', $conStringhe) === 1) {
        $trovate[] = 'table()';
    }

    foreach ($token as $t) {
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/\b(from|into|update|join|table)\s+["\'`]?(errori|occorrenze_errore)\b/i', $t[1]) === 1) {
            $trovate[] = 'sql';
            break;
        }
    }

    // (b) Il model, sulla catena ripulita anche dalle stringhe.
    $codice = $ripulito([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING]);

    foreach (SCRITTURE_DI_ELOQUENT as $scrittura) {
        // La forma **statica**, che non passa da una freccia: `Errore::destroy()`.
        if (preg_match('/'.MODELLI_DEL_TRACKER.'\s*::\s*'.$scrittura.'\s*\(/', $codice) === 1) {
            $trovate[] = $scrittura;
        }
    }

    $sorgenti = [
        MODELLI_DEL_TRACKER.'\s*::',
        // ⚠️ **La relazione anche come PROPRIETÀ dinamica**, non solo col paio
        // di parentesi: `$occorrenza->errore->forceFill(...)->save()` è la forma
        // idiomatica, ed è quella che sfuggiva. Il `(?!\s*\()` evita di contare
        // due volte la forma con le parentesi, già coperta sopra.
        '->\s*occorrenzeErrore\s*(?:\(\)|(?!\s*\())',
        '->\s*errore\s*(?:\(\)|(?!\s*\())',
        // ⚠️ **E l'istanza appena costruita.** `new Errore` seguito da
        // `forceFill()->save()` è *il* modo di cambiare stato senza lasciare la
        // riga di audit — cioè esattamente ciò che questo file esiste per
        // impedire — e passava indisturbato: verificato con un file vero in
        // `app/`. Il model che scrive sé stesso resta invisibile da fuori (per
        // questo `Errore.php` è in allowlist per token), ma **chiunque altro**
        // costruisca un'istanza e la salvi va preso.
        'new\s+(?:\\?App\\\\Models\\\\)?(?:Errore|OccorrenzaErrore)\b',
    ];

    foreach (variabiliCheTengonoIlTracker($codice) as $variabile) {
        $sorgenti[] = str_replace('\->', '\s*->\s*', preg_quote($variabile, '/')).'(?![A-Za-z0-9_])';
    }

    foreach (metodiCheRestituisconoIlTracker($codice) as $metodo) {
        $sorgenti[] = '\$this\s*->\s*'.preg_quote($metodo, '/').'\s*\(\)';
    }

    foreach ($sorgenti as $sorgente) {
        foreach (SCRITTURE_DI_ELOQUENT as $scrittura) {
            if (preg_match('/'.$sorgente.'[^;]*->\s*'.$scrittura.'\s*\(/', $codice) === 1) {
                $trovate[] = $scrittura;
            }
        }
    }

    return array_values(array_unique($trovate));
}

/**
 * Le variabili — e le proprietà — che tengono **una riga o un builder** del
 * tracker.
 *
 * Tre sorgenti, e la terza è quella che qui conta di più:
 *
 * 1. l'assegnazione da una query (`$q = Errore::query()`);
 * 2. l'assegnazione dalla relazione (`$q = $occorrenza->errore()`), **nei due
 *    versi**;
 * 3. il **tipo dichiarato** — parametro, parametro promosso o proprietà
 *    (`function chiudi(Errore $errore)`, `public Errore $errore;`). ⚠️ È la forma
 *    più realistica di scrittura fuori posto: un servizio che riceve la issue già
 *    pronta e la salva. Nessuna analisi delle sole assegnazioni la vedrebbe, e
 *    sarebbe la falla nel guardrail dichiarato «l'unica rete di questa feature».
 *
 * ⚠️ **L'esenzione per materializzazione è più stretta che nel guardrail RBAC**,
 * e la differenza è deliberata: là la sorgente è una *relazione*, e appena si
 * legge diventa una collection innocua. Qui la sorgente è un **model**, e un model
 * letto si scrive eccome — `$e = Errore::query()->first(); $e->delete();` è la
 * scrittura vietata. Si esentano quindi solo i finali che producono qualcosa che
 * **non è un model**: un numero, un booleano, un array, una collection di
 * scalari. `get`, `first`, `find`, `sole` e `paginate` restano sorgenti.
 *
 * @return list<string>
 */
function variabiliCheTengonoIlTracker(string $codice): array
{
    $materializzano = 'count|sum|avg|max|min|exists|doesntExist|pluck|value|toArray|toBase|getQuery|toSql';

    $variabili = [];

    preg_match_all(
        // ⚠️ Tre modi di far tenere il tracker a una variabile: la statica, la
        // relazione (**anche come proprietà**, senza parentesi), e — quello che
        // sfuggiva — l'**istanza appena costruita**. `$e = new Errore;` seguito
        // da `forceFill()->save()` è il modo di cambiare stato senza lasciare la
        // riga di audit, e l'assegnazione e la scrittura stanno su due
        // istruzioni diverse: senza marcare la variabile, nessun `[^;]*` le
        // unisce mai.
        '/(\$\w+)\s*=\s*[^;]*?(?:'.MODELLI_DEL_TRACKER.'\s*::|new\s+(?:\\?App\\\\Models\\\\)?(?:Errore|OccorrenzaErrore)\b|->\s*(?:occorrenzeErrore|errore)\s*(?:\(\)|(?!\s*\()))([^;]*)/',
        $codice,
        $trovate,
        PREG_SET_ORDER
    );

    foreach ($trovate as [, $variabile, $coda]) {
        if (preg_match('/->('.$materializzano.')\(/', $coda) === 1) {
            continue;
        }

        $variabili[] = $variabile;
    }

    // I tipi dichiarati: parametri, parametri promossi e proprietà.
    // Il tipo dichiarato, con l'eventuale unione (`Errore|null $e`).
    preg_match_all('/'.MODELLI_DEL_TRACKER.'(?:\|\w+)*\s+(\$\w+)/', $codice, $tipizzate, PREG_SET_ORDER);

    foreach ($tipizzate as [, $variabile]) {
        $variabili[] = $variabile;
    }

    // Una proprietà tipizzata si raggiunge da `$this->nome`, non solo da `$nome`.
    preg_match_all(
        '/(?:public|protected|private|readonly|static)\s+\??'.MODELLI_DEL_TRACKER.'(?:\|\w+)*\s+\$(\w+)\s*;/',
        $codice,
        $proprieta,
        PREG_SET_ORDER
    );

    foreach ($proprieta as [, $nome]) {
        $variabili[] = '$this->'.$nome;
    }

    return array_values(array_unique($variabili));
}

/**
 * I metodi del file il cui `return` è il tracker — direttamente o via una
 * variabile che lo tiene.
 *
 * Le graffe si contano invece di fermarsi alla prima chiusa: il corpo di un
 * metodo ne contiene altre (`if`, `foreach`, closure), e un `[^}]*` taglierebbe
 * via proprio il `return` finale.
 *
 * @return list<string>
 */
function metodiCheRestituisconoIlTracker(string $codice): array
{
    $variabili = variabiliCheTengonoIlTracker($codice);
    $metodi = [];

    preg_match_all('/function\s+(\w+)\s*\(/', $codice, $trovate, PREG_OFFSET_CAPTURE);

    foreach ($trovate[1] as $i => [$nome]) {
        // La prima graffa **o** il primo `;`: una firma senza corpo (interfaccia,
        // metodo astratto) non ha un corpo da leggere, e prendere la graffa del
        // metodo successivo attribuirebbe a lei il corpo di un altro.
        $apertura = null;

        for ($p = $trovate[0][$i][1]; $p < strlen($codice); $p++) {
            if ($codice[$p] === ';') {
                break;
            }

            if ($codice[$p] === '{') {
                $apertura = $p;
                break;
            }
        }

        if ($apertura === null) {
            continue;
        }

        $livello = 0;
        $corpo = null;

        for ($p = $apertura; $p < strlen($codice); $p++) {
            $livello += $codice[$p] === '{' ? 1 : ($codice[$p] === '}' ? -1 : 0);

            if ($livello === 0) {
                $corpo = substr($codice, $apertura, $p - $apertura);
                break;
            }
        }

        if ($corpo === null) {
            continue;
        }

        $restituisce = preg_match('/return\s[^;]*(?:'.MODELLI_DEL_TRACKER.'\s*::|->\s*(?:occorrenzeErrore|errore)\s*\(\))[^;]*;/', $corpo) === 1
            || collect($variabili)->contains(fn (string $v) => preg_match('/return\s+'.preg_quote($v, '/').'\s*;/', $corpo) === 1);

        if ($restituisce) {
            $metodi[] = $nome;
        }
    }

    return array_values(array_unique($metodi));
}

/**
 * Una vista scrive sull'error tracker?
 *
 * Sul **testo grezzo**, perché `token_get_all` su un `.blade.php` vede il PHP
 * dentro `@php` come inline HTML e tokenizzare darebbe una falsa pulizia. Si
 * cercano le sole forme che una vista potrebbe davvero contenere: il model
 * concatenato a una scrittura, la relazione concatenata a una scrittura, la
 * tabella nominata. Il nome nudo no — le viste dell'error tracker parlano di
 * «errori» a ogni riga.
 *
 * ⚠️ **I commenti Blade si tolgono prima di guardare**, ed è una trappola che
 * questo file ha pagato scrivendosi: `scheda-errore.blade.php` spiega dentro un
 * `{{-- --}}` che la colonna `messaggio` «si scrive una volta sola, alla nascita
 * della issue (`Errore::create()` in `CatturaErrori::registra()`)» — e su testo
 * grezzo quella riga è una violazione. È lo stesso difetto già colto in
 * `AuditCoverageGuardrailTest`: **un guardrail che legge il testo invece del
 * codice punisce chi documenta**, cioè il contrario di ciò che questo progetto
 * chiede.
 */
function scritturaSugliErroriInUnaVista(string $testo): bool
{
    $scritture = implode('|', SCRITTURE_DI_ELOQUENT);

    $testo = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $testo);

    return preg_match('/(?:DB::|->)table\(\s*[\'"](errori|occorrenze_errore)[\'"]/', $testo) === 1
        || preg_match('/'.MODELLI_DEL_TRACKER.'\s*::\s*(?:'.$scritture.')\s*\(/', $testo) === 1
        || preg_match('/'.MODELLI_DEL_TRACKER.'\s*::\s*query\(\)[^;]*->\s*(?:'.$scritture.')\s*\(/s', $testo) === 1
        || preg_match('/->\s*(?:occorrenzeErrore|errore)\s*\(\)\s*->\s*(?:'.$scritture.')\s*\(/s', $testo) === 1;
}

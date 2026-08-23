<?php

use Illuminate\Support\Facades\File;

/**
 * 🔴 Meta-test: **nessuno scrive sul pivot dei permessi fuori da `MatriceRuoli`**
 * (S6 — ADR-016).
 *
 * ## Perché è l'unica rete di questa feature, e non una ridondanza
 *
 * L'editor della matrice **non passa da `VistaPiattaforma`**, per una ragione
 * scritta nel suo docblock: `roles` e `permissions` sono tabelle globali, senza
 * tenancy, quindi non c'è alcuno scope da togliere e un `VistaPiattaforma::ruoli()`
 * sarebbe il «bypass finto» che il docblock della porta rifiuta per nome. Il
 * prezzo di quella scelta è che questa pagina **non eredita** il guardrail
 * «nessuna scrittura concatenata alla porta»: la sua rete è questo file, e non
 * ce n'è una seconda.
 *
 * ## Cosa si difende, e perché il pivot nudo è il modo realistico di rompere
 *
 * Letto nel vendor, non supposto: `Role::givePermissionTo()` e
 * `revokePermissionTo()` chiamano `forgetCachedPermissions()` quando
 * `$this instanceof Role` (`HasPermissions.php:416-418, 465-467`), cioè
 * dimenticano la chiave `spatie.permission.cache` su uno store **condiviso fra
 * i processi web**. `$role->permissions()->attach()/detach()/sync()` e qualunque
 * `DB::table('role_has_permissions')` fanno **la stessa scrittura senza
 * l'invalidazione**: la matrice cambia a database e ogni php-fpm continua a
 * servire quella vecchia fino alla scadenza della chiave — ventiquattro ore, per
 * `cache.expiration_time`. Il sintomo sarebbe «il permesso l'ho tolto e quello
 * entra lo stesso», su un'app in cui la matrice governa tutti i clienti insieme.
 *
 * ## L'allowlist è VUOTA, ed è la correzione più importante di questo file
 *
 * Il piano prevedeva «un'allowlist di un file solo — `MatriceRuoli` — verificando
 * che quel file contenga davvero il token», sulla forma del guardrail di
 * `sbloccaPerStripe()`. Verificato scrivendo il test: **`MatriceRuoli` non
 * contiene nessuno dei token proibiti.** Scrive con `givePermissionTo()` /
 * `revokePermissionTo()`, cioè con l'API che invalida la cache, e non tocca mai
 * il pivot direttamente. Una voce in allowlist per lui sarebbe stata esattamente
 * ciò che quel guardrail insegna a non fare: «un permesso rimasto aperto su
 * qualcosa che non esiste più».
 *
 * La disciplina resta, spostata sul suo rovescio: invece di verificare che il
 * file graziato contenga davvero il gesto vietato, si verifica che **il gesto
 * consentito esista, e in un file solo** — `never lets the sanctioned write
 * spread beyond MatriceRuoli`. È la stessa domanda («la voce dell'elenco parla
 * ancora di qualcosa di vero?») posta all'unica metà che qui ha una risposta.
 *
 * 🔗 `App\Support\Rbac\MatriceRuoli`, ADR-016, e per la **forma** del guardrail
 * `VistaPiattaformaTest::follows the platform view through a variable…`, che è
 * il modello più aggiornato del progetto.
 */

/**
 * ⚠️ **Vuota, e va tenuta vuota.** Vedi il docblock: `MatriceRuoli` non è qui
 * dentro perché non ne ha bisogno. Chi aggiunge una voce deve prima rispondere a
 * «e come si invalida la cache dei permessi, allora?» — e la risposta onesta è
 * quasi sempre «usando l'API di spatie», cioè non aggiungendola.
 *
 * @var list<string>
 */
const SCRITTURE_RBAC_CONSENTITE = [];

it('never writes the role-permission pivot outside the Eloquent API', function () {
    $colpevoli = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->reject(fn ($f) => in_array($f->getFilename(), SCRITTURE_RBAC_CONSENTITE, true))
        ->filter(fn ($f) => scrittureSulPivotDeiPermessi(file_get_contents($f->getPathname())) !== [])
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getRealPath()))
        ->values();

    // Le viste si guardano col testo grezzo: `token_get_all` su un `.blade.php`
    // vede il PHP dentro `@php` come inline HTML, quindi tokenizzare darebbe una
    // falsa pulizia. E lì non ci sono docblock che spieghino perché una cosa non
    // si fa, quindi il testo basta.
    $viste = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_contains(file_get_contents($f->getPathname()), 'role_has_permissions')
            || preg_match('/permissions\(\)\s*->\s*(attach|detach|sync)\(/', file_get_contents($f->getPathname())) === 1)
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getRealPath()))
        ->values();

    expect($colpevoli->merge($viste)->all())->toBe(
        [],
        "Scrittura diretta sul pivot dei permessi.\n\n".
        "`\$role->permissions()->attach|detach|sync()` e `DB::table('role_has_permissions')` fanno la\n".
        "scrittura SENZA invalidare `spatie.permission.cache`: la matrice cambia a database e ogni\n".
        "processo web continua a servire quella vecchia fino alla scadenza della chiave.\n\n".
        "Si scrive con l'API Eloquent di spatie, da `App\\Support\\Rbac\\MatriceRuoli`:\n".
        "    \$role->givePermissionTo(\$permission) / \$role->revokePermissionTo(\$permission)\n\n".
        'Trovato in: '.$colpevoli->merge($viste)->implode(', ')
    );
});

it('never lets the sanctioned write spread beyond MatriceRuoli', function () {
    // 🔴 **Il rovescio del guardrail, e la metà che gli dà significato.** Il test
    // qui sopra vieta la scrittura nuda; da solo lascerebbe libera la scrittura
    // *corretta* di moltiplicarsi — e sei posti che scrivono la matrice sono sei
    // posti in cui ricordarsi della riga di audit, della guardia del set bloccato
    // e della riga protetta. `MatriceRuoli` esiste perché siano uno.
    //
    // È anche la forma che il guardrail di `sbloccaPerStripe()` dà all'allowlist
    // — «verifica che il file in allowlist la contenga davvero, così la voce non
    // resta un permesso aperto su qualcosa che non esiste più» — posta all'unica
    // metà che qui ha una risposta, dato che l'allowlist dei divieti è vuota.
    $chiScrive = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->filter(function ($f) {
            // I commenti si tolgono: il docblock di `MatriceRuoli` nomina questi
            // due metodi per spiegare perché sono gli unici ammessi, e altrove
            // potrebbero essere citati per la stessa ragione.
            $codice = codiceRbacSenzaCommenti(file_get_contents($f->getPathname()));

            return preg_match('/->(givePermissionTo|revokePermissionTo|syncPermissions)\(/', $codice) === 1;
        })
        ->map(fn ($f) => str_replace(base_path().'/', '', $f->getRealPath()))
        ->values();

    expect($chiScrive->all())->toBe(['app/Support/Rbac/MatriceRuoli.php']);
});

it('catches the pivot write through a variable and through a method that returns it', function () {
    // 🔴 **Il guardrail del guardrail.** La forma diretta è la sola che si
    // scriverebbe per distrazione; le altre due sono quelle che si scrivono
    // *scrivendo bene*, cioè estraendo una variabile o un metodo — ed è già
    // successo altrove in questo progetto: una cancellazione di massa scritta
    // come `$this->filtrata()->delete()` lasciava la suite tutta verde, sulla
    // tabella append-only per definizione (`VistaPiattaformaTest`).
    //
    // ⚠️ E si tolgono anche gli spazi prima di confrontare: senza,
    // `permissions()\n    ->detach(` — cioè una catena mandata a capo dal
    // formatter, che è come Pint la scriverebbe — sfuggirebbe. Un guardrail
    // aggirabile da un ritorno a capo è peggio di nessun guardrail, perché
    // **sembra** coprire.
    $diretta = '<?php class A { function f() { $r->permissions()->detach($p); } }';
    $spezzata = "<?php class A { function f() { \$r->permissions()\n            ->detach(\$p); } }";
    $perVariabile = '<?php class A { function f() { $q = $r->permissions(); $q->attach($p); } }';
    // ⚠️ **Il verso inverso**: stessa tabella, lato `Permission`. Sfuggiva.
    $inversa = '<?php class A { function f() { $p->roles()->detach($r); } }';
    $inversaPerVariabile = '<?php class A { function f() { $q = $p->roles(); $q->sync([1, 2]); } }';
    $perMetodo = '<?php class A {
        private function pivot() { return $this->ruolo->permissions(); }
        public function svuota(): void { $this->pivot()->sync([]); }
    }';
    $tabellaNuda = '<?php class A { function f() { DB::table("role_has_permissions")->insert(["role_id" => 1]); } }';
    $sqlGrezzo = '<?php class A { function f() { DB::statement("delete from role_has_permissions"); } }';

    expect(scrittureSulPivotDeiPermessi($diretta))->not->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($spezzata))->not->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($perVariabile))->not->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($perMetodo))->not->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($tabellaNuda))->not->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($sqlGrezzo))->not->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($inversa))->not->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($inversaPerVariabile))->not->toBeEmpty();
});

it('lets an honest read of the pivot be written like any other', function () {
    // ⚠️ Il rovescio, e conta quanto l'altro: un guardrail che grida su ogni riga
    // onesta viene disattivato entro la settimana. `MatriceRuoli` **legge** il
    // pivot per invertire la cella — e lo legge di proposito da lì e non da
    // `hasPermissionTo()`, che passa dal registrar, cioè da una cache che in un
    // worker in daemon può essere vecchia di ore.
    $letturaVera = '<?php class A { function f() { return $r->permissions()->whereKey($id)->exists(); } }';
    $elenco = '<?php class A { function f() { return $r->permissions()->pluck("name")->all(); } }';
    // Una variabile che ha già **materializzato**: da lì in poi non è più il
    // pivot, è una collection.
    $materializzata = '<?php class A { function f() { $n = $r->permissions()->get(); $n->delete(); } }';
    // E il commento che spiega il divieto non è una violazione del divieto.
    $soloDocumentato = '<?php /** Mai `permissions()->detach()`, e mai DB::table("role_has_permissions"). */ class A {}';

    expect(scrittureSulPivotDeiPermessi($letturaVera))->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($elenco))->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($materializzata))->toBeEmpty()
        ->and(scrittureSulPivotDeiPermessi($soloDocumentato))->toBeEmpty()
        // La relazione inversa vale come sorgente **solo** se ci si scrive: chi
        // la interroga per sapere quali ruoli tengono un permesso — che è
        // esattamente ciò che fa la striscia degli orfani della griglia — non
        // sta violando niente.
        ->and(scrittureSulPivotDeiPermessi(
            '<?php class A { function f() { return $p->roles()->pluck("name")->all(); } }'
        ))->toBeEmpty();
});

/**
 * Le scritture che in questo sorgente raggiungono il pivot `role_has_permissions`.
 *
 * Due passaggi, perché i due modi di arrivarci non si assomigliano.
 *
 * **(a) La tabella nominata.** `DB::table('role_has_permissions')`, un
 * `DB::statement()` con dentro dell'SQL, un `join`: il nome vive dentro una
 * **stringa**, quindi si guardano le stringhe letterali — e per questo NON si
 * scartano i `T_CONSTANT_ENCAPSED_STRING` come fa il guardrail della porta di
 * piattaforma, che invece li toglie perché a lui darebbero solo rumore.
 *
 * **(b) La relazione.** `->permissions()` concatenata a un metodo di scrittura,
 * direttamente o attraverso una variabile o un metodo che la restituisce. Qui le
 * stringhe si tolgono davvero: una parentesi dentro una stringa spezzerebbe la
 * scansione della catena fino al `;`.
 *
 * ⚠️ **Limite dichiarato, lo stesso della porta di piattaforma**: l'analisi è
 * **per-file**. Un metodo che restituisse il pivot e venisse chiamato da
 * un'altra classe non verrebbe seguito. Chiuderlo vorrebbe dire un'analisi
 * statica vera; qui si coprono le forme che il progetto usa davvero e si scrive
 * quale resta fuori, invece di lasciar credere che sia tutto.
 *
 * @return list<string> i gesti trovati, vuoto se il file è pulito
 */
function scrittureSulPivotDeiPermessi(string $php): array
{
    $token = token_get_all($php);
    $trovate = [];

    // (a) Il nome della tabella dentro una stringa letterale.
    foreach ($token as $t) {
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && str_contains($t[1], 'role_has_permissions')) {
            $trovate[] = 'role_has_permissions';
            break;
        }
    }

    // (b) La relazione, sulla catena ripulita.
    $codice = collect($token)
        ->reject(fn ($t) => is_array($t) && in_array(
            $t[0],
            [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING],
            true
        ))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');

    $scritture = ['attach', 'detach', 'sync', 'syncWithoutDetaching', 'toggle', 'updateExistingPivot',
        'insert', 'update', 'upsert', 'delete', 'forceDelete', 'truncate'];

    // ⚠️ **Due versi, non uno.** `role_has_permissions` è il pivot di una
    // `belongsToMany` che spatie dichiara da **entrambi** i lati: `Role::permissions()`
    // e `Permission::roles()` (vendor, `Models/Permission.php`). Un
    // `$permesso->roles()->detach($ruolo)` fa esattamente la scrittura vietata,
    // senza invalidare `spatie.permission.cache` — ed è il modo più naturale di
    // scriverla partendo dal permesso.
    //
    // *La prima stesura guardava il solo `->permissions()`: verificato, un
    // metodo che usava la relazione inversa lasciava questo file tutto verde.
    // Un guardrail dichiarato «l'unica rete di questa feature» aveva un buco
    // della stessa forma di quello che dice di coprire.*
    $sorgenti = ['->permissions\(\)', '->roles\(\)'];

    foreach (variabiliCheTengonoIlPivotDeiPermessi($codice) as $variabile) {
        $sorgenti[] = preg_quote($variabile, '/');
    }

    foreach (metodiCheRestituisconoIlPivotDeiPermessi($codice) as $metodo) {
        $sorgenti[] = '\$this->'.preg_quote($metodo, '/').'\(\)';
    }

    foreach ($sorgenti as $sorgente) {
        foreach ($scritture as $scrittura) {
            if (preg_match('/'.$sorgente.'[^;]*->'.$scrittura.'\(/', $codice) === 1) {
                $trovate[] = $scrittura;
            }
        }
    }

    return array_values(array_unique($trovate));
}

/**
 * Le variabili che tengono **la relazione**, non righe già lette.
 *
 * La distinzione è ciò che tiene il guardrail utilizzabile: `$nomi =
 * $r->permissions()->pluck('name')` non è il pivot, è una collection, e
 * chiamarci sopra un `->delete()` non tocca `role_has_permissions`. Contarla
 * come violazione farebbe gridare il guardrail su righe oneste — e un guardrail
 * che grida a vuoto viene disattivato entro la settimana.
 *
 * @return list<string>
 */
function variabiliCheTengonoIlPivotDeiPermessi(string $codice): array
{
    $materializzano = 'find|findOrFail|first|firstOr|firstOrFail|firstWhere|sole|get|getQuery|pluck|count|sum|avg|max|min|exists|doesntExist|paginate|simplePaginate|cursorPaginate|value|cursor|toArray|toBase';

    // Entrambi i versi della relazione, per la ragione scritta sopra.
    preg_match_all('/(\$\w+)=[^;]*?->(?:permissions|roles)\(\)([^;]*)/', $codice, $trovate, PREG_SET_ORDER);

    $variabili = [];

    foreach ($trovate as [, $variabile, $coda]) {
        if (preg_match('/->('.$materializzano.')\(/', $coda) === 1) {
            continue;
        }

        $variabili[] = $variabile;
    }

    return array_values(array_unique($variabili));
}

/**
 * I metodi del file il cui `return` è il pivot — direttamente o via una
 * variabile che lo tiene.
 *
 * Le graffe si contano invece di fermarsi alla prima chiusa: il corpo di un
 * metodo ne contiene altre (`if`, `foreach`, closure), e un `[^}]*` taglierebbe
 * via proprio il `return` finale.
 *
 * @return list<string>
 */
function metodiCheRestituisconoIlPivotDeiPermessi(string $codice): array
{
    $variabili = variabiliCheTengonoIlPivotDeiPermessi($codice);
    $metodi = [];

    preg_match_all('/function(\w+)\(/', $codice, $trovate, PREG_OFFSET_CAPTURE);

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

        $restituisce = preg_match('/return[^;]*->permissions\(\);/', $corpo) === 1
            || collect($variabili)->contains(fn (string $v) => str_contains($corpo, 'return'.$v.';'));

        if ($restituisce) {
            $metodi[] = $nome;
        }
    }

    return array_values(array_unique($metodi));
}

/** Il sorgente senza commenti né spazi, per il test sull'API consentita. */
function codiceRbacSenzaCommenti(string $php): string
{
    return collect(token_get_all($php))
        ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true))
        ->map(fn ($t) => is_array($t) ? $t[1] : $t)
        ->implode('');
}

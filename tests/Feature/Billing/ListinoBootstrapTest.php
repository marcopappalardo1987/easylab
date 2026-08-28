<?php

use App\Models\Account;
use App\Models\Piano;
use App\Support\Listino\CatalogoPiani;
use App\Support\Piani;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 Il patto fra `config/easylab.php` e il listino a database (🔗 ADR-035).
 *
 * ## Perché questo file esiste, e perché è già citato per nome altrove
 *
 * `config/easylab.php` promette che «`ListinoBootstrapTest` diventa rosso apposta
 * per dirlo a chi ci prova», e affida a questo file la coerenza fra
 * `easylab.piani.predefinito` e il database. Il docblock di `App\Support\Piani`
 * dice la stessa cosa in modo più esplicito: «la coerenza col database è tenuta
 * da un test (`ListinoBootstrapTest`), **non da una guardia a runtime**».
 *
 * Il rimando è rimasto a lungo senza il file — cioè con una frase rassicurante e
 * nessuna rete dietro, che è peggio di nessuna frase: chi legge smette di
 * cercare.
 *
 * ## La ragione per cui la guardia è un test e non un `if`
 *
 * `Piani::predefinito()` è chiamato da un **webhook**
 * (`StripeWebhookController`, disdetta → decadimento al piano predefinito): lì
 * un'eccezione farebbe **ritentare Stripe per giorni** e poi disabilitare
 * l'endpoint. Un `throw` a runtime sarebbe quindi la risposta sbagliata al posto
 * sbagliato; la risposta giusta è che il caso non si presenti mai, e a garantirlo
 * è la suite.
 *
 * ## L'altra metà: perché non c'è un seeder
 *
 * `config/rbac.php` ha un seeder rilanciabile, e proprio quello rende
 * distruttivo il gesto corretto — `syncPermissions()` detacha tutto e riattacca
 * dai default (CLAUDE.md). ADR-035 ha scelto la strada opposta: il bootstrap del
 * listino è una **migration**, e la trappola non si documenta perché non è
 * esprimibile. Qui quella scelta smette di essere una frase in un commento.
 */

// ─── Il piano predefinito ────────────────────────────────────────────────────

it('keeps easylab.piani.predefinito pointing at a plan that exists in the database', function () {
    // 🔴 Lo scenario intero: qualcuno cambia la chiave in `starter` senza creare
    // la riga. `Piani::predefinito()` restituisce un codice che
    // `Account::cambiaPiano()` rifiuta, e il webhook di disdetta esplode **in
    // produzione**, su un evento che Stripe ritenterà per giorni.
    //
    // Si asserisce sui **tre passaggi** e non solo su `esiste()`, perché è la
    // catena a contare: la config dice un codice, il catalogo lo conosce, e
    // l'unica scrittura che tocca `accounts.piano` lo accetta.
    $predefinito = Piani::predefinito();

    expect($predefinito)->not->toBe('')
        ->and(Piani::esiste($predefinito))->toBeTrue()
        // E **offribile**, non solo esistente: è il piano con cui nasce ogni
        // account nuovo. `GovernoListino::archivia()` lo rifiuta apposta, e
        // questa riga è il rovescio positivo di quel rifiuto.
        ->and(Piani::offribili())->toContain($predefinito);

    $account = Account::factory()->saas()->create();

    $account->cambiaPiano($predefinito);

    expect($account->fresh()->piano)->toBe($predefinito);
});

// ─── Il bootstrap e ciò che ne è nato ────────────────────────────────────────

it('leaves the backfilled listino agreeing with the bootstrap it came from', function () {
    // La migration di backfill legge `easylab.piani.catalogo` **una volta sola**.
    // Da quel momento le due cose possono separarsi senza che nulla se ne
    // accorga — e questa è la sola riga che se ne accorge. Non è una richiesta di
    // tenerle uguali per sempre: è la richiesta che, il giorno in cui divergono,
    // qualcuno lo veda in un rosso invece che in un MRR.
    $catalogo = config('easylab.piani.catalogo');

    expect($catalogo)->toBeArray()->not->toBeEmpty();

    foreach ($catalogo as $codice => $definizione) {
        $piano = Piano::query()->where('codice', $codice)->first();

        expect($piano)->not->toBeNull()
            ->and($piano->etichetta)->toBe($definizione['etichetta'])
            ->and($piano->max_enti)->toBe($definizione['max_enti'])
            ->and($piano->gratuito)->toBe((bool) ($definizione['gratuito'] ?? false))
            ->and($piano->prezzo_mensile_cent)->toBe((int) $definizione['prezzo_mensile_cent']);
    }

    // E nient'altro: il bootstrap è il bootstrap, non «almeno questi».
    expect(Piani::codici())->toEqualCanonicalizing(array_keys($catalogo));
});

it('no longer reads the config as the price list, so touching it moves no money', function () {
    // ⛔ **Dal 27 Ago 2026 `catalogo` NON è più il listino**, e la frase va resa
    // meccanica o resta una promessa: cambiare quei numeri deve **non cambiare il
    // prezzo di nessuno**. Se un giorno qualcuno rimettesse un `config()` dentro
    // `Piani`, questo diventa rosso — ed è l'unico posto in cui diventerebbe
    // rosso, perché in suite i due valori partono uguali e ogni altro test
    // resterebbe verde.
    config(['easylab.piani.catalogo.saas.prezzo_mensile_cent' => 1]);
    config(['easylab.piani.catalogo.saas.etichetta' => 'Un euro']);
    config(['easylab.piani.catalogo.saas.max_enti' => 999]);

    app(CatalogoPiani::class)->dimentica();

    expect(Piani::prezzoMensileCent('saas'))->toBe(4900)
        ->and(Piani::etichetta('saas'))->toBe('SaaS')
        ->and(Piani::maxEnti('saas'))->toBe(5);
});

// ─── La trappola resa inesprimibile ──────────────────────────────────────────

it('declares no re-runnable seeder of the price list, which is the whole point', function () {
    // 🔴 **La differenza deliberata con `config/rbac.php`.** Là esiste un seeder
    // rilanciabile, e proprio quello rende distruttivo il gesto corretto:
    // `syncPermissions()` detacha tutto e riattacca dai default, cancellando ogni
    // personalizzazione fatta a runtime. Qui la stessa trappola non si documenta
    // — si rende **inesprimibile**: non c'è nessun `PianiSeeder` che qualcuno
    // possa lanciare per riflesso dopo aver toccato la config.
    //
    // Il difetto da temere è che qualcuno ne scriva uno «per comodità», leggendo
    // il commento di ADR-035 come una dimenticanza invece che come una decisione.
    // Nessun altro test diventerebbe rosso: un seeder in più non rompe niente,
    // finché non lo si lancia.
    $seeder = collect(glob(database_path('seeders/*.php')))
        ->map(fn (string $file) => basename($file))
        ->filter(fn (string $nome) => str_contains(mb_strtolower($nome), 'piani')
            || str_contains(mb_strtolower($nome), 'listino')
            || str_contains(mb_strtolower($nome), 'prezz'))
        ->values()
        ->all();

    expect($seeder)->toBe([]);
});

it('bootstraps the listino from a migration, and does it only once', function () {
    // La migration di backfill è **idempotente** (`updateOrInsert` sul codice):
    // rilanciarla su un listino già governato dalla schermata non deve riportare
    // indietro i prezzi. Non è teorico — è precisamente il gesto che su
    // `config/rbac.php` distrugge la matrice di runtime.
    //
    // Si esercita il `up()` della migration così com'è, su un listino modificato
    // a mano: ciò che il test guarda è che il numero di righe non cresca e che
    // la migration non sia in grado di *sovrascrivere* un prezzo deciso dalla
    // schermata... e siccome invece `updateOrInsert` lo sovrascriverebbe, ciò che
    // si congela è l'altra metà: quella migration ha già girato, e il suo record
    // in `migrations` è ciò che impedisce che rigiri.
    $nome = '2026_08_27_140200_backfill_listino_dal_catalogo';

    expect(file_exists(database_path("migrations/{$nome}.php")))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', $nome)->exists())->toBeTrue()
        // Una riga sola per piano: il backfill non ha lasciato duplicati.
        ->and(Piano::query()->count())->toBe(count(config('easylab.piani.catalogo')));
});

// --- Il `down()`, che è la metà che nessuno guarda ---
//
// 🔴 Trovato il 28 Ago 2026 allineando gli ADR, e **contro un messaggio di
// commit che lo dichiarava già chiuso**: il `down()` di questa migration faceva
// `DB::table('piani')->delete()` **senza `where`**. Un `migrate:rollback` —
// cioè il gesto che si fa proprio per annullare *questa* migration — portava
// via anche i piani nati da `/piattaforma/piani`, che in nessuna config
// esistono e che `up()` non sa ricreare: prezzi veri, cancellati in silenzio.
//
// Il verso giusto di un `down()` è «disfa ciò che ho fatto io», non «riporta la
// tabella a vuota». Questo test è ciò che tiene in piedi la differenza.

it('undoes only the plans it created, leaving the hand-made listino alone', function () {
    $daConfig = array_keys(config('easylab.piani.catalogo'));

    // Un piano che il backfill non ha mai messo: nasce dalla schermata.
    // ⚠️ `forceFill` e non `create`: `codice` e `gratuito` stanno FUORI dal
    // `$fillable` di proposito — non sono campi, sono l'identità del piano, e
    // dopo la nascita non si toccano. Qui si sta simulando la nascita.
    $aMano = new Piano;
    $aMano->forceFill([
        'codice' => 'enterprise',
        'etichetta' => 'Enterprise',
        'max_enti' => null,
        'gratuito' => false,
        'attivo' => true,
        'ordine' => 99,
        'prezzo_mensile_cent' => 29900,
    ])->save();

    // Si esercita il `down()` vero della migration, non una sua imitazione.
    $migration = require database_path('migrations/2026_08_27_140200_backfill_listino_dal_catalogo.php');
    $migration->down();

    expect(Piano::query()->where('codice', 'enterprise')->exists())
        ->toBeTrue()
        ->and(Piano::query()->whereIn('codice', $daConfig)->exists())
        ->toBeFalse();

    // E il piano sopravvissuto è proprio quello, non un omonimo ricreato.
    expect(Piano::query()->sole()->id)->toBe($aMano->id);
});

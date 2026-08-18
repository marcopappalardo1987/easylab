<?php

use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;

/**
 * La lingua dei messaggi di validazione (rientro dall'uso reale, 9 Ago 2026).
 *
 * Il progetto scrive ogni stringa di UI in italiano hard-coded, ma la
 * validazione veniva dal fallback interno del framework: fino a oggi un campo
 * obbligatorio rispondeva «The intervento form.descrizione field is required.»
 * — in inglese **e** con la struttura interna del form in bella vista. Non era
 * il difetto di un form: valeva per tutta l'app dalla S1, e si è visto solo
 * quando un errore di validazione è comparso davvero a schermo.
 */
it('runs in Italian by default, without depending on the environment', function () {
    // Il default sta in config/app.php e non solo in .env: affidare la lingua a
    // una variabile d'ambiente significa che basta una dimenticanza in CI o al
    // deploy per far tornare i messaggi in inglese.
    expect(app()->getLocale())->toBe('it');
});

it('keeps the English fallback, so a missing key degrades instead of breaking', function () {
    // `mac_address` è una regola che il framework traduce e che `lang/it/` NON
    // contiene: è il caso reale di una chiave mancante. Col fallback `en`
    // l'utente legge l'inglese — degradato ma comprensibile; con fallback `it`
    // leggerebbe la stringa grezza `validation.mac_address`.
    //
    // ⚠️ Serve una chiave davvero assente da entrambe le lingue per NON provare
    // nulla: la prima stesura usava un nome inventato, che torna sempre grezzo
    // qualunque sia il fallback — un assert che non poteva fallire.
    expect(config('app.fallback_locale'))->toBe('en')
        ->and(trans('validation.mac_address'))->not->toStartWith('validation.')
        ->and(trans('validation.mac_address'))->toContain('MAC address');
});

it('translates the validation rules the project actually uses', function () {
    foreach (['required', 'date', 'boolean', 'integer', 'array', 'email', 'file', 'confirmed'] as $regola) {
        expect(trans("validation.{$regola}"))->not->toStartWith('validation.')
            ->and(trans("validation.{$regola}"))->not->toContain('The :attribute');
    }

    expect(trans('validation.required'))->toBe('Il campo :attribute è obbligatorio.');
});

it('gives form array fields a readable name instead of the internal key', function () {
    // Metà del difetto visto da Marco era questa: anche col locale corretto,
    // senza gli `attributes` il messaggio direbbe «Il campo intervento
    // form.descrizione è obbligatorio».
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();
    $strumento = Strumento::factory()->forNode($dept)->create();

    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    $errori = scheda($admin, $strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', '')
        ->set('interventoForm.data_scadenza', today()->toDateString())
        ->call('saveIntervento')
        ->errors();

    expect($errori->first('interventoForm.descrizione'))
        ->toBe('Il campo descrizione è obbligatorio.');
});

it('centralises the attribute names, with no second definition in the components', function () {
    // C'era un unico `validationAttributes()` nel progetto, in SchedaStrumento,
    // e copriva due chiavi su decine: ora i nomi vivono in lang/it/validation.php
    // e valgono per tutti i form. Una definizione sola, come per `assegnabili()`.
    $componenti = glob(app_path('Livewire/**/*.php')) ?: [];

    foreach ($componenti as $file) {
        expect(file_get_contents($file))->not->toContain('function validationAttributes');
    }

    // ⚠️ Non con `trans('validation.attributes.ricambiNuovi.*.nome')`: `trans()`
    // spezza sui punti e cercherebbe una struttura annidata. Il validatore usa
    // invece `Arr::get`, che trova prima la chiave letterale — ed è il motivo
    // per cui le chiavi con i punti funzionano davvero. Si asserisce sul
    // comportamento, non sul modo in cui è memorizzato.
    expect(Arr::get(trans('validation.attributes'), 'ricambiNuovi.*.nome'))->toBe('nome del ricambio');
});

it('names the wildcard rows of the repeater, not their index', function () {
    // Il caso che l'utente incontra davvero: la riga 0 del repeater deve
    // chiamarsi «nome del ricambio», non «ricambi nuovi.0.nome».
    $this->seed(RolesAndPermissionsSeeder::class);

    $ente = UnitaOrganizzativa::factory()->ente()->create();
    $dept = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create();
    $strumento = Strumento::factory()->forNode($dept)->create();

    $admin = User::factory()->create(['tenant_id' => $ente->id, 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('Admin');

    $errori = scheda($admin, $strumento)
        ->call('openNuovoIntervento')
        ->set('interventoForm.descrizione', 'Sostituzione')
        ->set('interventoForm.data_scadenza', today()->toDateString())
        ->set('ricambiEffettuati', true)
        ->call('addRicambio')
        ->set('ricambiNuovi.0.nome', 'Guarnizione')
        ->set('ricambiNuovi.0.scadenza_garanzia', '')
        ->call('saveIntervento')
        ->errors();

    expect($errori->first('ricambiNuovi.0.scadenza_garanzia'))
        ->toBe('Il campo scadenza garanzia è obbligatorio.');
});

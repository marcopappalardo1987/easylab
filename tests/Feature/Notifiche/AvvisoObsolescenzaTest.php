<?php

use App\Enums\TransizioneAvviso;
use App\Models\Account;
use App\Models\AvvisoScadenza;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\AvvisoObsolescenza;
use App\Support\Notifiche\RigaObsolescenza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * L'alert automatico di obsolescenza (🔗 ADR-014 × ADR-011) — area rossa della
 * Policy di Code Review su tre fronti, esattamente come il digest: niente
 * duplicati, isolamento fra Enti, sotto-albero del Responsabile. In console i
 * global scope non filtrano, quindi ognuna di quelle garanzie vive nel codice
 * di `AvvisiObsolescenza` e va verificata qui, non dedotta.
 *
 * ⚠️ **`data_installazione` è SEMPRE esplicita nelle fixture, mai lasciata alla
 * factory.** `StrumentoFactory` la genera con `dateTimeBetween('-12 years',
 * 'now')`: con la soglia di default a 10 anni, circa una macchina su sei
 * nascerebbe già obsoleta e i conteggi di questi test sarebbero rossi una volta
 * su sei, in modo non deterministico.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->admin->assignRole('Admin');
});

function sweep(bool $senzaInvio = false): void
{
    test()->artisan('easylab:notifica-obsolescenza', $senzaInvio ? ['--senza-invio' => true] : [])
        ->assertSuccessful();
}

/** Una macchina con età esatta, nel nodo indicato (default: il dipartimento). */
function macchinaDi(UnitaOrganizzativa $nodo, string $nome, int $anniDiEta, int $piuGiorni = 0): Strumento
{
    return Strumento::factory()->forNode($nodo)->create([
        'nome' => $nome,
        'data_installazione' => today()->subYears($anniDiEta)->addDays($piuGiorni)->toDateString(),
    ]);
}

/** @return list<RigaObsolescenza> righe dell'avviso ricevuto da un utente */
function righeObsolescenzaDi(User $utente): array
{
    $inviate = Notification::sent($utente, AvvisoObsolescenza::class);

    return $inviate->isEmpty() ? [] : $inviate->first()->righe;
}

it('sends one single email listing every machine that crossed the line', function () {
    macchinaDi($this->dept, 'Agitatore 2358', 11);
    macchinaDi($this->dept, 'Centrifuga 88', 14);
    macchinaDi($this->altroDept, 'Stufa 5', 12);
    // …e una giovane, che non deve comparire.
    macchinaDi($this->dept, 'Spettrometro nuovo', 2);

    sweep();

    // UNA notifica sola con tre righe, non tre notifiche: è la decisione di
    // prodotto «una email unica con l'elenco lungo», la stessa ragione
    // anti-spam per cui il digest è un digest.
    expect(Notification::sent($this->admin, AvvisoObsolescenza::class))->toHaveCount(1);

    $nomi = array_map(fn (RigaObsolescenza $r) => $r->strumentoNome, righeObsolescenzaDi($this->admin));
    expect($nomi)->toHaveCount(3)
        ->and($nomi)->toContain('Agitatore 2358')
        ->and($nomi)->toContain('Centrifuga 88')
        ->and($nomi)->toContain('Stufa 5');

    // La macchina giovane non è nell'elenco. Asserzione separata: `toContain()`
    // è variadico e un secondo argomento diventerebbe un secondo ago.
    expect($nomi)->not->toContain('Spettrometro nuovo');
});

it('warns at the exact boundary and not one day earlier', function () {
    // Il confine di ADR-014 è INCLUSIVO: installata esattamente N anni fa oggi,
    // la macchina è già obsoleta. Un giorno più giovane, no.
    macchinaDi($this->dept, 'Sul confine', 10);
    macchinaDi($this->dept, 'Un giorno prima del confine', 10, piuGiorni: 1);

    sweep();

    $nomi = array_map(fn (RigaObsolescenza $r) => $r->strumentoNome, righeObsolescenzaDi($this->admin));

    expect($nomi)->toBe(['Sul confine']);
});

it('sends nothing on a second run in the same day', function () {
    macchinaDi($this->dept, 'Agitatore 2358', 11);

    sweep();
    expect(righeObsolescenzaDi($this->admin))->toHaveCount(1);

    Notification::fake();
    sweep();

    // La memoria è `avvisi_scadenza`, non una condizione del comando.
    Notification::assertNothingSent();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(1);
});

it('never warns a machine without data_installazione', function () {
    // Senza la data manca la base del calcolo, e dichiararla obsoleta sarebbe
    // un'affermazione non sostenuta dai dati (`Strumento::isObsoleto()`).
    Strumento::factory()->forNode($this->dept)->create([
        'nome' => 'Senza data',
        'data_installazione' => null,
    ]);

    sweep();

    Notification::assertNothingSent();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(0);
});

it('only lists the machines that newly crossed, when the threshold is lowered', function () {
    // 🔴 Il test che regge la decisione di prodotto, e quello che la prova di
    // mutazione usa per bocciare `data_installazione + soglia` nella chiave del
    // log: con quella forma la chiave cambierebbe al cambiare della soglia, e
    // tutte e cinque le macchine verrebbero riavvisate invece delle tre nuove.
    macchinaDi($this->dept, 'Vecchia 1', 12);
    macchinaDi($this->dept, 'Vecchia 2', 15);

    sweep();
    expect(righeObsolescenzaDi($this->admin))->toHaveCount(2);

    macchinaDi($this->dept, 'Media 1', 9);
    macchinaDi($this->dept, 'Media 2', 8);
    macchinaDi($this->altroDept, 'Media 3', 8);

    $this->ente->update(['soglia_obsolescenza_anni' => 8]);

    Notification::fake();
    sweep();

    $nomi = array_map(fn (RigaObsolescenza $r) => $r->strumentoNome, righeObsolescenzaDi($this->admin));

    expect($nomi)->toHaveCount(3)
        ->and($nomi)->toContain('Media 1')
        ->and($nomi)->toContain('Media 2')
        ->and($nomi)->toContain('Media 3');

    expect($nomi)->not->toContain('Vecchia 1');
    expect($nomi)->not->toContain('Vecchia 2');

    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(5);
});

it('lets a machine be warned again after the threshold goes up and down', function () {
    $macchina = macchinaDi($this->dept, 'Altalena', 11);

    sweep();
    expect(righeObsolescenzaDi($this->admin))->toHaveCount(1);

    // Soglia alzata: la macchina torna «nuova» e la riga di avviso sparisce.
    // È l'unica transizione le cui righe si cancellano — vedi
    // `TransizioneAvviso::Obsoleta`.
    $this->ente->update(['soglia_obsolescenza_anni' => 20]);

    Notification::fake();
    sweep();

    Notification::assertNothingSent();
    expect(AvvisoScadenza::where('riferimento_id', $macchina->id)
        ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(0);

    // Riabbassata: torna a essere un attraversamento, e riparte l'avviso.
    $this->ente->update(['soglia_obsolescenza_anni' => 10]);

    Notification::fake();
    sweep();

    expect(righeObsolescenzaDi($this->admin))->toHaveCount(1)
        ->and(AvvisoScadenza::where('riferimento_id', $macchina->id)
            ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(1);
});

it('never puts machines of another Ente in the list', function () {
    // 🔴 Isolamento. In console i global scope si ritirano: se il servizio non
    // portasse il proprio `where('strumenti.tenant_id')`, l'email di un Ente
    // conterrebbe le macchine di un altro — e uscirebbe dall'applicazione, dove
    // nessuna schermata fa da gate.
    macchinaDi($this->dept, 'Agitatore 2358', 11);

    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    macchinaDi($deptB, 'Centrifuga B', 11);

    sweep();

    $nomi = array_map(fn (RigaObsolescenza $r) => $r->strumentoNome, righeObsolescenzaDi($this->admin));
    expect($nomi)->toBe(['Agitatore 2358']);

    // E non c'è nemmeno nel corpo reso dell'email: il nome dell'altro Ente e
    // quello della sua macchina non devono comparire da nessuna parte.
    $reso = (string) Notification::sent($this->admin, AvvisoObsolescenza::class)
        ->first()->toMail($this->admin)->render();

    expect($reso)->not->toContain('Centrifuga B');
    expect($reso)->not->toContain('Ente B');
});

it('limits a Responsabile to the machines of their own sotto-albero', function () {
    // 🔴 Il sotto-albero si riapplica a mano, con lo stesso criterio
    // dell'applicazione: in console `AccessibleNodes::forCurrentUser()` non
    // esiste, e senza questo filtro l'email sarebbe il canale che scavalca lo
    // scope.
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    macchinaDi($this->dept, 'Nel reparto', 11);
    macchinaDi($this->altroDept, 'Fuori reparto', 11);

    sweep();

    expect(righeObsolescenzaDi($this->admin))->toHaveCount(2);

    $sue = array_map(fn (RigaObsolescenza $r) => $r->strumentoNome, righeObsolescenzaDi($resp));
    expect($sue)->toBe(['Nel reparto']);
});

it('sends nothing to a Responsabile without assignments', function () {
    // Fail-safe: senza assegnazioni `AccessibleNodes::forUser()` torna `[]`, non
    // `null` — «niente», non «tutto».
    $resp = User::factory()->create(['tenant_id' => $this->ente->id]);
    $resp->assignRole('Responsabile Reparto');

    macchinaDi($this->dept, 'Agitatore 2358', 11);

    sweep();

    Notification::assertNotSentTo($resp, AvvisoObsolescenza::class);
});

it('sends nothing to the ente of an account in lockout, and warns again after unlock', function () {
    // ADR-013: il blocco per insoluto è totale. Dire «rinnova il parco
    // macchine» a chi non può nemmeno entrare è la contraddizione in un minuto
    // solo. E il log NON si scrive, così allo sblocco è ancora una novità.
    $bloccato = Account::factory()->bloccato()->create();
    $sedeBloccata = UnitaOrganizzativa::factory()->ente()->perAccount($bloccato)->create(['nome' => 'Ente Bloccato']);
    $deptBloccato = UnitaOrganizzativa::factory()->dipartimento()->under($sedeBloccata)->create();
    macchinaDi($deptBloccato, 'Vecchia e bloccata', 11);

    $suoAdmin = User::factory()->create(['tenant_id' => $sedeBloccata->id]);
    $suoAdmin->assignRole('Admin');

    sweep();

    Notification::assertNotSentTo($suoAdmin, AvvisoObsolescenza::class);
    expect(AvvisoScadenza::where('tenant_id', $sedeBloccata->id)->count())->toBe(0);

    // `sblocca()` e non un `update()`: `is_locked` è fuori dal fillable
    // apposta — è la colonna che decide l'accesso di tutti gli utenti
    // dell'account, e si scrive solo dai metodi di dominio.
    $bloccato->sblocca();

    Notification::fake();
    sweep();

    expect(righeObsolescenzaDi($suoAdmin))->toHaveCount(1);
});

it('fills the log without notifying anyone with --senza-invio', function () {
    // Il primo run in produzione: su dati esistenti OGNI macchina già obsoleta
    // è un attraversamento mai notificato, e la prima email sarebbe una lista
    // di centinaia di righe.
    macchinaDi($this->dept, 'Agitatore 2358', 11);
    macchinaDi($this->dept, 'Centrifuga 88', 14);

    sweep(senzaInvio: true);

    Notification::assertNothingSent();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(2);

    // E dal giro dopo arrivano solo le novità vere: nulla, qui.
    sweep();
    Notification::assertNothingSent();
});

it('keeps the in-app notification and drops the email for who opted out', function () {
    // L'opt-out riusa `riceve_email_scadenze` (registro trattamenti T4): è il
    // diritto di opposizione alle email programmate operative, e questa lo è.
    // La copia in-app resta, perché non è un invio verso l'esterno.
    // `forceFill` e non `update()`: la colonna è fuori dal fillable, come
    // fa già `DigestScadenzeTest` per la stessa preferenza.
    $this->admin->forceFill(['riceve_email_scadenze' => false])->save();

    macchinaDi($this->dept, 'Agitatore 2358', 11);

    sweep();

    Notification::assertSentTo(
        $this->admin,
        AvvisoObsolescenza::class,
        fn ($notifica, array $canali) => $canali === ['database'],
    );
});

it('keeps both channels for who did not opt out', function () {
    macchinaDi($this->dept, 'Agitatore 2358', 11);

    sweep();

    Notification::assertSentTo(
        $this->admin,
        AvvisoObsolescenza::class,
        fn ($notifica, array $canali) => $canali === ['database', 'mail'],
    );
});

it('warns the new Ente when an obsolete machine is transferred across tenants', function () {
    // 🔴 ADR-015: una macchina può cambiare Ente (`TipoSpostamento::CrossTenant`),
    // e il nuovo proprietario ha diritto al proprio avviso — lo storico segue la
    // macchina. La riga di log del vecchio Ente resta invece dov'è, perché il
    // reset è CONSERVATIVO: dopo il trasferimento quella macchina non è più fra
    // le sue, quindi non viene «positivamente letta» e non si tocca.
    //
    // È il caso che chiede a `avvisi_scadenza_unico` di comprendere `tenant_id`:
    // la data di avviso è la `data_installazione` NUDA, quindi le due righe —
    // quella del vecchio Ente e quella del nuovo — condividono morph,
    // riferimento, transizione e data. Senza `tenant_id` nella unique la
    // seconda `create()` viola il vincolo e il comando notturno ABORTA, lasciando
    // senza avviso ogni Ente successivo, tutti i giorni, finché la macchina
    // resta dov'è.
    $macchina = macchinaDi($this->dept, 'Agitatore trasferito', 11);

    sweep();
    expect(righeObsolescenzaDi($this->admin))->toHaveCount(1);

    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB = UnitaOrganizzativa::factory()->dipartimento()->under($enteB)->create();
    $adminB = User::factory()->create(['tenant_id' => $enteB->id]);
    $adminB->assignRole('Admin');

    // `forceFill` perché `tenant_id` è fuori dal fillable: lo spostamento
    // cross-tenant è un gesto di dominio, non un update di form.
    $macchina->forceFill([
        'tenant_id' => $enteB->id,
        'unita_organizzativa_id' => $deptB->id,
    ])->save();

    Notification::fake();
    sweep();

    expect(righeObsolescenzaDi($adminB))->toHaveCount(1);

    // Il vecchio Ente non riceve nulla: per lui non è cambiato niente.
    Notification::assertNotSentTo($this->admin, AvvisoObsolescenza::class);

    // Due righe di log, una per Ente: la unique le distingue per `tenant_id`.
    expect(AvvisoScadenza::where('riferimento_id', $macchina->id)
        ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(2);
});

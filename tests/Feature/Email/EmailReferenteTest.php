<?php

use App\Enums\VisibilitaGaranzieRicambio;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\AvvisoObsolescenza;
use App\Notifications\DigestScadenze;
use App\Notifications\InterventoAssegnato;
use App\Notifications\InterventoEseguito;
use App\Notifications\InterventoProgrammato;
use App\Notifications\MacchinaSegnalata;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use App\Support\Notifiche\AvvisiObsolescenza;
use App\Support\Notifiche\DestinatariEnte;
use App\Support\Notifiche\Referente;
use App\Support\Notifiche\RigaAvviso;
use App\Support\Notifiche\RigaObsolescenza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Le email dello strumento arrivano anche al suo referente (🔗 ADR-054).
 *
 * **Area rossa**: decide cosa esce dall'applicazione e verso chi, e qui il
 * «chi» è una casella scritta a mano su una scheda — non una persona con un
 * account, un ruolo e delle preferenze. Contano quindi soprattutto i casi in
 * cui NON deve partire nulla, e quelli in cui deve partire **una volta sola**.
 *
 * Mondo: l'Ente A con due laboratori. L'Autoclave sta in Chimica e ha per
 * referente Giulia Bianchi; la Centrifuga sta in Fisica e non ne ha. L'Ente B
 * è la controprova.
 */
const CASELLA_REFERENTE = 'giulia.bianchi@ospedale.test';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Chimica']);
    $this->fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Fisica']);

    // ⚠️ La data di installazione è fissata: la factory la sorteggia fino a
    // dodici anni fa, e una macchina su sei nascerebbe già oltre la soglia di
    // obsolescenza — cioè con un destinatario in più, una volta ogni tanto.
    $recente = today()->subYears(2)->toDateString();

    $this->autoclave = Strumento::factory()->forNode($this->chimica)->create([
        'nome' => 'Autoclave',
        'data_installazione' => $recente,
        'referente_nome' => 'Giulia',
        'referente_cognome' => 'Bianchi',
        'referente_email' => CASELLA_REFERENTE,
        'referente_cellulare' => '333 1234567',
    ]);
    $this->centrifuga = Strumento::factory()->forNode($this->fisica)->create(['nome' => 'Centrifuga', 'data_installazione' => $recente]);

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);

    $persona = function (string $nome, string $ruolo, ?UnitaOrganizzativa $ente): User {
        $u = User::factory()->create(['name' => $nome, 'tenant_id' => $ente?->id, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($ruolo);

        return $u->fresh();
    };

    $this->admin = $persona('Aldo Admin', 'Admin', $this->enteA);
    $this->altroAdmin = $persona('Anna Admin', 'Admin', $this->enteA);
    $this->tenant = $persona('Tina Tenant', 'Tenant', $this->enteA);
    $this->respChimica = $persona('Rita Chimica', 'Responsabile Reparto', $this->enteA);
    $this->respChimica->unitaResponsabili()->attach($this->chimica);
    $this->respFisica = $persona('Franco Fisica', 'Responsabile Reparto', $this->enteA);
    $this->respFisica->unitaResponsabili()->attach($this->fisica);
    $this->tecnicoInterno = $persona('Mario Interno', 'Tecnico', $this->enteA);
    $this->adminB = $persona('Bruno B', 'Admin', $this->enteB);

    $this->tecnico = $persona('Elio Esterno', 'Tecnico', null);
    $this->tecnico->portafoglioClienti()->attach($this->enteA);
    $this->gestore = $persona('Giorgia Gestore', 'Gestore', null);
    $this->gestore->portafoglioClienti()->attach($this->enteA);

    Notification::fake();

    $this->accendi = fn (string ...$chiavi) => array_map(fn ($c) => InterruttoriEmail::imposta($c, true), $chiavi);

    // Ciò che è arrivato a UNA casella, cioè a un destinatario senza account.
    $this->aCasella = fn (string $classe, string $email = CASELLA_REFERENTE) => Notification::sent(
        new AnonymousNotifiable,
        $classe,
        fn ($notifica, array $canali, object $casella) => ($casella->routes['mail'] ?? null) === $email,
    );

    // Il referente dell'Autoclave diventa una persona che in Easy Lab c'è già.
    $this->referenteE = function (User $persona): void {
        $this->autoclave->update(['referente_email' => $persona->email]);
    };

    // Segnala la macchina dalla scheda, come farebbe una persona.
    $this->segnala = fn (User $chi, string $stato = 'rosso', string $motivo = 'Guasto alla pompa', ?Strumento $macchina = null) => scheda($chi, $macchina ?? $this->autoclave)
        ->call('openForza')
        ->set('forzaForm.stato', $stato)
        ->set('forzaForm.motivo', $motivo)
        ->call('forza')
        ->assertHasNoErrors();

    // Pianifica un intervento dalla scheda.
    $this->pianifica = function (User $chi) {
        $pagina = scheda($chi, $this->autoclave)->call('openNuovoIntervento');

        foreach ([
            'descrizione' => 'Taratura annuale',
            'tipo' => 'manutenzione_ordinaria',
            'data_scadenza' => today()->addMonths(3)->toDateString(),
            'tecnico_id' => $this->tecnico->id,
        ] as $campo => $valore) {
            $pagina->set("interventoForm.{$campo}", $valore);
        }

        return $pagina->call('saveIntervento')->assertHasNoErrors();
    };

    // Il giro delle 06:00, e quello delle 06:15.
    $this->scadenze = fn (array $opzioni = []) => $this->artisan('easylab:notifica-scadenze', $opzioni)->assertSuccessful();
    $this->obsolescenza = fn (array $opzioni = []) => $this->artisan('easylab:notifica-obsolescenza', $opzioni)->assertSuccessful();

    $this->inScadenza = fn (Strumento $macchina, string $descrizione = 'Taratura') => Intervento::factory()->forStrumento($macchina)
        ->create(['descrizione' => $descrizione, 'data_scadenza' => today()->addDays(5)->toDateString()]);

    // Per le prove di consegna vera: toglie la finta, e legge ciò che il
    // trasporto ha spedito davvero, casella per casella.
    $this->perDavvero = fn () => Notification::swap(new ChannelManager(app()));
    $this->spedite = fn () => collect(Mail::mailer()->getSymfonyTransport()->messages())
        ->map(fn ($spedita) => $spedita->getOriginalMessage())
        ->groupBy(fn ($messaggio) => $messaggio->getTo()[0]->getAddress());

    /** @return list<string> i nomi delle macchine in un riepilogo o in un avviso */
    $this->macchineIn = fn ($notifica): array => array_map(fn (RigaAvviso|RigaObsolescenza $riga) => $riga->strumentoNome, $notifica->righe);
});

// ─── Chi è il referente, per chi notifica ────────────────────────────────────

it('is somebody to write to only when the page of the strumento carries an address', function () {
    $referente = Referente::di($this->autoclave);

    expect($referente->email)->toBe(CASELLA_REFERENTE)
        ->and($referente->nome)->toBe('Giulia Bianchi')
        ->and($referente->casella()->routes)->toBe(['mail' => CASELLA_REFERENTE])
        // Un nome e un cellulare senza indirizzo: un referente, ma non un destinatario.
        ->and(Referente::di(Strumento::factory()->forNode($this->chimica)->create(['referente_nome' => 'Luca', 'referente_cellulare' => '333'])))->toBeNull()
        ->and(Referente::di($this->centrifuga))->toBeNull();
});

it('reads the address without capitals also when it was written without passing through the model', function () {
    // Un import in blocco non passa dal model, e scrive ciò che trova.
    DB::table('strumenti')->where('id', $this->autoclave->id)->update(['referente_email' => ' Giulia.Bianchi@Ospedale.TEST ']);

    $referente = Referente::di($this->autoclave->fresh());

    expect($referente->email)->toBe(CASELLA_REFERENTE)
        ->and($referente->corrispondeA(CASELLA_REFERENTE))->toBeTrue();
});

it('recognises an address whatever its capitals and the blanks around it', function () {
    $referente = Referente::di($this->autoclave);

    expect($referente->corrispondeA('  Giulia.Bianchi@Ospedale.TEST '))->toBeTrue()
        ->and($referente->corrispondeA('giulia.bianchi@ospedale.test.it'))->toBeFalse()
        ->and($referente->corrispondeA(null))->toBeFalse()
        ->and($referente->corrispondeA(''))->toBeFalse();
});

it('reads the referenti of the machines of one ente, and of that ente only', function () {
    $laboratorioB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();
    $sequenziatore = Strumento::factory()->forNode($laboratorioB)->create(['referente_email' => 'luca.verdi@altro.test']);

    $ids = [$this->autoclave->id, $this->centrifuga->id, $sequenziatore->id];

    expect(array_map(fn (Referente $r) => $r->email, Referente::delleMacchine($this->enteA->id, $ids)))
        ->toBe([$this->autoclave->id => CASELLA_REFERENTE])
        // 🔴 In console i global scope non filtrano: a tenere fuori la macchina
        // di un altro cliente è la sola richiesta esplicita dell'Ente.
        ->and(array_map(fn (Referente $r) => $r->email, Referente::delleMacchine($this->enteB->id, $ids)))
        ->toBe([$sequenziatore->id => 'luca.verdi@altro.test'])
        // Solo le macchine chieste: l'Autoclave ha un referente, ma qui non è fra quelle.
        ->and(Referente::delleMacchine($this->enteA->id, [$this->centrifuga->id]))->toBe([])
        ->and(Referente::delleMacchine($this->enteA->id, []))->toBe([]);

    // Un indirizzo vuoto scritto senza passare dal model (un import in blocco)
    // non è un referente a cui scrivere.
    DB::table('strumenti')->where('id', $this->autoclave->id)->update(['referente_email' => '  ']);

    expect(Referente::delleMacchine($this->enteA->id, $ids))->toBe([]);
});

// ─── Macchina segnalata ──────────────────────────────────────────────────────

it('tells the referente that the machine was flagged, at the address on its page, and keeps telling everybody else', function () {
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);

    ($this->segnala)($this->admin);

    Notification::assertSentOnDemand(
        MacchinaSegnalata::class,
        fn (MacchinaSegnalata $n, array $canali, object $casella) => $casella->routes === ['mail' => CASELLA_REFERENTE]
            && $canali === ['mail']
            && $n->alReferente === true
            && $n->enteId === $this->enteA->id
            && $n->strumentoId === $this->autoclave->id
            && $n->strumentoNome === 'Autoclave'
            && $n->stato === 'Strumento non idoneo'
            && $n->motivo === 'Guasto alla pompa'
            && $n->autore === 'Aldo Admin',
    );
    Notification::assertSentOnDemandTimes(MacchinaSegnalata::class, 1);

    // 🔴 Si aggiunge, non sostituisce: le persone del cliente la ricevono come prima.
    Notification::assertSentTo(
        [$this->altroAdmin, $this->tenant, $this->respChimica],
        fn (MacchinaSegnalata $n) => $n->alReferente === false,
    );
    Notification::assertNotSentTo([$this->admin, $this->respFisica, $this->adminB, $this->tecnicoInterno], MacchinaSegnalata::class);
});

it('writes to no address at all when the machine has no referente, or has one without an address', function () {
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);

    ($this->segnala)($this->admin, macchina: $this->centrifuga);

    $this->autoclave->update(['referente_email' => null]);
    ($this->segnala)($this->admin);

    Notification::assertSentOnDemandTimes(MacchinaSegnalata::class, 0);
    // La macchina è stata segnalata davvero, e gli altri lo hanno saputo.
    Notification::assertSentToTimes($this->tenant, MacchinaSegnalata::class, 2);
});

it('writes to nobody, referente included, while the email is off', function () {
    // 🔴 Lo stato di nascita: scrivere un indirizzo su una scheda non accende
    // un'email che la piattaforma tiene spenta.
    ($this->segnala)($this->admin);

    Notification::assertNothingSent();
});

it('writes once to a referente who already receives the email as a person of the client', function () {
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);

    // Le maiuscole non fanno di una persona due destinatari.
    $this->altroAdmin->forceFill(['email' => 'Anna.Admin@Ospedale.TEST'])->save();
    $this->autoclave->update(['referente_email' => 'anna.admin@ospedale.test']);

    ($this->segnala)($this->admin);

    Notification::assertSentOnDemandTimes(MacchinaSegnalata::class, 0);
    Notification::assertSentToTimes($this->altroAdmin->fresh(), MacchinaSegnalata::class, 1);
});

it('does not write to the referente who flagged the machine themselves', function () {
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    ($this->referenteE)($this->admin);

    ($this->segnala)($this->admin);

    Notification::assertSentOnDemandTimes(MacchinaSegnalata::class, 0);
    Notification::assertNotSentTo($this->admin, MacchinaSegnalata::class);
    Notification::assertSentTo($this->tenant, MacchinaSegnalata::class);
});

it('does not write to the referente who is somebody of easylab flagging the machine', function () {
    // Chi gestisce la macchina per EasyLab non è fra le persone del cliente:
    // se è lui il referente e segnala lui, resta comunque quello che lo sa già.
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    ($this->referenteE)($this->gestore);

    ($this->segnala)($this->gestore);

    Notification::assertSentOnDemandTimes(MacchinaSegnalata::class, 0);
    Notification::assertSentTo($this->admin, MacchinaSegnalata::class);
});

it('treats a responsabile named as referente of a machine outside their laboratori as that person, once', function () {
    // Franco segue Fisica, e l'Autoclave sta in Chimica: da Responsabile non la
    // riceverebbe. Qualcuno però lo ha scritto come referente di quella
    // macchina, quindi la riceve — da persona, non per indirizzo.
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    ($this->referenteE)($this->respFisica);

    ($this->segnala)($this->admin);

    Notification::assertSentOnDemandTimes(MacchinaSegnalata::class, 0);
    Notification::assertSentToTimes($this->respFisica, MacchinaSegnalata::class, 1);
    Notification::assertSentTo($this->respFisica, fn (MacchinaSegnalata $n) => $n->alReferente === false);
});

it('keeps their laboratori as the limit for every other machine', function () {
    // 🔴 Essere referente dell'Autoclave non apre a Franco le altre macchine di
    // Chimica: per quelle resta un Responsabile di Fisica.
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    ($this->referenteE)($this->respFisica);
    $bilancia = Strumento::factory()->forNode($this->chimica)->create(['nome' => 'Bilancia']);

    ($this->segnala)($this->admin, macchina: $bilancia);

    Notification::assertNotSentTo($this->respFisica, MacchinaSegnalata::class);
    Notification::assertSentTo($this->respChimica, MacchinaSegnalata::class);
});

it('respects the choice of a referente who is a person and gave the email up', function () {
    // 🔴 È la ragione per cui lo si tratta da persona: per indirizzo, la sua
    // rinuncia non la leggerebbe nessuno.
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    ($this->referenteE)($this->respFisica);
    $this->respFisica->forceFill(['riceve_email_macchine_segnalate' => false])->save();

    ($this->segnala)($this->admin);

    Notification::assertNotSentTo($this->respFisica->fresh(), MacchinaSegnalata::class);
    Notification::assertSentOnDemandTimes(MacchinaSegnalata::class, 0);
});

it('writes by address to a referente who has an account but is not among those who receive these emails', function () {
    // Un tecnico interno non è «il cliente» per queste email (ADR-047): non ha
    // preferenze da leggere, quindi il suo indirizzo vale come quello di chiunque.
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    ($this->referenteE)($this->tecnicoInterno);

    ($this->segnala)($this->admin);

    expect(($this->aCasella)(MacchinaSegnalata::class, $this->tecnicoInterno->email))->toHaveCount(1);
    Notification::assertNotSentTo($this->tecnicoInterno, MacchinaSegnalata::class);
});

it('never writes to the referente of another machine', function () {
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    $this->centrifuga->update(['referente_email' => 'luca.verdi@ospedale.test']);

    ($this->segnala)($this->admin);

    expect(($this->aCasella)(MacchinaSegnalata::class, 'luca.verdi@ospedale.test'))->toBeEmpty()
        ->and(($this->aCasella)(MacchinaSegnalata::class))->toHaveCount(1);
});

// ─── Intervento programmato, eseguito, assegnato ─────────────────────────────

it('tells the referente that an intervento was planned on the machine', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO);

    ($this->pianifica)($this->admin);

    Notification::assertSentOnDemand(
        InterventoProgrammato::class,
        fn (InterventoProgrammato $n, array $canali, object $casella) => $casella->routes === ['mail' => CASELLA_REFERENTE]
            && $canali === ['mail']
            && $n->alReferente === true
            && $n->strumentoNome === 'Autoclave'
            && $n->descrizione === 'Taratura annuale'
            && $n->assegnatario === 'Elio Esterno'
            && $n->autore === 'Aldo Admin',
    );
    Notification::assertSentOnDemandTimes(InterventoProgrammato::class, 1);
    Notification::assertSentTo([$this->altroAdmin, $this->tenant, $this->respChimica], fn (InterventoProgrammato $n) => $n->alReferente === false);
    Notification::assertNotSentTo([$this->admin, $this->respFisica], InterventoProgrammato::class);
});

it('tells the referente that an intervento was carried out on the machine', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ESEGUITO);

    $intervento = Intervento::factory()->forStrumento($this->autoclave)->create([
        'descrizione' => 'Sostituzione guarnizioni', 'tecnico_id' => $this->tecnico->id,
    ]);

    scheda($this->tecnico, $this->autoclave)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->toDateString())
        ->set('reportFineLavoro', 'Guarnizioni sostituite.')
        ->call('completa')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(
        InterventoEseguito::class,
        fn (InterventoEseguito $n, array $canali, object $casella) => $casella->routes === ['mail' => CASELLA_REFERENTE]
            && $canali === ['mail']
            && $n->alReferente === true
            && $n->descrizione === 'Sostituzione guarnizioni'
            && $n->autore === 'Elio Esterno'
            && $n->conReport === true,
    );
    Notification::assertSentOnDemandTimes(InterventoEseguito::class, 1);
    Notification::assertSentTo([$this->admin, $this->tenant, $this->respChimica], fn (InterventoEseguito $n) => $n->alReferente === false);
});

it('leaves the referente out of the email that assigns the work to somebody', function () {
    // È un'email fra chi assegna il lavoro e chi lo fa: la macchina c'entra,
    // ma non è al suo referente che parla.
    ($this->accendi)(CatalogoEmail::INTERVENTO_ASSEGNATO);

    ($this->pianifica)($this->admin);

    Notification::assertSentTo($this->tecnico, InterventoAssegnato::class);
    Notification::assertSentOnDemandTimes(InterventoAssegnato::class, 0);
});

it('says in the catalogue of the emails that the five about a machine also reach its referente', function () {
    // È la pagina in cui chi governa la piattaforma legge a chi scrive ogni email.
    $aChi = fn (string $chiave): string => CatalogoEmail::trova($chiave)->aChi;

    foreach ([
        CatalogoEmail::RIEPILOGO_SCADENZE,
        CatalogoEmail::AVVISO_OBSOLESCENZA,
        CatalogoEmail::MACCHINA_SEGNALATA,
        CatalogoEmail::INTERVENTO_PROGRAMMATO,
        CatalogoEmail::INTERVENTO_ESEGUITO,
    ] as $chiave) {
        expect($aChi($chiave))->toContain('referente');
    }

    expect($aChi(CatalogoEmail::INTERVENTO_ASSEGNATO))->not->toContain('referente');
});

// ─── Le parole delle email ───────────────────────────────────────────────────

it('tells the referente why the email arrives and how to stop it, instead of pointing at settings they do not have', function (Closure $notifica) {
    /** @var MacchinaSegnalata|InterventoProgrammato|InterventoEseguito $perReferente */
    $perReferente = $notifica(true);
    $html = (string) $perReferente->toMail(Referente::di($this->autoclave)->casella())->render();

    expect($html)
        ->toContain('sei indicato come referente di questa macchina per Ente A')
        ->toContain('togliere il tuo indirizzo dalla sua scheda')
        ->not->toContain('preferenze notifiche')
        ->not->toContain('/settings/notifiche');

    // La stessa email, a una persona del cliente: com'era.
    $html = (string) $notifica(false)->toMail($this->tenant)->render();

    expect($html)
        ->toContain('perché segui delle macchine su Easy Lab')
        ->toContain('/settings/notifiche')
        ->not->toContain('referente');
})->with([
    'macchina segnalata' => [fn (bool $alReferente) => new MacchinaSegnalata(test()->enteA->id, 'Ente A', test()->autoclave->id, 'Autoclave', 'Ente A › Chimica', 'Strumento non idoneo', true, 'Guasto', 'Aldo Admin', $alReferente)],
    'intervento programmato' => [fn (bool $alReferente) => new InterventoProgrammato(test()->enteA->id, 'Ente A', test()->autoclave->id, 'Autoclave', 'Ente A › Chimica', 'Manutenzione', 'Taratura', '01/12/2026', 'Elio Esterno', 'Aldo Admin', $alReferente)],
    'intervento eseguito' => [fn (bool $alReferente) => new InterventoEseguito(test()->enteA->id, 'Ente A', test()->autoclave->id, 'Autoclave', 'Ente A › Chimica', 'Manutenzione', 'Taratura', '01/12/2026', 'Elio Esterno', false, null, $alReferente)],
]);

it('writes the name of the ente in the last line as plain text', function () {
    $html = (string) (new MacchinaSegnalata($this->enteA->id, 'Ente [A](https://evil.example)', $this->autoclave->id, 'Autoclave', 'Chimica', 'Strumento non idoneo', true, null, null, true))
        ->toMail(Referente::di($this->autoclave)->casella())
        ->render();

    expect($html)->not->toContain('href="https://evil.example');
});

// ─── Il riepilogo delle scadenze ─────────────────────────────────────────────

it('sends the referente a riepilogo with the scadenze of their machine, and of that one only', function () {
    ($this->inScadenza)($this->autoclave, 'Taratura annuale');
    ($this->inScadenza)($this->centrifuga, 'Cambio cinghia');

    ($this->scadenze)();

    Notification::assertSentOnDemand(
        DigestScadenze::class,
        fn (DigestScadenze $n, array $canali, object $casella) => $casella->routes === ['mail' => CASELLA_REFERENTE]
            // 🔴 Solo l'email: una casella non ha una campanella, e il canale
            // `database` su un destinatario senza account farebbe fallire l'invio.
            && $canali === ['mail']
            && $n->alReferente === true
            && $n->nomeReferente === 'Giulia Bianchi'
            && $n->enteId === $this->enteA->id
            && ($this->macchineIn)($n) === ['Autoclave'],
    );
    Notification::assertSentOnDemandTimes(DigestScadenze::class, 1);

    // Le persone del cliente ricevono il loro, com'era.
    Notification::assertSentTo($this->admin, fn (DigestScadenze $n) => $n->alReferente === false && count($n->righe) === 2);
    Notification::assertSentTo($this->respFisica, fn (DigestScadenze $n) => ($this->macchineIn)($n) === ['Centrifuga']);
});

it('puts in one riepilogo all the machines of the same referente', function () {
    $this->centrifuga->update(['referente_email' => 'GIULIA.BIANCHI@ospedale.test', 'referente_nome' => 'G.']);
    ($this->inScadenza)($this->autoclave);
    ($this->inScadenza)($this->centrifuga);

    ($this->scadenze)();

    $riepiloghi = ($this->aCasella)(DigestScadenze::class);

    expect($riepiloghi)->toHaveCount(1)
        ->and(($this->macchineIn)($riepiloghi->first()))->toEqualCanonicalizing(['Autoclave', 'Centrifuga']);
});

it('sends each referente their own machines when two machines have two referenti', function () {
    $this->centrifuga->update(['referente_email' => 'luca.verdi@ospedale.test', 'referente_nome' => 'Luca']);
    ($this->inScadenza)($this->autoclave);
    ($this->inScadenza)($this->centrifuga);

    ($this->scadenze)();

    expect(($this->macchineIn)(($this->aCasella)(DigestScadenze::class)->sole()))->toBe(['Autoclave'])
        ->and(($this->macchineIn)(($this->aCasella)(DigestScadenze::class, 'luca.verdi@ospedale.test')->sole()))->toBe(['Centrifuga'])
        ->and(($this->aCasella)(DigestScadenze::class, 'luca.verdi@ospedale.test')->sole()->nomeReferente)->toBe('Luca');
});

it('never tells a referente that a part was replaced on the machine', function () {
    // 🔴 ADR-029: la garanzia di un pezzo montato dice che su quella macchina
    // un pezzo è stato sostituito. Un indirizzo scritto su una scheda non ha
    // titolo per saperlo — qualunque cosa l'Ente abbia scelto per i propri Tenant.
    $this->enteA->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Lettura);

    $ricambio = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Lampada UV']);
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($this->autoclave)->forRicambio($ricambio)
        ->create(['data' => today()->subMonths(2)->toDateString()]);
    Garanzia::factory()->scadenzaDichiarata(today()->addDays(5)->toDateString())->forRicambio($utilizzo)->create();

    ($this->scadenze)();

    // La riga esiste, e l'Admin la riceve: è la sola del giorno.
    Notification::assertSentTo($this->admin, fn (DigestScadenze $n) => count($n->righe) === 1);
    Notification::assertSentOnDemandTimes(DigestScadenze::class, 0);

    // Con una scadenza della macchina accanto, al referente arriva quella sola.
    Garanzia::factory()->scadenzaDichiarata(today()->addDays(6)->toDateString())->forStrumento($this->autoclave)->create();
    Notification::fake();

    ($this->scadenze)();

    $riepilogo = ($this->aCasella)(DigestScadenze::class)->sole();

    expect($riepilogo->righe)->toHaveCount(1)
        ->and($riepilogo->righe[0]->tipo->value)->toBe('garanzia_macchina');
});

it('sends one riepilogo to a referente who is already a person of the client', function () {
    // Le maiuscole non fanno di una persona due destinatari, nemmeno qui.
    $this->admin->forceFill(['email' => 'Aldo.Admin@Ospedale.TEST'])->save();
    $this->autoclave->update(['referente_email' => 'aldo.admin@ospedale.test']);
    ($this->inScadenza)($this->autoclave);

    ($this->scadenze)();

    Notification::assertSentOnDemandTimes(DigestScadenze::class, 0);
    Notification::assertSentToTimes($this->admin->fresh(), DigestScadenze::class, 1);
    Notification::assertSentTo($this->admin->fresh(), fn (DigestScadenze $n) => $n->alReferente === false && count($n->righe) === 1);
});

it('adds the machine to the riepilogo of a responsabile named as its referente, without a second email', function () {
    ($this->referenteE)($this->respFisica);
    ($this->inScadenza)($this->autoclave);
    ($this->inScadenza)($this->centrifuga);
    // Un'altra macchina di Chimica, di cui Franco non è referente: resta fuori.
    ($this->inScadenza)(Strumento::factory()->forNode($this->chimica)->create(['nome' => 'Bilancia']));

    ($this->scadenze)();

    Notification::assertSentOnDemandTimes(DigestScadenze::class, 0);
    Notification::assertSentToTimes($this->respFisica, DigestScadenze::class, 1);
    Notification::assertSentTo(
        $this->respFisica,
        fn (DigestScadenze $n) => $n->alReferente === false && collect(($this->macchineIn)($n))->sort()->values()->all() === ['Autoclave', 'Centrifuga'],
    );
});

it('sends one riepilogo to the assigned tecnico who is also named as referente of the machine', function () {
    // Il tecnico esterno non ha un Ente: entra nel giro per gli interventi che
    // gli sono assegnati. Se è anche il referente, resta una persona sola.
    ($this->referenteE)($this->tecnico);
    Intervento::factory()->forStrumento($this->autoclave)->assegnatoA($this->tecnico)
        ->create(['data_scadenza' => today()->addDays(5)->toDateString()]);

    ($this->scadenze)();

    Notification::assertSentOnDemandTimes(DigestScadenze::class, 0);
    Notification::assertSentToTimes($this->tecnico, DigestScadenze::class, 1);
});

it('counts the referente among those it wrote to', function () {
    ($this->inScadenza)($this->autoclave);

    // Due Admin, il Tenant, la Responsabile di Chimica, e la casella di Giulia.
    $this->artisan('easylab:notifica-scadenze')
        ->expectsOutputToContain('a 5 destinatari')
        ->assertSuccessful();

    $this->autoclave->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    expect(AvvisiObsolescenza::perEnte($this->enteA->fresh())['destinatari'])->toBe(5);
});

it('does not let the referente channel hand a tenant the rows their ente hides', function () {
    // Il Tenant è anche referente della macchina: il canale del referente non
    // deve restituirgli la riga che il filtro di ADR-029 gli ha appena tolto.
    $this->enteA->fissaVisibilitaGaranzieRicambio(VisibilitaGaranzieRicambio::Nascosta);
    ($this->referenteE)($this->tenant);

    $ricambio = Ricambio::factory()->forTenant($this->enteA)->create(['nome' => 'Lampada UV']);
    $utilizzo = RicambioUtilizzo::factory()->forStrumento($this->autoclave)->forRicambio($ricambio)
        ->create(['data' => today()->subMonths(2)->toDateString()]);
    Garanzia::factory()->scadenzaDichiarata(today()->addDays(5)->toDateString())->forRicambio($utilizzo)->create();

    ($this->scadenze)();

    Notification::assertNotSentTo($this->tenant, DigestScadenze::class);
    Notification::assertSentOnDemandTimes(DigestScadenze::class, 0);
});

it('keeps the riepilogo of an ente inside that ente, also when the referente is the same person', function () {
    $adminB = $this->adminB;
    $laboratorioB = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create(['nome' => 'Genetica']);
    $sequenziatore = Strumento::factory()->forNode($laboratorioB)->create(['nome' => 'Sequenziatore', 'referente_email' => CASELLA_REFERENTE]);

    ($this->inScadenza)($this->autoclave);
    ($this->inScadenza)($sequenziatore);

    ($this->scadenze)();

    $riepiloghi = ($this->aCasella)(DigestScadenze::class);

    // Due email, una per Ente: mai un riepilogo che mescola due clienti.
    expect($riepiloghi)->toHaveCount(2)
        ->and($riepiloghi->map(fn (DigestScadenze $n) => [$n->enteNome, ($this->macchineIn)($n)])->sortBy(0)->values()->all())
        ->toBe([['Ente A', ['Autoclave']], ['Ente B', ['Sequenziatore']]]);

    Notification::assertSentTo($adminB, fn (DigestScadenze $n) => ($this->macchineIn)($n) === ['Sequenziatore']);
});

it('sends nothing to the referente on a run that only fills the log', function () {
    ($this->inScadenza)($this->autoclave);

    ($this->scadenze)(['--senza-invio' => true]);

    Notification::assertNothingSent();
});

it('leaves the referente out, person or address, while the riepilogo email is off', function () {
    // Il referente non ha una campanella: a email spenta non c'è niente da
    // lasciargli. E chi lo è da persona resta alle macchine dei suoi laboratori.
    InterruttoriEmail::imposta(CatalogoEmail::RIEPILOGO_SCADENZE, false);
    ($this->inScadenza)($this->autoclave);

    ($this->scadenze)();

    Notification::assertSentOnDemandTimes(DigestScadenze::class, 0);
    // Le persone ricevono comunque la copia in applicazione.
    Notification::assertSentTo($this->admin, fn (DigestScadenze $n, array $canali) => $canali === ['database']);

    ($this->referenteE)($this->respFisica);
    ($this->inScadenza)($this->autoclave, 'Seconda taratura');
    Notification::fake();

    ($this->scadenze)();

    Notification::assertNotSentTo($this->respFisica, DigestScadenze::class);
});

it('greets the referente by the name on the page, and tells them why the riepilogo arrives', function () {
    ($this->inScadenza)($this->autoclave);
    ($this->scadenze)();

    $riepilogo = ($this->aCasella)(DigestScadenze::class)->sole();
    $html = (string) $riepilogo->toMail(Referente::di($this->autoclave)->casella())->render();

    expect($html)
        ->toContain('Ciao Giulia Bianchi, ecco cosa è cambiato oggi sulle macchine di cui sei referente.')
        ->toContain('sei indicato come referente di queste macchine per Ente A')
        ->toContain('togliere il tuo indirizzo dalla loro scheda')
        ->not->toContain('/settings/notifiche');

    // Senza un nome sulla scheda non si saluta nessuno, e la frase regge lo stesso.
    $senzaNome = new DigestScadenze($this->enteA->id, 'Ente A', $riepilogo->righe, alReferente: true);

    expect((string) $senzaNome->toMail(Referente::di($this->autoclave)->casella())->render())
        ->toContain('Ecco cosa è cambiato oggi sulle macchine di cui sei referente.')
        ->not->toContain('Ciao');

    // A una persona del cliente, com'era.
    $perPersona = new DigestScadenze($this->enteA->id, 'Ente A', $riepilogo->righe);

    expect((string) $perPersona->toMail($this->tenant)->render())
        ->toContain('Ciao Tina Tenant, ecco cosa è cambiato oggi sulle macchine che segui.')
        ->toContain('/settings/notifiche')
        ->not->toContain('referente');
});

it('keeps both channels for a person and the email alone for an address', function () {
    $riepilogo = new DigestScadenze($this->enteA->id, 'Ente A', []);
    $casella = Referente::di($this->autoclave)->casella();

    expect($riepilogo->via($this->admin))->toBe(['database', 'mail'])
        ->and($riepilogo->via($casella))->toBe(['mail']);

    InterruttoriEmail::imposta(CatalogoEmail::RIEPILOGO_SCADENZE, false);

    expect($riepilogo->via($this->admin))->toBe(['database'])
        ->and($riepilogo->via($casella))->toBe([]);

    $avviso = new AvvisoObsolescenza($this->enteA->id, 'Ente A', 10, []);

    InterruttoriEmail::imposta(CatalogoEmail::AVVISO_OBSOLESCENZA, true);
    expect($avviso->via($this->admin))->toBe(['database', 'mail'])
        ->and($avviso->via($casella))->toBe(['mail']);

    InterruttoriEmail::imposta(CatalogoEmail::AVVISO_OBSOLESCENZA, false);
    expect($avviso->via($this->admin))->toBe(['database'])
        ->and($avviso->via($casella))->toBe([]);
});

// ─── L'avviso di obsolescenza ────────────────────────────────────────────────

it('tells the referente that their machine went over the age threshold, and that one only', function () {
    $this->autoclave->update(['data_installazione' => today()->subYears(12)->toDateString()]);
    $this->centrifuga->update(['data_installazione' => today()->subYears(15)->toDateString()]);

    ($this->obsolescenza)();

    Notification::assertSentOnDemand(
        AvvisoObsolescenza::class,
        fn (AvvisoObsolescenza $n, array $canali, object $casella) => $casella->routes === ['mail' => CASELLA_REFERENTE]
            && $canali === ['mail']
            && $n->alReferente === true
            && $n->nomeReferente === 'Giulia Bianchi'
            && ($this->macchineIn)($n) === ['Autoclave'],
    );
    Notification::assertSentOnDemandTimes(AvvisoObsolescenza::class, 1);
    Notification::assertSentTo($this->admin, fn (AvvisoObsolescenza $n) => $n->alReferente === false && count($n->righe) === 2);
    Notification::assertSentTo($this->respFisica, fn (AvvisoObsolescenza $n) => ($this->macchineIn)($n) === ['Centrifuga']);
});

it('adds the obsolete machine to the avviso of a responsabile named as its referente, without a second email', function () {
    ($this->referenteE)($this->respFisica);
    $this->autoclave->update(['data_installazione' => today()->subYears(12)->toDateString()]);
    $this->centrifuga->update(['data_installazione' => today()->subYears(15)->toDateString()]);
    Strumento::factory()->forNode($this->chimica)->create(['nome' => 'Bilancia', 'data_installazione' => today()->subYears(20)->toDateString()]);

    ($this->obsolescenza)();

    Notification::assertSentOnDemandTimes(AvvisoObsolescenza::class, 0);
    Notification::assertSentToTimes($this->respFisica, AvvisoObsolescenza::class, 1);
    Notification::assertSentTo(
        $this->respFisica,
        fn (AvvisoObsolescenza $n) => collect(($this->macchineIn)($n))->sort()->values()->all() === ['Autoclave', 'Centrifuga'],
    );
    // 🔴 Chi è Responsabile di Chimica non riceve la Centrifuga per questo.
    Notification::assertSentTo(
        $this->respChimica,
        fn (AvvisoObsolescenza $n) => collect(($this->macchineIn)($n))->sort()->values()->all() === ['Autoclave', 'Bilancia'],
    );
});

it('sends one avviso to a referente of two obsolete machines, and none on a run that only fills the log', function () {
    $this->centrifuga->update(['referente_email' => CASELLA_REFERENTE, 'data_installazione' => today()->subYears(15)->toDateString()]);
    $this->autoclave->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    ($this->obsolescenza)(['--senza-invio' => true]);
    Notification::assertNothingSent();

    // Il registro è stato riempito: per rivedere l'avviso servono macchine nuove.
    $this->autoclave->update(['data_installazione' => today()->subYears(13)->toDateString()]);
    $this->centrifuga->update(['data_installazione' => today()->subYears(16)->toDateString()]);

    ($this->obsolescenza)();

    $avvisi = ($this->aCasella)(AvvisoObsolescenza::class);

    expect($avvisi)->toHaveCount(1)
        ->and(($this->macchineIn)($avvisi->first()))->toEqualCanonicalizing(['Autoclave', 'Centrifuga']);
});

it('leaves the referente out of the avviso, person or address, while that email is off', function () {
    InterruttoriEmail::imposta(CatalogoEmail::AVVISO_OBSOLESCENZA, false);
    $this->autoclave->update(['data_installazione' => today()->subYears(12)->toDateString()]);

    ($this->obsolescenza)();

    Notification::assertSentOnDemandTimes(AvvisoObsolescenza::class, 0);
    Notification::assertSentTo($this->admin, AvvisoObsolescenza::class);

    ($this->referenteE)($this->respFisica);
    $this->autoclave->update(['data_installazione' => today()->subYears(13)->toDateString()]);
    Notification::fake();

    ($this->obsolescenza)();

    Notification::assertNotSentTo($this->respFisica, AvvisoObsolescenza::class);
});

it('greets the referente by name in the avviso, and names the ente instead of calling it theirs', function () {
    $riga = RigaObsolescenza::daStrumento($this->autoclave->fill(['data_installazione' => today()->subYears(12)->toDateString()]));
    $casella = Referente::di($this->autoclave)->casella();

    $html = (string) (new AvvisoObsolescenza($this->enteA->id, 'Ente A', 10, [$riga], alReferente: true, nomeReferente: 'Giulia Bianchi'))->toMail($casella)->render();

    expect(preg_replace('/\s+/', ' ', $html))
        ->toContain("Ciao Giulia Bianchi, la soglia di obsolescenza di Ente A è di 10 anni: queste macchine, di cui sei referente, l'hanno appena superata.")
        ->toContain('sei indicato come referente di queste macchine per Ente A')
        ->not->toContain('/settings/notifiche');

    $html = (string) (new AvvisoObsolescenza($this->enteA->id, 'Ente A', 10, [$riga], alReferente: true))->toMail($casella)->render();

    expect(preg_replace('/\s+/', ' ', $html))
        ->toContain('La soglia di obsolescenza di Ente A è di 10 anni')
        ->not->toContain('Ciao');

    $html = (string) (new AvvisoObsolescenza($this->enteA->id, 'Ente A', 10, [$riga]))->toMail($this->tenant)->render();

    expect(preg_replace('/\s+/', ' ', $html))
        ->toContain('Ciao Tina Tenant, la soglia di obsolescenza del tuo Ente è di 10 anni')
        ->toContain('/settings/notifiche')
        ->not->toContain('referente');
});

// ─── La regola, guardata da sola ─────────────────────────────────────────────

it('answers who receives an email about a machine with the people and the referente, never the same address twice', function () {
    $nomi = fn (array $esito) => collect($esito['persone'])->pluck('name')->sort()->values()->all();

    $esito = DestinatariEnte::dellaMacchina($this->autoclave, $this->admin);

    expect($nomi($esito))->toBe(['Anna Admin', 'Rita Chimica', 'Tina Tenant'])
        ->and($esito['referente']?->email)->toBe(CASELLA_REFERENTE);

    // Senza un attore (un comando, un job) nessuno è escluso.
    expect($nomi(DestinatariEnte::dellaMacchina($this->autoclave)))->toBe(['Aldo Admin', 'Anna Admin', 'Rita Chimica', 'Tina Tenant']);

    // La Centrifuga non ha un referente: le sole persone, e chi segue Fisica.
    $esito = DestinatariEnte::dellaMacchina($this->centrifuga, $this->admin);

    expect($nomi($esito))->toBe(['Anna Admin', 'Franco Fisica', 'Tina Tenant'])
        ->and($esito['referente'])->toBeNull();
});

// ─── In coda durante il rilascio ─────────────────────────────────────────────

it('still delivers an email that was queued before the referente existed', function (Closure $notifica, array $campi) {
    // 🔴 Una notifica accodata PRIMA del rilascio non porta i campi nuovi nel
    // proprio payload, e `unserialize` non passa dal costruttore: se fossero
    // parametri promossi senza un default di proprietà resterebbero non
    // inizializzati, e l'email in coda in quel momento fallirebbe leggendoli.
    //
    // Si prende il payload di oggi e gli si tolgono i campi nuovi, se li porta:
    // `SerializesModels` non scrive una proprietà che vale il proprio default,
    // quindi con la forma giusta non ci sono già — ed è la prova che il
    // payload di ieri e quello di oggi sono lo stesso.
    $serializzata = serialize($notifica());

    foreach ($campi as $campo) {
        $serializzata = preg_replace('/s:\d+:"'.$campo.'";(?:b:[01]|N|s:\d+:"[^"]*");/', '', $serializzata, 1, $tolti);

        if ($tolti === 1) {
            $serializzata = preg_replace_callback('/^(O:\d+:"[^"]+":)(\d+)(:\{)/', fn (array $m) => $m[1].($m[2] - 1).$m[3], $serializzata);
        }

        expect($serializzata)->not->toContain('"'.$campo.'"');
    }

    $diPrima = unserialize($serializzata);

    expect($diPrima->alReferente)->toBeFalse()
        ->and((string) $diPrima->toMail($this->tenant)->render())
        ->toContain('/settings/notifiche')
        ->not->toContain('referente');
})->with([
    'macchina segnalata' => [fn () => new MacchinaSegnalata(test()->enteA->id, 'Ente A', test()->autoclave->id, 'Autoclave', 'Chimica', 'Strumento non idoneo', true), ['alReferente']],
    'intervento programmato' => [fn () => new InterventoProgrammato(test()->enteA->id, 'Ente A', test()->autoclave->id, 'Autoclave', 'Chimica', 'Manutenzione', 'Taratura', '01/12/2026'), ['alReferente']],
    'intervento eseguito' => [fn () => new InterventoEseguito(test()->enteA->id, 'Ente A', test()->autoclave->id, 'Autoclave', 'Chimica', 'Manutenzione', 'Taratura', '01/12/2026'), ['alReferente']],
    'riepilogo scadenze' => [fn () => new DigestScadenze(test()->enteA->id, 'Ente A', []), ['alReferente', 'nomeReferente']],
    'avviso obsolescenza' => [fn () => new AvvisoObsolescenza(test()->enteA->id, 'Ente A', 10, []), ['alReferente', 'nomeReferente']],
]);

// ─── Consegnate davvero ──────────────────────────────────────────────────────
//
// Fin qui la finta di `Notification` ha detto «a chi» e «con che cosa». Queste
// tolgono la finta: provano che una casella senza account regge tutta la strada
// fino al trasporto, e che nessuno dei canali le chiede ciò che non ha.

it('really delivers the flagged machine to the address of the referente, next to the people of the client', function () {
    ($this->accendi)(CatalogoEmail::MACCHINA_SEGNALATA);
    ($this->perDavvero)();

    ($this->segnala)($this->admin);

    $spedite = ($this->spedite)();

    expect($spedite->keys()->sort()->values()->all())
        ->toBe(collect([CASELLA_REFERENTE, $this->altroAdmin->email, $this->tenant->email, $this->respChimica->email])->sort()->values()->all())
        ->and($spedite[CASELLA_REFERENTE])->toHaveCount(1)
        ->and($spedite[CASELLA_REFERENTE][0]->getSubject())->toBe('Easy Lab · Ente A: Autoclave non è idonea')
        ->and($spedite[CASELLA_REFERENTE][0]->getHtmlBody())
        ->toContain('Guasto alla pompa')
        ->toContain('sei indicato come referente di questa macchina per Ente A')
        ->and($spedite[$this->tenant->email][0]->getHtmlBody())
        ->toContain('preferenze notifiche')
        ->not->toContain('referente');
});

it('really delivers the riepilogo and the avviso to the address of the referente, and leaves no in-app copy for it', function () {
    ($this->inScadenza)($this->autoclave, 'Taratura annuale');
    $this->autoclave->update(['data_installazione' => today()->subYears(12)->toDateString()]);
    ($this->perDavvero)();

    ($this->scadenze)();
    ($this->obsolescenza)();

    $perReferente = ($this->spedite)()[CASELLA_REFERENTE];

    expect($perReferente)->toHaveCount(2)
        ->and($perReferente[0]->getHtmlBody())
        ->toContain('Autoclave')
        ->toContain('sulle macchine di cui sei referente')
        ->and($perReferente[1]->getHtmlBody())
        ->toContain('Autoclave')
        ->toContain('queste macchine, di cui sei')
        // 🔴 La campanella è delle persone: per una casella non si scrive nulla,
        // e l'invio non si è rotto provandoci.
        ->and(DB::table('notifications')->where('notifiable_type', '!=', (new User)->getMorphClass())->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $this->admin->id)->count())->toBe(2);
});

<?php

use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InterventoAssegnato;
use App\Notifications\InterventoEseguito;
use App\Notifications\InterventoProgrammato;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * Le email che seguono un gesto su un intervento: programmato, eseguito,
 * assegnato (🔗 ADR-047).
 *
 * **Area rossa**: qui si decide cosa esce dall'applicazione e verso chi. Un
 * destinatario di troppo è il dato di un cliente nella casella di un altro —
 * o il lavoro di un reparto raccontato al responsabile di quello accanto.
 *
 * Mondo: l'Ente A con due reparti (la macchina sta in Chimica) e sei persone;
 * l'Ente B, che fa da controprova; un tecnico e un gestore di EasyLab con
 * l'Ente A in portafoglio.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->chimica = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Chimica']);
    $this->fisica = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create(['nome' => 'Fisica']);
    $this->autoclave = Strumento::factory()->forNode($this->chimica)->create(['nome' => 'Autoclave']);

    $enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);

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

    $this->adminB = $persona('Bruno B', 'Admin', $enteB);

    $this->tecnico = $persona('Elio Esterno', 'Tecnico', null);
    $this->tecnico->portafoglioClienti()->attach($this->enteA);
    $this->gestore = $persona('Giorgia Gestore', 'Gestore', null);
    $this->gestore->portafoglioClienti()->attach($this->enteA);

    Notification::fake();

    $this->accendi = fn (string ...$chiavi) => array_map(fn ($c) => InterruttoriEmail::imposta($c, true), $chiavi);

    // Pianifica un intervento dalla scheda, come farebbe una persona.
    $this->pianifica = function (User $chi, array $campi = []) {
        $pagina = scheda($chi, $this->autoclave)->call('openNuovoIntervento');

        foreach ($campi + [
            'descrizione' => 'Taratura annuale',
            'tipo' => 'manutenzione_ordinaria',
            'data_scadenza' => today()->addMonth()->toDateString(),
            // Per chi può assegnare l'assegnatario è obbligatorio.
            'tecnico_id' => $this->tecnico->id,
        ] as $campo => $valore) {
            $pagina->set("interventoForm.{$campo}", $valore);
        }

        return $pagina->call('saveIntervento')->assertHasNoErrors();
    };
});

// ─── Spente finché qualcuno non le accende ───────────────────────────────────

it('sends nothing at all while the three emails are off', function () {
    // 🔴 Lo stato di nascita: un deploy non comincia a scrivere ai clienti.
    ($this->pianifica)($this->admin, ['tecnico_id' => $this->tecnico->id]);

    $intervento = Intervento::withoutGlobalScopes()->firstOrFail();

    scheda($this->admin, $this->autoclave)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->toDateString())
        ->call('completa')
        ->assertHasNoErrors();

    Notification::assertNothingSent();
});

// ─── Programmato ─────────────────────────────────────────────────────────────

it('tells the people of the client that an intervento was planned, and nobody else', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO);

    ($this->pianifica)($this->admin, ['tecnico_id' => $this->tecnico->id]);

    Notification::assertSentTo([$this->altroAdmin, $this->tenant, $this->respChimica], InterventoProgrammato::class);

    // Chi l'ha pianificato lo sa già.
    Notification::assertNotSentTo($this->admin, InterventoProgrammato::class);
    // 🔴 Il responsabile del reparto accanto: l'email non scavalca il suo sotto-albero.
    Notification::assertNotSentTo($this->respFisica, InterventoProgrammato::class);
    // 🔴 Un altro cliente.
    Notification::assertNotSentTo($this->adminB, InterventoProgrammato::class);
    // I tecnici non sono «il cliente»: per loro c'è l'email di assegnazione.
    Notification::assertNotSentTo([$this->tecnico, $this->tecnicoInterno, $this->gestore], InterventoProgrammato::class);
});

it('writes in the email what was planned, where, by whom and for whom', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO);

    ($this->pianifica)($this->admin, ['tecnico_id' => $this->tecnico->id]);

    Notification::assertSentTo($this->tenant, function (InterventoProgrammato $n) {
        return $n->enteId === $this->enteA->id
            && $n->enteNome === 'Ente A'
            && $n->strumentoId === $this->autoclave->id
            && $n->strumentoNome === 'Autoclave'
            && str_contains($n->ubicazione, 'Chimica')
            && $n->tipo === 'Manutenzione ordinaria'
            && $n->descrizione === 'Taratura annuale'
            && $n->scadenza === today()->addMonth()->format('d/m/Y')
            && $n->assegnatario === 'Elio Esterno'
            && $n->autore === 'Aldo Admin';
    });
});

it('tells the client when easylab plans the work, with the name of who did it', function () {
    // Il caso per cui l'email esiste: il gestore di EasyLab lavora sulle
    // macchine del cliente, e il cliente lo viene a sapere.
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO);

    ($this->pianifica)($this->gestore);

    Notification::assertSentTo(
        [$this->admin, $this->altroAdmin, $this->tenant, $this->respChimica],
        fn (InterventoProgrammato $n) => $n->autore === 'Giorgia Gestore',
    );
});

it('does not announce the record of a job already done in the past', function () {
    // L'inserimento storico del backlog non è né un programma né
    // un'esecuzione di oggi.
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO, CatalogoEmail::INTERVENTO_ESEGUITO, CatalogoEmail::INTERVENTO_ASSEGNATO);

    ($this->pianifica)($this->admin, [
        'data_scadenza' => '2024-05-28',
        'gia_eseguito' => true,
        'data_esecuzione' => '2024-05-30',
        'tecnico_id' => $this->tecnico->id,
    ]);

    Notification::assertNothingSent();
});

it('does not announce again an intervento that is only edited', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO);

    $intervento = Intervento::factory()->forStrumento($this->autoclave)->create([
        'descrizione' => 'Da correggere', 'tecnico_id' => $this->tecnico->id,
    ]);

    scheda($this->admin, $this->autoclave)
        ->call('openModificaIntervento', $intervento->id)
        ->set('interventoForm.descrizione', 'Corretta')
        ->call('saveIntervento')
        ->assertHasNoErrors();

    Notification::assertNothingSent();
});

it('does not send the email to who gave it up, and keeps sending it to the others', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO);

    $this->tenant->forceFill(['riceve_email_interventi_programmati' => false])->save();

    ($this->pianifica)($this->admin);

    Notification::assertNotSentTo($this->tenant, InterventoProgrammato::class);
    Notification::assertSentTo($this->altroAdmin, InterventoProgrammato::class);
});

// ─── Eseguito ────────────────────────────────────────────────────────────────

it('tells the people of the client that an intervento was carried out, and nobody else', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ESEGUITO);

    $intervento = Intervento::factory()->forStrumento($this->autoclave)->create([
        'descrizione' => 'Sostituzione guarnizioni', 'tecnico_id' => $this->tecnico->id,
    ]);

    // Lo chiude il tecnico di EasyLab: è il cliente a doverlo sapere.
    scheda($this->tecnico, $this->autoclave)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->toDateString())
        ->set('reportFineLavoro', 'Guarnizioni sostituite, prova di tenuta superata.')
        ->call('completa')
        ->assertHasNoErrors();

    Notification::assertSentTo(
        [$this->admin, $this->altroAdmin, $this->tenant, $this->respChimica],
        fn (InterventoEseguito $n) => $n->enteNome === 'Ente A'
            && $n->strumentoNome === 'Autoclave'
            && $n->descrizione === 'Sostituzione guarnizioni'
            && $n->eseguitoIl === today()->format('d/m/Y')
            && $n->autore === 'Elio Esterno'
            && $n->conReport === true
            && $n->prossima === null,
    );

    Notification::assertNotSentTo([$this->respFisica, $this->adminB, $this->tecnico, $this->gestore], InterventoEseguito::class);
});

it('never puts the work report in the email', function () {
    // Il report è testo libero, scritto per chi apre la scheda dietro il
    // login: l'email dice che c'è, non cosa dice.
    ($this->accendi)(CatalogoEmail::INTERVENTO_ESEGUITO);

    $intervento = Intervento::factory()->forStrumento($this->autoclave)->create();

    scheda($this->tecnico, $this->autoclave)
        ->call('openCompleta', $intervento->id)
        ->set('dataEsecuzione', today()->toDateString())
        ->set('reportFineLavoro', 'Dettaglio riservato del lavoro')
        ->call('completa');

    Notification::assertSentTo($this->tenant, function (InterventoEseguito $n) {
        $html = (string) $n->toMail($this->tenant)->render();

        return ! str_contains($html, 'Dettaglio riservato del lavoro')
            && str_contains($html, 'Il report di fine lavoro si legge nella scheda');
    });
});

it('announces the next taratura inside the same email, not with a second one', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ESEGUITO, CatalogoEmail::INTERVENTO_PROGRAMMATO);

    $taratura = Intervento::factory()->forStrumento($this->autoclave)->create(['tipo' => 'taratura_e_certificazione']);

    scheda($this->admin, $this->autoclave)
        ->call('openCompleta', $taratura->id)
        ->set('dataEsecuzione', today()->toDateString())
        ->set('pianificaProssimaTaratura', true)
        ->set('mesiProssimaTaratura', 12)
        ->call('completa')
        ->assertHasNoErrors();

    $successiva = Intervento::withoutGlobalScopes()->where('id', '!=', $taratura->id)->firstOrFail();

    Notification::assertSentTo($this->tenant, fn (InterventoEseguito $n) => $n->prossima === $successiva->data_scadenza->format('d/m/Y')
        // Nessun report scritto: l'email non deve promettere di trovarne uno.
        && $n->conReport === false
        && ! str_contains((string) $n->toMail($this->tenant)->render(), 'report di fine lavoro'));
    Notification::assertNotSentTo($this->tenant, InterventoProgrammato::class);
});

it('does not send the eseguito email to who gave it up', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ESEGUITO);

    $this->altroAdmin->forceFill(['riceve_email_interventi_eseguiti' => false])->save();
    $intervento = Intervento::factory()->forStrumento($this->autoclave)->create();

    scheda($this->admin, $this->autoclave)
        ->call('openCompleta', $intervento->id)->set('dataEsecuzione', today()->toDateString())->call('completa');

    Notification::assertNotSentTo($this->altroAdmin, InterventoEseguito::class);
    Notification::assertSentTo($this->tenant, InterventoEseguito::class);
});

// ─── Assegnato ───────────────────────────────────────────────────────────────

it('tells a tecnico that an intervento was assigned, the moment it happens', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ASSEGNATO);

    ($this->pianifica)($this->admin, ['tecnico_id' => $this->tecnico->id]);

    Notification::assertSentTo($this->tecnico, fn (InterventoAssegnato $n) => $n->enteNome === 'Ente A'
        && $n->strumentoNome === 'Autoclave'
        && str_contains($n->ubicazione, 'Chimica')
        && $n->descrizione === 'Taratura annuale'
        && $n->scadenza === today()->addMonth()->format('d/m/Y')
        && $n->assegnatoDa === 'Aldo Admin');

    // Solo a lui: non è un'email del cliente.
    Notification::assertNotSentTo([$this->admin, $this->altroAdmin, $this->tenant, $this->tecnicoInterno], InterventoAssegnato::class);
});

it('tells the new person when an intervento passes from one to another, and not the old one', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ASSEGNATO);

    $intervento = Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $this->tecnico->id]);

    scheda($this->admin, $this->autoclave)
        ->call('openModificaIntervento', $intervento->id)
        ->set('interventoForm.tecnico_id', $this->tecnicoInterno->id)
        ->call('saveIntervento')
        ->assertHasNoErrors();

    Notification::assertSentTo($this->tecnicoInterno, InterventoAssegnato::class);
    Notification::assertNotSentTo($this->tecnico, InterventoAssegnato::class);
});

it('does not write again to a tecnico when the edit leaves the assignment alone', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ASSEGNATO);

    $intervento = Intervento::factory()->forStrumento($this->autoclave)->create(['tecnico_id' => $this->tecnico->id]);

    scheda($this->admin, $this->autoclave)
        ->call('openModificaIntervento', $intervento->id)
        ->set('interventoForm.descrizione', 'Solo la descrizione')
        ->call('saveIntervento')
        ->assertHasNoErrors();

    Notification::assertNothingSent();
});

it('does not write to somebody who took the intervento on themselves', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ASSEGNATO);

    ($this->pianifica)($this->gestore, ['tecnico_id' => $this->gestore->id]);

    expect(Intervento::withoutGlobalScopes()->firstOrFail()->tecnico_id)->toBe($this->gestore->id);

    Notification::assertNothingSent();
});

it('does not send the assignment email to who gave it up', function () {
    ($this->accendi)(CatalogoEmail::INTERVENTO_ASSEGNATO);

    $this->tecnico->forceFill(['riceve_email_interventi_assegnati' => false])->save();

    ($this->pianifica)($this->admin, ['tecnico_id' => $this->tecnico->id]);

    Notification::assertNotSentTo($this->tecnico, InterventoAssegnato::class);
});

// ─── Ciò che non deve dipendere dall'email ───────────────────────────────────

it('saves and closes the intervento even when the email cannot be queued', function () {
    // 🔴 Una coda irraggiungibile non deve trasformare un lavoro registrato in
    // una pagina di errore.
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO, CatalogoEmail::INTERVENTO_ESEGUITO, CatalogoEmail::INTERVENTO_ASSEGNATO);

    $this->app->bind(Dispatcher::class, fn () => new class
    {
        public function send(mixed ...$argomenti): void
        {
            throw new RuntimeException('coda irraggiungibile');
        }
    });

    ($this->pianifica)($this->admin, ['tecnico_id' => $this->tecnico->id]);

    $intervento = Intervento::withoutGlobalScopes()->firstOrFail();

    scheda($this->admin, $this->autoclave)
        ->call('openCompleta', $intervento->id)->set('dataEsecuzione', today()->toDateString())->call('completa')
        ->assertHasNoErrors();

    expect($intervento->fresh()->data_esecuzione)->not->toBeNull();
});

it('builds each intervento email with the brand and the name of the ente in its payload, whatever the context on the worker', function () {
    // 🔴 Sul worker non c'è l'utente che ha compiuto il gesto, e se per caso ce
    // n'è uno non c'entra: qui è l'Admin di un ALTRO cliente. Il marchio e il
    // nome vengono dall'id nel payload, e da nient'altro.
    $this->enteA->forceFill(['marchio_colore' => '#aa0000'])->save();
    ($this->accendi)(CatalogoEmail::INTERVENTO_PROGRAMMATO, CatalogoEmail::INTERVENTO_ESEGUITO, CatalogoEmail::INTERVENTO_ASSEGNATO);

    ($this->pianifica)($this->admin, ['tecnico_id' => $this->tecnico->id]);

    $intervento = Intervento::withoutGlobalScopes()->firstOrFail();
    scheda($this->admin, $this->autoclave)
        ->call('openCompleta', $intervento->id)->set('dataEsecuzione', today()->toDateString())->call('completa');

    $this->actingAs($this->adminB);

    $resa = function (string $classe, User $a): array {
        $notifica = Notification::sent($a, $classe)->first();
        $messaggio = $notifica->toMail($a);

        return [$messaggio->viewData['marchio'], (string) $messaggio->render()];
    };

    foreach ([
        [InterventoProgrammato::class, $this->tenant],
        [InterventoEseguito::class, $this->tenant],
        [InterventoAssegnato::class, $this->tecnico],
    ] as [$classe, $a]) {
        [$marchio, $html] = $resa($classe, $a);

        expect($marchio->nome)->toBe('Ente A')
            ->and($marchio->colore)->toBe('#aa0000')
            ->and($html)->toContain('Autoclave')
            ->and($html)->not->toContain('Ente B');
    }
});

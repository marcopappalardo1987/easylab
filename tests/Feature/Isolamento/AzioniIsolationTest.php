<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Fornitori\ElencoFornitori;
use App\Livewire\Ricambi\RicercaRicambi;
use App\Livewire\Strumenti\SchedaStrumento;
use App\Livewire\Utenti\ElencoUtenti;
use App\Models\Strumento;
use App\Models\User;
use App\Notifications\InvitoUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Isolamento\Support\MondoDueEnti;
use Tests\Feature\Isolamento\Support\Tentativo;

/**
 * Isolamento multi-tenant sulle azioni di MODIFICA ed ELIMINAZIONE
 * (ADR-001/018). Nessuna property di questi componenti è `#[Locked]`: ogni id
 * è scritto dal client, quindi il vettore è doppio — l'argomento dell'azione e
 * la property manomessa prima di un'azione senza argomenti. Entrambi devono
 * cadere nello scope: la riga dell'Ente B resta identica e il suo testo non
 * esce nella risposta.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();
    $this->adminA = $this->mondo->riga('A', 'admin');
});

function schedaA(): Testable
{
    return Livewire::actingAs(test()->adminA)
        ->test(SchedaStrumento::class, ['strumento' => test()->mondo->riga('A', 'strumento')]);
}

// --- Fornitore -----------------------------------------------------------

it('refuses to open a fornitore of another Ente for editing', function () {
    $b = $this->mondo->riga('B', 'fornitore');
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(ElencoFornitori::class)->call('edit', $b->id));
});

it('refuses to save onto a fornitore of another Ente through a tampered editingId', function () {
    $b = $this->mondo->riga('B', 'fornitore');
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(ElencoFornitori::class)
        ->set('editingId', $b->id)
        ->set('form.ragione_sociale', 'Sovrascritto da A')
        ->call('save'));
});

it('refuses to delete a fornitore of another Ente, by argument or by tampered deletingId', function () {
    $b = $this->mondo->riga('B', 'fornitore');
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(ElencoFornitori::class)->call('confermaElimina', $b->id)->call('elimina'));
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(ElencoFornitori::class)->set('deletingId', $b->id)->call('elimina'));
});

// --- Intervento (dalla scheda di una macchina di A) --------------------------

it('refuses to open, complete, reopen or delete an intervento of another Ente', function (string $azione) {
    $b = $this->mondo->riga('B', 'intervento');
    Tentativo::senzaEffetto($b, fn () => schedaA()->call($azione, $b->id));
})->with(['openModificaIntervento', 'openCompleta', 'riapri', 'openEliminaIntervento']);

it('refuses to act on an intervento of another Ente through a tampered property', function (string $property, string $azione) {
    $b = $this->mondo->riga('B', 'intervento');
    Tentativo::senzaEffetto($b, fn () => schedaA()
        ->set('interventoForm', [
            'descrizione' => 'Sovrascritto da A', 'tipo' => 'manutenzione_ordinaria',
            'data_scadenza' => now()->addMonth()->toDateString(), 'tecnico_id' => null,
            'gia_eseguito' => false, 'data_esecuzione' => '',
        ])
        ->set('dataEsecuzione', now()->toDateString())
        ->set($property, $b->id)
        ->call($azione));
})->with([
    'modifica' => ['editingInterventoId', 'saveIntervento'],
    'completamento' => ['completingInterventoId', 'completa'],
    'eliminazione' => ['deletingInterventoId', 'eliminaIntervento'],
]);

it('refuses an intervento of the SAME Ente that belongs to another machine', function () {
    // Stesso tenant, altro strumento: la scheda risolve dalla relazione, non dal modello nudo.
    $altro = $this->mondo->riga('A', 'interventoAltroReparto');
    Tentativo::senzaEffetto($altro, fn () => schedaA()->set('deletingInterventoId', $altro->id)->call('eliminaIntervento'));
});

// --- Garanzia ------------------------------------------------------------

it('refuses to edit or delete a garanzia of another Ente', function () {
    $b = $this->mondo->riga('B', 'garanzia');
    Tentativo::senzaEffetto($b, fn () => schedaA()->call('openModificaGaranzia', $b->id));
    Tentativo::senzaEffetto($b, fn () => schedaA()->call('openEliminaGaranzia', $b->id)->call('eliminaGaranzia'));
    Tentativo::senzaEffetto($b, fn () => schedaA()->set('deletingGaranziaId', $b->id)->call('eliminaGaranzia'));
    Tentativo::senzaEffetto($b, fn () => schedaA()
        ->set('editingGaranziaId', $b->id)
        ->set('garanziaForm', ['data_inizio' => '2020-01-01', 'durata_mesi' => 1])
        ->call('saveGaranzia'));
});

// --- Documento -----------------------------------------------------------

it('refuses to delete a documento of another Ente', function () {
    $b = $this->mondo->riga('B', 'documento');
    Tentativo::senzaEffetto($b, fn () => schedaA()->call('openEliminaDocumento', $b->id)->call('eliminaDocumento'));
    Tentativo::senzaEffetto($b, fn () => schedaA()->set('deletingDocumentoId', $b->id)->call('eliminaDocumento'));
});

it('refuses to attach an upload to an intervento of another Ente', function () {
    $b = $this->mondo->riga('B', 'intervento');
    Tentativo::senzaEffetto($b, fn () => schedaA()->call('openCaricaDocumento', $b->id));
});

// --- RicambioUtilizzo / Ricambio -----------------------------------------------

it('refuses to correct or remove a ricambio montato of another Ente', function () {
    $b = $this->mondo->riga('B', 'utilizzo');
    Tentativo::senzaEffetto($b, fn () => schedaA()->call('openCorreggiRicambio', $b->id));
    Tentativo::senzaEffetto($b, fn () => schedaA()->call('openRimuoviRicambio', $b->id)->call('rimuoviRicambio'));
    Tentativo::senzaEffetto($b, fn () => schedaA()->set('deletingUtilizzoId', $b->id)->call('rimuoviRicambio'));
    Tentativo::senzaEffetto($b, fn () => schedaA()->set('editingUtilizzoId', $b->id)->call('segnaNonMontato'));
});

it('refuses to merge a ricambio into, or out of, the catalogue of another Ente', function () {
    $b = $this->mondo->riga('B', 'ricambio');
    $a = $this->mondo->riga('A', 'ricambio');

    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(RicercaRicambi::class)->call('apriUnione', $b->id));
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(RicercaRicambi::class)
        ->call('apriUnione', $a->id)->set('destinazioneId', $b->id)->call('unisci'));
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(RicercaRicambi::class)
        ->set('unendoId', $b->id)->set('destinazioneId', $a->id)->call('unisci'));
});

// --- Strumento / SpostamentoStrumento ------------------------------------------

it('refuses to move a machine into a node of another Ente', function () {
    $repartoB = $this->mondo->riga('B', 'reparto');
    $strumentoA = $this->mondo->riga('A', 'strumento');

    Tentativo::senzaEffetto($strumentoA, fn () => schedaA()
        ->call('openMove')
        ->set('destinazioneId', $repartoB->id)
        ->set('dataSpostamento', now()->toDateString())
        ->call('move'));
});

it('refuses to link a machine to a fornitore of another Ente', function () {
    $fornitoreB = $this->mondo->riga('B', 'fornitore');
    $strumentoA = $this->mondo->riga('A', 'strumento');

    Tentativo::senzaEffetto($strumentoA, fn () => schedaA()
        ->call('edit')
        ->set('strumentoForm.fornitore_id', $fornitoreB->id)
        ->call('save'));

    expect($strumentoA->fresh()->fornitore_id)->not->toBe($fornitoreB->id);
});

// --- UnitaOrganizzativa ----------------------------------------------------------

it('refuses to open, edit, delete or add under a node of another Ente', function () {
    $b = $this->mondo->riga('B', 'reparto');
    $albero = fn () => Livewire::actingAs($this->adminA)->test(Albero::class);

    Tentativo::senzaEffetto($b, fn () => $albero()->call('open', $b->id));
    Tentativo::senzaEffetto($b, fn () => $albero()->call('goTo', $b->id));
    Tentativo::senzaEffetto($b, fn () => $albero()->call('edit', $b->id));
    Tentativo::senzaEffetto($b, fn () => $albero()->set('editingId', $b->id)->set('nome', 'Sovrascritto da A')->call('save'));
    Tentativo::senzaEffetto($b, fn () => $albero()->call('confirmDelete', $b->id)->call('delete'));
    Tentativo::senzaEffetto($b, fn () => $albero()->set('deletingId', $b->id)->call('delete'));
    Tentativo::senzaEffetto($b, fn () => $albero()->call('addChild', $b->id));
    Tentativo::senzaEffetto($b, fn () => $albero()
        ->set('parentId', $b->id)->set('tipo', 'sottolaboratorio')->set('nome', 'Figlio forgiato')->call('save'));
});

it('never creates a child node under a node of another Ente', function () {
    $b = $this->mondo->riga('B', 'reparto');

    try {
        Livewire::actingAs($this->adminA)->test(Albero::class)
            ->set('parentId', $b->id)->set('tipo', 'sottolaboratorio')->set('nome', 'Figlio forgiato')->call('save');
    } catch (ModelNotFoundException) {
    }

    $this->assertDatabaseMissing('unita_organizzativa', ['parent_id' => $b->id, 'nome' => 'Figlio forgiato']);
});

it('refuses to add a machine to a node of another Ente through a tampered currentId', function () {
    $b = $this->mondo->riga('B', 'reparto');
    $prima = Strumento::withoutGlobalScopes()->count();

    try {
        Livewire::actingAs($this->adminA)->test(Albero::class)
            ->set('currentId', $b->id)
            ->set('strumentoForm.nome', 'Macchina forgiata')
            ->set('strumentoForm.matricola', 'FORGIATA-1')
            ->call('saveStrumento');
    } catch (ModelNotFoundException) {
    }

    expect(Strumento::withoutGlobalScopes()->count())->toBe($prima);
});

// --- User (esente dal trait: il filtro è esplicito in ElencoUtenti) -----------

it('refuses to act on a user of another Ente from the user list', function (string $azione) {
    $b = $this->mondo->riga('B', 'responsabile');
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(ElencoUtenti::class)->call($azione, $b->id));
})->with(['apriRuolo', 'confermaCestino']);
// `reinvia` e `ripristina` hanno test propri più sotto (T2A-3/T2B-4): su questa
// persona, verificata e non cestinata, li respingerebbe lo stato e non l'Ente.

it('refuses to change the role of, or bin, a user of another Ente through tampered properties', function () {
    $b = $this->mondo->riga('B', 'responsabile');

    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(ElencoUtenti::class)
        ->set('utenteRuolo', $b->id)->set('nuovoRuolo', 'Tenant')->call('cambiaRuolo'));
    Tentativo::senzaEffetto($b, fn () => Livewire::actingAs($this->adminA)->test(ElencoUtenti::class)
        ->set('utenteCestino', $b->id)->call('cestina'));

    expect($b->fresh()->getRoleNames()->all())->toBe([User::DEPARTMENT_SCOPED_ROLE]);
});

it('does not resend the invitation to an invited person of another Ente', function () {
    // La persona di B è nello stato che l'azione accetta (invitata, mai
    // entrata): senza il filtro di tenant l'invito partirebbe davvero.
    Notification::fake();
    $invitataB = User::factory()->create(['name' => 'Invitata-B', 'email_verified_at' => null]);
    $invitataB->forceFill(['tenant_id' => $this->mondo->riga('B', 'ente')->id])->save();
    $invitataB->assignRole(User::TENANT_ROLE);

    Tentativo::senzaEffetto($invitataB, fn () => Livewire::actingAs($this->adminA)->test(ElencoUtenti::class)->call('reinvia', $invitataB->id));

    Notification::assertNotSentTo($invitataB, InvitoUtente::class);
});

it('does send the invitation to a never-logged-in person of the own Ente (positive control)', function () {
    Notification::fake();
    $invitataA = User::factory()->create(['name' => 'Invitata-A', 'email_verified_at' => null]);
    $invitataA->forceFill(['tenant_id' => $this->mondo->riga('A', 'ente')->id])->save();
    $invitataA->assignRole(User::TENANT_ROLE);

    Livewire::actingAs($this->adminA)->test(ElencoUtenti::class)->call('reinvia', $invitataA->id);

    Notification::assertSentTo($invitataA, InvitoUtente::class);
});

it('does not restore a binned person of another Ente', function () {
    $cestinataB = User::factory()->create(['name' => 'Cestinata-B']);
    $cestinataB->forceFill(['tenant_id' => $this->mondo->riga('B', 'ente')->id])->save();
    $cestinataB->assignRole(User::TENANT_ROLE);
    $cestinataB->delete();

    try {
        Livewire::actingAs($this->adminA)->test(ElencoUtenti::class)->call('ripristina', $cestinataB->id);
    } catch (ModelNotFoundException) {
    }

    expect(User::withTrashed()->find($cestinataB->id)->trashed())->toBeTrue();
});

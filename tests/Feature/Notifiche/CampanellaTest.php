<?php

use App\Livewire\Notifiche\Campanella;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\AvvisoObsolescenza;
use App\Notifications\DigestScadenze;
use App\Support\Notifiche\RigaAvviso;
use App\Support\Notifiche\RigaObsolescenza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * La campanella (🔗 ADR-011): il conteggio, il pannello e — la cosa che conta
 * davvero — il fatto che ciascuno veda la propria posta e nessun'altra.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->strumento = Strumento::factory()->forNode($this->ente)->create(['nome' => 'Agitatore 2358']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->admin->assignRole('Admin');
});

function notificaA(User $utente, UnitaOrganizzativa $ente, Strumento $strumento): void
{
    $intervento = Intervento::factory()->forStrumento($strumento)->create([
        'data_scadenza' => today()->addDays(10)->toDateString(),
    ]);

    $utente->notify(new DigestScadenze($ente->id, $ente->nome, [
        RigaAvviso::daIntervento($intervento, $strumento->nome, $strumento->unita_organizzativa_id),
    ]));
}

it('shows no badge when there is nothing unread', function () {
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)->assertSet('nonLette', 0);
});

it('counts the unread notifications', function () {
    notificaA($this->admin, $this->ente, $this->strumento);
    notificaA($this->admin, $this->ente, $this->strumento);
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)->assertSet('nonLette', 2);
});

// 🔴 **Questa regola è cambiata il 28 Ago 2026, e il test dice perché.**
//
// Fino a quel giorno le righe si leggevano solo a pannello aperto, per non
// pesare su ogni pagina. Il prezzo era che il pannello **non si apriva**: il
// click chiedeva le righe al server, la risposta faceva ridisegnare il
// frammento e Alpine ripartiva da capo, richiudendolo nell'istante in cui
// arrivavano i dati per riempirlo. Nessun errore in console. Lo stesso difetto
// era sullo switcher delle sedi, dove Marco l'ha trovato usando l'applicazione.
//
// Ora le righe sono sempre in pagina e l'apertura è tutta nel browser. Il costo
// è dichiarato: una query da dieci righe su ogni pagina, per ogni utente — ma
// il conteggio dei non letti interroga già la stessa tabella a ogni pagina,
// quindi il salto è da una query a due, non da zero.

it('has the rows already in the page, so opening never needs the server', function () {
    notificaA($this->admin, $this->ente, $this->strumento);
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)
        // Senza aver chiamato `apri()`: le righe ci sono già.
        ->assertSee('Ente A')
        ->assertSee('1 in arrivo');
});

it('marks everything as read', function () {
    notificaA($this->admin, $this->ente, $this->strumento);
    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)
        ->call('segnaTutteLette')
        ->assertSet('nonLette', 0);

    expect($this->admin->fresh()->unreadNotifications()->count())->toBe(0);
});

it('never shows the notifications of another user', function () {
    $altro = User::factory()->create(['tenant_id' => $this->ente->id]);
    $altro->assignRole('Admin');
    notificaA($altro, $this->ente, $this->strumento);

    $this->actingAs($this->admin);

    Livewire::test(Campanella::class)
        ->assertSet('nonLette', 0)
        ->call('apri')
        ->assertDontSee('Ente A');
});

it('appears in the app shell for an authenticated user', function () {
    // Un Tenant e non l'Admin: i ruoli privilegiati sono rediretti all'attivazione
    // della 2FA, quindi non arriverebbero mai al layout (TwoFactorEnforcement).
    $tenant = User::factory()->create(['tenant_id' => $this->ente->id]);
    $tenant->assignRole('Tenant');
    notificaA($tenant, $this->ente, $this->strumento);

    $this->actingAs($tenant)
        ->get('/dashboard')
        ->assertOk()
        ->assertSeeLivewire(Campanella::class);
});

// --- La terza transizione, che usciva muta ---
//
// 🔴 La campanella rende DUE notifiche diverse dallo stesso blocco: il digest
// delle scadenze e l'avviso di obsolescenza. Il secondo è nato leggendo solo
// `scadute` e `imminenti`, che nel suo payload non esistono — quindi il
// paragrafo usciva VUOTO: nome dell'Ente, una riga bianca, «0 secondi fa».
// Non dava errore. Dava il nulla, e per chi ha spento le email era l'unico
// canale che gli restava.

it('says what an obsolescence notice is about, instead of showing a blank line', function () {
    $this->actingAs($this->admin);

    $vecchia = Strumento::factory()->forNode($this->ente)->create([
        'nome' => 'Centrifuga CF-12',
        'data_installazione' => today()->subYears(14)->toDateString(),
    ]);

    $this->admin->notify(new AvvisoObsolescenza(
        enteId: $this->ente->id,
        enteNome: $this->ente->nome,
        soglia: 10,
        righe: [RigaObsolescenza::daStrumento($vecchia)],
    ));

    Livewire::test(Campanella::class)
        ->call('apri')
        // ⚠️ Ago SENZA apostrofi: `assertSee` di Livewire escapa, quindi
        // «soglia di età» combacia mentre «l'età» non combacerebbe mai.
        ->assertSee('1 macchina oltre la soglia');
});

it('pluralises the obsolescence notice, and keeps the two counts apart', function () {
    $this->actingAs($this->admin);

    $righe = collect(['Autoclave AC-200', 'Frigo -80 FR-3'])
        ->map(fn (string $nome) => RigaObsolescenza::daStrumento(
            Strumento::factory()->forNode($this->ente)->create([
                'nome' => $nome,
                'data_installazione' => today()->subYears(12)->toDateString(),
            ])
        ))
        ->all();

    $this->admin->notify(new AvvisoObsolescenza($this->ente->id, $this->ente->nome, 10, $righe));

    Livewire::test(Campanella::class)
        ->call('apri')
        ->assertSee('2 macchine oltre la soglia')
        // E NON deve prendere in prestito il vocabolario del digest: le due
        // notifiche vivono nello stesso blocco e leggono chiavi diverse.
        ->assertDontSee('scadenze superate')
        ->assertDontSee('in arrivo');
});

<?php

use App\Enums\TransizioneAvviso;
use App\Livewire\Anagrafica\Albero;
use App\Models\AvvisoScadenza;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\AvvisoObsolescenza;
use App\Support\Notifiche\AvvisiObsolescenza;
use App\Support\Notifiche\RigaObsolescenza;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Il gesto dalla UI: l'Admin abbassa la soglia di obsolescenza dall'anagrafica e
 * l'avviso parte **subito**, senza aspettare il giro delle 06:15 (🔗 ADR-014).
 *
 * ⚠️ **Qui i global scope sono ATTIVI**, al contrario di `AvvisoObsolescenzaTest`
 * che gira in console: è l'altro dei due contesti che `AvvisiObsolescenza` deve
 * reggere, ed è quello in cui il reset conservativo esiste. L'ultimo caso del
 * file è il negativo che lo misura.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->dept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();
    $this->altroDept = UnitaOrganizzativa::factory()->dipartimento()->under($this->ente)->create();

    $this->admin = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Admin');
});

/** Una macchina con età esatta: mai lasciata alla factory, che la randomizza. */
function macchinaCon(UnitaOrganizzativa $nodo, string $nome, int $anniDiEta): Strumento
{
    return Strumento::factory()->forNode($nodo)->create([
        'nome' => $nome,
        'data_installazione' => today()->subYears($anniDiEta)->toDateString(),
    ]);
}

function salvaSoglia(User $utente, UnitaOrganizzativa $nodo, int $anni): void
{
    Livewire::actingAs($utente->fresh())
        ->test(Albero::class)
        ->call('edit', $nodo->id)
        ->set('sogliaObsolescenzaAnni', $anni)
        ->call('save')
        ->assertHasNoErrors();
}

it('sends the alert as soon as an admin lowers the threshold from the anagrafica', function () {
    // A soglia 10 nessuna di queste è obsoleta; a soglia 5 lo diventano tutte.
    macchinaCon($this->dept, 'Agitatore 2358', 9);
    macchinaCon($this->altroDept, 'Centrifuga 88', 6);
    macchinaCon($this->dept, 'Spettrometro nuovo', 2);

    salvaSoglia($this->admin, $this->ente, 5);

    expect(Notification::sent($this->admin, AvvisoObsolescenza::class))->toHaveCount(1);

    $righe = Notification::sent($this->admin, AvvisoObsolescenza::class)->first()->righe;
    $nomi = array_map(fn (RigaObsolescenza $r) => $r->strumentoNome, $righe);

    expect($nomi)->toHaveCount(2)
        ->and($nomi)->toContain('Agitatore 2358')
        ->and($nomi)->toContain('Centrifuga 88');

    expect($nomi)->not->toContain('Spettrometro nuovo');

    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(2);
});

it('removes the registered avviso when the threshold goes up', function () {
    $macchina = macchinaCon($this->dept, 'Altalena', 11);

    $this->artisan('easylab:notifica-obsolescenza')->assertSuccessful();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(1);

    Notification::fake();
    salvaSoglia($this->admin, $this->ente, 20);

    // Le righe `obsoleta` sono le uniche del log che si cancellano: registrano
    // uno stato che una soglia mutabile può disfare, non un fatto avvenuto.
    Notification::assertNothingSent();
    expect(AvvisoScadenza::where('riferimento_id', $macchina->id)
        ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(0);
});

it('sends nothing when the form is saved without touching the threshold', function () {
    // La macchina È obsoleta e non è mai stata annunciata: se la guardia su
    // `wasChanged` cadesse, rinominare un nodo manderebbe un'email.
    macchinaCon($this->dept, 'Agitatore 2358', 11);

    Livewire::actingAs($this->admin->fresh())
        ->test(Albero::class)
        ->call('edit', $this->ente->id)
        ->set('nome', 'Ente A rinominato')
        ->call('save')
        ->assertHasNoErrors();

    Notification::assertNothingSent();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(0);
    expect($this->ente->fresh()->nome)->toBe('Ente A rinominato');
});

it('sends nothing when a non-Ente node is saved', function () {
    // Il ramo `$isEnte`: un dipartimento non ha una soglia da abbassare, e la
    // colonna che pure esiste sulla riga non significa nulla.
    macchinaCon($this->dept, 'Agitatore 2358', 11);

    Livewire::actingAs($this->admin->fresh())
        ->test(Albero::class)
        ->call('edit', $this->dept->id)
        ->set('nome', 'Reparto rinominato')
        ->call('save')
        ->assertHasNoErrors();

    Notification::assertNothingSent();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(0);
});

it('still saves the threshold when the alert has nothing to say', function () {
    // L'alert non deve mai diventare un modo per far fallire il salvataggio.
    salvaSoglia($this->admin, $this->ente, 7);

    expect($this->ente->fresh()->soglia_obsolescenza_anni)->toBe(7);
    Notification::assertNothingSent();
});

it('never deletes the avviso of a machine hidden by the current scope', function () {
    // 🔴 Il negativo che regge il reset CONSERVATIVO. Un reset scritto come
    // «cancella tutte le righe delle macchine che non risultano più obsolete»
    // cancellerebbe in silenzio anche quelle delle macchine che chi salva NON
    // VEDE: non risultano obsolete perché non risultano affatto.
    //
    // ⚠️ Qui la macchina è nascosta dal SOFT DELETE, ed è il caso raggiungibile
    // dalla UI: un Responsabile Reparto, l'altro modo di non vedere una
    // macchina, non può arrivare a salvare l'Ente — non ha
    // `unita_organizzativa.update` nella matrice, e il nodo Ente è comunque
    // fuori dal suo sotto-albero. Quel contesto è misurato dal caso successivo,
    // che chiama il servizio nella stessa condizione di scope in cui
    // `Albero::save()` lo mette.
    $visibile = macchinaCon($this->dept, 'In vista', 11);
    $cestinata = macchinaCon($this->dept, 'Nel cestino', 12);

    $this->artisan('easylab:notifica-obsolescenza')->assertSuccessful();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(2);

    $cestinata->delete();

    Notification::fake();
    salvaSoglia($this->admin, $this->ente, 30);

    expect(AvvisoScadenza::where('riferimento_id', $visibile->id)
        ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(0);

    // La riga della cestinata sopravvive: non è stata letta, quindi non è stata
    // toccata. Se un giorno tornasse dal cestino ancora obsoleta, non
    // riceverebbe un secondo avviso per qualcosa di cui si era già detto.
    expect(AvvisoScadenza::where('riferimento_id', $cestinata->id)
        ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(1);
});

it('never deletes the avviso of a machine the acting user cannot see', function () {
    // 🔴 Lo stesso guardrail nell'altro contesto di scope: un utente
    // department-scoped. Il servizio si chiama qui direttamente e non dalla UI
    // perché quella strada, per un Responsabile, non esiste (vedi il caso
    // sopra) — ma la condizione di scope è **esattamente** quella che
    // `Albero::save()` stabilisce, ed è ciò che il docblock di
    // `AvvisiObsolescenza` promette di reggere.
    $resp = User::factory()->create([
        'tenant_id' => $this->ente->id,
        'two_factor_confirmed_at' => now(),
    ]);
    $resp->assignRole('Responsabile Reparto');
    $resp->unitaResponsabili()->attach($this->dept->id);

    $suo = macchinaCon($this->dept, 'Nel suo reparto', 11);
    $altrui = macchinaCon($this->altroDept, 'Fuori dal suo reparto', 11);

    $this->artisan('easylab:notifica-obsolescenza')->assertSuccessful();
    expect(AvvisoScadenza::where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(2);

    // Soglia alzata da qualcun altro; il Responsabile fa scattare il servizio.
    $this->ente->forceFill(['soglia_obsolescenza_anni' => 30])->save();

    $this->actingAs($resp->fresh());
    AvvisiObsolescenza::perEnte($this->ente->fresh());

    expect(AvvisoScadenza::where('riferimento_id', $suo->id)
        ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(0);

    expect(AvvisoScadenza::where('riferimento_id', $altrui->id)
        ->where('transizione', TransizioneAvviso::Obsoleta)->count())->toBe(1);
});

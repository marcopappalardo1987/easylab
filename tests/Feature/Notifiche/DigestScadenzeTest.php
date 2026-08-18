<?php

use App\Enums\TransizioneAvviso;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\DigestScadenze;
use App\Support\Notifiche\RigaAvviso;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Il digest come documento (🔗 ADR-011): quali canali usa, cosa serializza e —
 * soprattutto — cosa NON scrive mai.
 *
 * Il comando che lo riempie è testato altrove; qui il digest è costruito a mano,
 * perché la domanda è cosa succede *dopo* che le righe esistono.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ente = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Laboratorio Rossi']);
    $this->strumento = Strumento::factory()->forNode($this->ente)->create(['nome' => 'Agitatore 2358']);

    $this->admin = User::factory()->create(['tenant_id' => $this->ente->id]);
    $this->admin->assignRole('Admin');
});

function digestCon(array $righe, UnitaOrganizzativa $ente): DigestScadenze
{
    return new DigestScadenze($ente->id, $ente->nome, $righe);
}

function rigaIntervento(Strumento $strumento, string $scadenza): RigaAvviso
{
    $intervento = Intervento::factory()->forStrumento($strumento)->create([
        'data_scadenza' => $scadenza,
        'descrizione' => 'Sostituzione filtri',
    ]);

    return RigaAvviso::daIntervento($intervento, $strumento->nome, $strumento->unita_organizzativa_id);
}

it('always sends in-app, and email only when the user has not opted out', function () {
    $digest = digestCon([rigaIntervento($this->strumento, today()->addDays(10)->toDateString())], $this->ente);

    expect($digest->via($this->admin))->toEqualCanonicalizing(['database', 'mail']);

    $this->admin->forceFill(['riceve_email_scadenze' => false])->save();
    expect($digest->via($this->admin->fresh()))->toBe(['database']);
});

it('serializes rows with the ente context and string-backed enums', function () {
    $riga = rigaIntervento($this->strumento, today()->addDays(10)->toDateString());

    $payload = digestCon([$riga], $this->ente)->toArray($this->admin);

    expect($payload['ente_id'])->toBe($this->ente->id)
        ->and($payload['ente_nome'])->toBe('Laboratorio Rossi')
        ->and($payload['imminenti'])->toBe(1)
        ->and($payload['scadute'])->toBe(0)
        ->and($payload['righe'][0]['tipo'])->toBe('intervento')
        ->and($payload['righe'][0]['transizione'])->toBe('imminente')
        ->and($payload['righe'][0]['strumento_nome'])->toBe('Agitatore 2358');

    // Il payload deve poter fare andata e ritorno da JSON: è come la riga
    // `notifications` viene salvata e riletta dalla campanella.
    expect(json_decode(json_encode($payload), true))->toBe($payload);
});

it('separates expired rows from upcoming ones in the subject', function () {
    $righe = [
        rigaIntervento($this->strumento, today()->subDay()->toDateString()),
        rigaIntervento($this->strumento, today()->addDays(10)->toDateString()),
    ];

    $mail = digestCon($righe, $this->ente)->toMail($this->admin);

    expect($mail->subject)->toBe('Easy Lab · Laboratorio Rossi: 1 scadenza superata, 1 in arrivo');
});

it('never names the ricambio in the email, not even for an Admin', function () {
    // La riga nasce come la produce il comando: `deiPezziMontati()` seleziona le
    // sole colonne id e scadenza, quindi il nome del pezzo non è mai letto.
    $ricambio = Ricambio::factory()->forTenant($this->ente)->create([
        'nome' => 'Lampada UV modello XR-7',
    ]);
    $intervento = Intervento::factory()->forStrumento($this->strumento)->create();
    $utilizzo = RicambioUtilizzo::factory()
        ->forStrumento($this->strumento)
        ->forRicambio($ricambio)
        ->forIntervento($intervento)
        ->montatoAMano(today()->subMonth()->toDateString())
        ->create();
    $garanzia = Garanzia::factory()->forRicambio($utilizzo)->imminente()->create();

    $riga = RigaAvviso::daGaranziaRicambio(
        $garanzia,
        $this->strumento->id,
        $this->strumento->nome,
        $this->strumento->unita_organizzativa_id,
    );

    expect($riga->dettaglio)->toBeNull()
        ->and($riga->transizione)->toBe(TransizioneAvviso::Imminente);

    $reso = (string) digestCon([$riga], $this->ente)->toMail($this->admin)->render();

    expect($reso)->not->toContain('Lampada UV')
        ->and($reso)->not->toContain('XR-7')
        // Nemmeno il fatto che si tratti di un ricambio: l'email esce
        // dall'applicazione e può essere inoltrata (ADR-004).
        ->and($reso)->not->toContain('ricambio')
        ->and($reso)->toContain('Garanzia di un componente')
        ->and($reso)->toContain('Agitatore 2358');
});

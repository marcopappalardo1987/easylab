<?php

use App\Livewire\Piattaforma\EmailInviate;
use App\Models\EmailInviata;
use App\Support\Email\CatalogoEmail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Piattaforma → Email inviate (🔗 ADR-064): la vedono il Developer e il
 * Superadmin, nessun ruolo dei clienti; e i filtri fanno ciò che dicono.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->riga = function (array $campi): EmailInviata {
        $email = (new EmailInviata)->forceFill([
            'destinatari' => 'chi@esempio.test',
            'stato' => EmailInviata::STATO_ACCETTATA,
            ...$campi,
        ]);
        $email->save();

        return $email;
    };
});

it('opens to the platform roles', function (string $ruolo) {
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.email-inviate'))
        ->assertOk()
        ->assertSee('Email inviate');
})->with(RUOLI_CON_PIATTAFORMA);

it('stays closed to every customer role', function (string $ruolo) {
    // 🔴 Il registro elenca gli indirizzi di tutti i clienti.
    $this->actingAs(utenteConRuolo($ruolo))
        ->get(route('piattaforma.email-inviate'))
        ->assertForbidden();
})->with(RUOLI_SENZA_PIATTAFORMA);

it('asks for the permission again on every render, not only at the door', function () {
    // Un cambio di filtro è una richiesta Livewire che non ripassa dal
    // middleware di rotta: chi perde il permesso a pagina aperta non deve
    // continuare a leggere.
    $superadmin = utenteConRuolo('Superadmin');
    $pagina = Livewire::actingAs($superadmin)->test(EmailInviate::class)->assertOk();

    $superadmin->syncRoles([]);

    $pagina->set('cerca', 'chi')->assertForbidden();
});

it('shows who received what, and filters by recipient, type and state', function () {
    $invito = ($this->riga)(['tipo' => CatalogoEmail::INVITO, 'classe' => 'InvitoUtente', 'destinatari' => 'nuovo@cliente.test', 'oggetto' => 'Sei stato invitato']);
    $digest = ($this->riga)(['tipo' => CatalogoEmail::RIEPILOGO_SCADENZE, 'classe' => 'DigestScadenze', 'destinatari' => 'admin@altro.test', 'oggetto' => 'Scadenze di oggi']);
    $rifiutata = ($this->riga)(['tipo' => CatalogoEmail::INVITO, 'classe' => 'InvitoUtente', 'destinatari' => 'rifiutata@cliente.test', 'stato' => EmailInviata::STATO_IN_INVIO]);
    $rifiutata->forceFill(['created_at' => now()->subHour()])->save();
    $prova = ($this->riga)(['classe' => 'EmailDiProva', 'destinatari' => 'prove@esempio.test', 'oggetto' => '[Prova] Invito']);

    $riga = fn (EmailInviata $e) => 'data-email-inviata="'.$e->id.'"';

    $pagina = Livewire::actingAs(utenteConRuolo('Superadmin'))->test(EmailInviate::class)
        ->assertSeeHtml($riga($invito))->assertSeeHtml($riga($digest))
        ->assertSeeHtml($riga($rifiutata))->assertSeeHtml($riga($prova))
        ->assertSee('nuovo@cliente.test')
        ->assertSee(CatalogoEmail::trova(CatalogoEmail::INVITO)->nome)
        ->assertSee('Prova di invio')
        ->assertSee('Non partita');

    $pagina->set('cerca', 'CLIENTE.test')
        ->assertSeeHtml($riga($invito))->assertSeeHtml($riga($rifiutata))
        ->assertDontSeeHtml($riga($digest))->assertDontSeeHtml($riga($prova));

    $pagina->set('cerca', '')->set('tipo', CatalogoEmail::RIEPILOGO_SCADENZE)
        ->assertSeeHtml($riga($digest))
        ->assertDontSeeHtml($riga($invito))->assertDontSeeHtml($riga($prova));

    $pagina->set('tipo', EmailInviate::FUORI_CATALOGO)
        ->assertSeeHtml($riga($prova))
        ->assertDontSeeHtml($riga($invito))->assertDontSeeHtml($riga($digest));

    $pagina->set('tipo', '')->set('stato', EmailInviata::STATO_NON_PARTITA)
        ->assertSeeHtml($riga($rifiutata))
        ->assertDontSeeHtml($riga($invito))->assertDontSeeHtml($riga($digest));
});

it('ignores a type or a state that does not exist, instead of hiding everything', function () {
    // I filtri arrivano dal browser e stanno in query string.
    $email = ($this->riga)(['tipo' => CatalogoEmail::INVITO]);

    Livewire::actingAs(utenteConRuolo('Superadmin'))->test(EmailInviate::class)
        ->set('tipo', 'inventato')->set('stato', 'qualunque')
        ->assertSeeHtml('data-email-inviata="'.$email->id.'"');
});

it('takes a search literally, wildcards included', function () {
    $email = ($this->riga)(['destinatari' => 'mario.rossi@esempio.test']);

    Livewire::actingAs(utenteConRuolo('Superadmin'))->test(EmailInviate::class)
        ->set('cerca', '%')
        ->assertDontSeeHtml('data-email-inviata="'.$email->id.'"')
        ->set('cerca', 'mario.rossi')
        ->assertSeeHtml('data-email-inviata="'.$email->id.'"');
});

it('lights its own tab, and not the one of the Email page', function () {
    $html = $this->actingAs(utenteConRuolo('Superadmin'))
        ->get(route('piattaforma.email-inviate'))->getContent();

    preg_match('/<nav[^>]*Sezioni della piattaforma.*?<\/nav>/s', $html, $barra);

    expect($barra[0] ?? '')->toMatch('/<a href="'.preg_quote(route('piattaforma.email-inviate'), '/').'"\s+aria-current="page"/')
        ->not->toMatch('/<a href="'.preg_quote(route('piattaforma.email'), '/').'"\s+aria-current="page"/');
});

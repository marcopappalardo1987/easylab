<?php

use App\Livewire\Anagrafica\Albero;
use App\Livewire\Tenancy\SwitcherEnte;
use App\Models\Documento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Support\Tenancy\AccessibleNodes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\Feature\Isolamento\Support\MondoDueEnti;
use Tests\Feature\Isolamento\Support\Tentativo;

/**
 * Secondo livello di isolamento: lo scope di reparto (DepartmentScope,
 * AccessibleNodes, ADR-006). Il Responsabile vede il sotto-albero assegnato e
 * nient'altro, nemmeno del proprio Ente — e la tabella delle assegnazioni non è
 * una porta verso un altro Ente.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mondo = MondoDueEnti::crea();
    $this->responsabile = $this->mondo->riga('A', 'responsabile');
});

it('limits every department-scoped model to the assigned subtree', function () {
    $this->actingAs($this->responsabile);

    expect(Strumento::pluck('id')->all())->toBe([$this->mondo->riga('A', 'strumento')->id])
        ->and(Intervento::pluck('id')->all())->toBe([$this->mondo->riga('A', 'intervento')->id])
        ->and(Documento::pluck('id')->all())->toBe([$this->mondo->riga('A', 'documento')->id])
        ->and(UnitaOrganizzativa::pluck('id')->all())->not->toContain($this->mondo->riga('A', 'altroReparto')->id);
});

it('refuses a node outside the assigned subtree from the anagrafica tree', function () {
    $fuori = $this->mondo->riga('A', 'altroReparto');

    $segreti = ['RepartoNonAssegnato-A', 'StrumentoNonAssegnato-A', 'MAT-NONASSEGNATO-A'];

    Tentativo::senzaEffetto($fuori, fn () => Livewire::actingAs($this->responsabile)->test(Albero::class)->call('open', $fuori->id), $segreti);
    Tentativo::senzaEffetto($fuori, fn () => Livewire::actingAs($this->responsabile)->test(Albero::class)->call('goTo', $fuori->id), $segreti);
    Tentativo::senzaEffetto($fuori, fn () => Livewire::actingAs($this->responsabile)->test(Albero::class)
        ->set('editingId', $fuori->id)->set('nome', 'Sovrascritto dal Responsabile')->call('save'), $segreti);
});

it('exports only the documents of the assigned subtree', function () {
    $righe = null;
    View::composer('pdf.elenco-documenti', function ($vista) use (&$righe) {
        $righe = $vista->getData()['righe'];
    });

    // Il Responsabile ha `documenti.export_pdf`: l'export gli è servito, e deve
    // lasciar fuori il documento della macchina nel reparto non assegnato.
    $this->actingAs($this->responsabile)->get('/documenti/export.pdf')->assertOk();

    expect($righe->pluck('id')->all())->toBe([$this->mondo->riga('A', 'documento')->id]);
});

it('never turns an assignment row pointing to another Ente into access', function () {
    // Riga di assegnazione corrotta (fuori da Eloquent): il Responsabile di A
    // risulta «assegnato» al reparto di B. AccessibleNodes scarta le radici
    // fuori dal tenant (D-T2-2); il TenantScope resta la seconda rete.
    $repartoB = $this->mondo->riga('B', 'reparto');
    $this->responsabile->unitaResponsabili()->attach($repartoB->id);

    $this->actingAs($this->responsabile);

    expect(AccessibleNodes::forCurrentUser())->not->toContain($repartoB->id)
        ->and(AccessibleNodes::forUser($this->responsabile))->toBe([$this->mondo->riga('A', 'reparto')->id])
        ->and(Strumento::pluck('id')->all())->not->toContain($this->mondo->riga('B', 'strumento')->id)
        ->and(Intervento::pluck('id')->all())->not->toContain($this->mondo->riga('B', 'intervento')->id);

    $this->get(route('strumenti.show', $this->mondo->riga('B', 'strumento')))->assertNotFound();
});

it('gives nothing to a Responsabile whose only assignment points to another Ente', function () {
    $this->responsabile->unitaResponsabili()->sync([$this->mondo->riga('B', 'reparto')->id]);

    expect(AccessibleNodes::forUser($this->responsabile))->toBe([]);
});

it('shows nothing at all to a Responsabile whose assignments were all removed', function () {
    $this->responsabile->unitaResponsabili()->detach();

    $this->actingAs($this->responsabile);

    expect(Strumento::count())->toBe(0)
        ->and(Intervento::count())->toBe(0);
    $this->get(route('strumenti.show', $this->mondo->riga('A', 'strumento')))->assertNotFound();
});

it('names the Ente in the switcher of a Responsabile, whose subtree does not include the Ente root', function () {
    $componente = Livewire::actingAs($this->responsabile)->test(SwitcherEnte::class);

    expect($componente->get('nomeEnte'))->toBe('Ente-SEGRETO-A');
});

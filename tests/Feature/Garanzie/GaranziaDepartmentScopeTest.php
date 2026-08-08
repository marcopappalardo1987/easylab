<?php

use App\Enums\SoggettoGaranzia;
use App\Models\Garanzia;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\Scopes\GaranziaDepartmentScope;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Livello 2 sulle garanzie (ADR-006) — test NEGATIVI, area rossa.
 *
 * Esiste come file a sé, e non come casi aggiunti a InterventoDepartmentScopeTest,
 * perché qui ogni caso va incrociato con **le due varianti di soggetto**: una
 * garanzia raggiunge lo strumento per `strumento_id` se è del macchinario, e
 * per il doppio salto via `ricambio_utilizzo` se è di un pezzo montato. Il
 * debito S3 lettera (b) era esattamente la seconda strada mancante.
 *
 * Albero: Ente A → deptA1 → subA1a ; deptA2 (fratello). Ente B → deptB1.
 * Ogni strumento porta una garanzia macchina E una garanzia ricambio.
 *
 * Le helper sono closure su `$this` e non funzioni globali: `userWith` e
 * `responsabileDi` sono già definite in altri file di test e in Pest le
 * funzioni globali collidono.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->enteA = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente A']);
    $this->deptA1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();
    $this->subA1a = UnitaOrganizzativa::factory()->sottolaboratorio()->under($this->deptA1)->create();
    $this->deptA2 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteA)->create();

    $this->enteB = UnitaOrganizzativa::factory()->ente()->create(['nome' => 'Ente B']);
    $deptB1 = UnitaOrganizzativa::factory()->dipartimento()->under($this->enteB)->create();

    // Una coppia macchina/ricambio per nodo. `$utilizzi` serve ai test che
    // cestinano la riga di montaggio.
    $this->utilizzi = [];
    $coppiaSu = function (UnitaOrganizzativa $nodo, UnitaOrganizzativa $ente) {
        $strumento = Strumento::factory()->forNode($nodo)->create();
        $utilizzo = RicambioUtilizzo::factory()
            ->forStrumento($strumento)
            ->forRicambio(Ricambio::factory()->forTenant($ente)->create())
            ->create();
        $this->utilizzi[$nodo->id] = $utilizzo;

        return [
            'macchina' => Garanzia::factory()->forStrumento($strumento)->create()->id,
            'ricambio' => Garanzia::factory()->forRicambio($utilizzo)->create()->id,
        ];
    };

    $this->a1 = $coppiaSu($this->deptA1, $this->enteA);
    $this->a1a = $coppiaSu($this->subA1a, $this->enteA);
    $this->a2 = $coppiaSu($this->deptA2, $this->enteA);
    $this->b1 = $coppiaSu($deptB1, $this->enteB);

    $this->responsabile = function (array $nodi, ?UnitaOrganizzativa $ente = null): User {
        $resp = User::factory()->create(['tenant_id' => ($ente ?? $this->enteA)->id]);
        $resp->assignRole('Responsabile Reparto');
        $resp->unitaResponsabili()->attach(collect($nodi)->pluck('id')->all());

        return $resp;
    };

    $this->conRuolo = function (string $ruolo): User {
        $u = User::factory()->create(['tenant_id' => $this->enteA->id]);
        $u->assignRole($ruolo);

        return $u;
    };
});

it('shows the Responsabile both soggetti of their own sub-tree', function () {
    $this->actingAs(($this->responsabile)([$this->deptA1]));

    // Il sotto-albero include il sotto-laboratorio: quattro righe, due per nodo.
    expect(Garanzia::pluck('id')->all())->toEqualCanonicalizing([
        $this->a1['macchina'], $this->a1['ricambio'],
        $this->a1a['macchina'], $this->a1a['ricambio'],
    ]);
});

it('hides both soggetti of a sibling department, on read and on write', function () {
    $this->actingAs(($this->responsabile)([$this->deptA1]));

    foreach ([$this->a2['macchina'], $this->a2['ricambio']] as $id) {
        expect(Garanzia::find($id))->toBeNull()
            ->and(Garanzia::where('id', $id)->exists())->toBeFalse()
            ->and(Garanzia::where('id', $id)->update(['durata_mesi' => 99]))->toBe(0);
    }
});

it('does not let the OR escape its group and leak another tenant', function () {
    // Se la closure di raggruppamento sparisse, l'orWhere si legherebbe al
    // where del TenantScope e il ramo ricambio resterebbe senza confine Ente.
    // Il filtro esplicito su `soggetto` rende il caso leggibile: chiedendo le
    // sole righe macchina fuori sotto-albero non deve tornare NULLA — con l'OR
    // sfuggito tornerebbero invece righe ricambio.
    $this->actingAs(($this->responsabile)([$this->deptA1]));

    expect(Garanzia::where('soggetto', 'macchina')->pluck('id')->all())
        ->toEqualCanonicalizing([$this->a1['macchina'], $this->a1a['macchina']])
        ->and(Garanzia::whereIn('id', [$this->b1['macchina'], $this->b1['ricambio']])->exists())
        ->toBeFalse();
});

it('keeps a Responsabile of another tenant out entirely', function () {
    $this->actingAs(($this->responsabile)([$this->deptA1], $this->enteB));

    // Nodi di un altro Ente nel pivot: il confine riapplicato dentro la
    // subquery non li fa passare.
    expect(Garanzia::pluck('id')->all())->toBeEmpty();
});

it('stays closed on a mounting row whose tenant contradicts its strumento', function () {
    // Il `tenant_id` nella subquery interna è RIDONDANTE finché l'invariante
    // «ricambio_utilizzo.tenant_id == strumento.tenant_id» regge: AccessibleStrumenti
    // filtra già per Ente. Serve solo se quella riga esiste rotta — e allora la
    // scriviamo rotta, con `DB::table` che salta le guardie del model, invece di
    // lasciare in piedi una guardia che nessun test può far cadere.
    //
    // La riga finta appartiene all'Ente B ma punta a uno strumento dell'Ente A,
    // dentro il sotto-albero del Responsabile; la garanzia sopra è dell'Ente A,
    // quindi il TenantScope da solo la lascerebbe passare.
    $strumentoA1 = Strumento::withoutGlobalScopes()->where('unita_organizzativa_id', $this->deptA1->id)->firstOrFail();

    $utilizzoIncoerente = DB::table('ricambio_utilizzo')->insertGetId([
        'tenant_id' => $this->enteB->id,
        'strumento_id' => $strumentoA1->id,
        'ricambio_id' => Ricambio::factory()->forTenant($this->enteB)->create()->id,
        'quantita' => 1,
        'data' => today()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $garanziaSospetta = Garanzia::factory()->create([
        'tenant_id' => $this->enteA->id,
        'soggetto' => SoggettoGaranzia::Ricambio,
        'strumento_id' => null,
        'ricambio_utilizzo_id' => $utilizzoIncoerente,
    ]);

    $this->actingAs(($this->responsabile)([$this->deptA1]));

    expect(Garanzia::pluck('id')->all())->not->toContain($garanziaSospetta->id);
});

it('is fail-safe for a Responsabile with no assignment', function () {
    $this->actingAs(($this->responsabile)([]));

    expect(Garanzia::pluck('id')->all())->toBeEmpty();
});

it('hides a ricambio garanzia whose mounting row is soft-deleted', function () {
    // Consegna scritta nel docblock di RicambioUtilizzo quando la tabella è
    // nata: una riga cestinata non esiste per nessuna lettura di dominio. La
    // subquery gira withoutGlobalScopes(), che porta via anche il
    // SoftDeletingScope, quindi il `deleted_at` va riapplicato a mano.
    $this->utilizzi[$this->deptA1->id]->delete();

    $this->actingAs(($this->responsabile)([$this->deptA1]));

    expect(Garanzia::pluck('id')->all())->toEqualCanonicalizing([
        $this->a1['macchina'],
        $this->a1a['macchina'], $this->a1a['ricambio'],
    ]);
});

it('shows a Tenant of the same sub-tree only the macchina rows', function () {
    // I due global scope si compongono in AND: il Tenant non è ristretto per
    // reparto, ma la privacy di ADR-004 gli toglie comunque le righe ricambio.
    $this->actingAs(($this->conRuolo)('Tenant'));

    expect(Garanzia::pluck('id')->all())->toEqualCanonicalizing([
        $this->a1['macchina'], $this->a1a['macchina'], $this->a2['macchina'],
    ]);
});

it('does not restrict an Admin, who has no sub-tree', function () {
    $this->actingAs(($this->conRuolo)('Admin'));

    expect(Garanzia::pluck('id')->all())->toEqualCanonicalizing([
        $this->a1['macchina'], $this->a1['ricambio'],
        $this->a1a['macchina'], $this->a1a['ricambio'],
        $this->a2['macchina'], $this->a2['ricambio'],
    ]);
});

it('does not restrict a console context', function () {
    // Nessun actingAs: stessa postura di TenantScope, il seeder vede tutto.
    expect(Garanzia::count())->toBe(8);
});

it('offers an escape hatch that lifts only the department restriction', function () {
    $this->actingAs(($this->responsabile)([$this->deptA1]));

    // Toglie il reparto ma NON la tenancy: le righe dell'Ente B restano fuori.
    $ids = Garanzia::withoutGlobalScope(GaranziaDepartmentScope::class)->pluck('id')->all();

    expect($ids)->toContain($this->a2['ricambio'])
        ->and($ids)->not->toContain($this->b1['ricambio']);
});

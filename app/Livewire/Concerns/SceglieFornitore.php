<?php

namespace App\Livewire\Concerns;

use App\Models\Fornitore;
use App\Support\Fornitori\RegoleFornitore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Il selettore del fornitore: si scorre, si filtra scrivendo, e se il fornitore
 * manca lo si crea senza uscire dalla schermata (🔗 ADR-051; ADR-023 il
 * fornitore della macchina).
 *
 * Fino al 10 Ott 2026 era una `<select>`: con trenta fornitori si scorreva, con
 * trecento no. E un fornitore che non c'era costringeva a chiudere il form,
 * andare in Fornitori, crearlo, tornare e ricominciare.
 *
 * ## Lo stato sta sul server
 *
 * `selettoreFornitore` dice **di quale campo** è aperto il selettore, e
 * l'elenco lo calcola il server a ogni giro. Non è uno stato Alpine, ed è la
 * regola di `TendineLivewireGuardrailTest`: una tendina che si riempie con un
 * giro sul server perderebbe il proprio «aperto» nell'istante in cui Livewire
 * ridisegna, cioè proprio quando arrivano i dati. Alpine qui aggiunge solo
 * tastiera, fuoco e chiusura al click fuori.
 *
 * ## Una sola definizione di «selezionabile»
 *
 * `fornitoriSelezionabili()` riempie l'elenco **e** decide se una scelta è
 * accettata: un id che non compare nell'elenco non passa nemmeno se arriva
 * forgiato dal browser. Le regole di salvataggio dei form ospiti dicono la
 * stessa cosa in SQL, perché `scegliFornitore()` non è l'unica strada con cui
 * la property si può scrivere.
 *
 * ## Un selettore per campo, uno solo aperto
 *
 * La schermata può avere più campi fornitore: quello della macchina, uno per
 * ogni riga dei ricambi. Ognuno ha una chiave (`strumento`, `ricambio.2`,
 * `correzione`), e il componente ospite dice che cos'è con `campoFornitore()`.
 */
trait SceglieFornitore
{
    /** Quanti fornitori mostra l'elenco prima di chiedere di scrivere. */
    public const FORNITORI_IN_ELENCO = 50;

    /** Il campo di cui è aperto il selettore, o `null`. */
    public ?string $selettoreFornitore = null;

    /** Ciò che si sta scrivendo per filtrare l'elenco. */
    public string $cercaFornitore = '';

    public bool $nuovoFornitoreAperto = false;

    /** @var array{ragione_sociale:string,email:?string,telefono:?string} */
    public array $nuovoFornitore = ['ragione_sociale' => '', 'email' => null, 'telefono' => null];

    /**
     * Che cos'è un campo fornitore di questa schermata: la sede fra i cui
     * fornitori si sceglie e, se il record ne ha già uno salvato, il suo id.
     *
     * `corrente` riammette il fornitore già associato anche se cestinato:
     * senza, correggere un record il cui fornitore è finito nel cestino
     * fallirebbe su un campo che nessuno ha toccato.
     *
     * `null` per una chiave che non è un campo aperto di questa schermata:
     * l'azione risponde 404.
     *
     * @return array{tenant:int, corrente:?int}|null
     */
    abstract protected function campoFornitore(string $campo): ?array;

    /** Scrive la scelta nella property del form a cui il campo appartiene. */
    abstract protected function scriviFornitore(string $campo, ?int $id): void;

    /**
     * I fornitori fra cui un Ente può scegliere (ADR-023): i vivi, più quello
     * già associato anche se cestinato.
     *
     * ⚠️ Il tie-break sull'id non è decorativo: l'elenco del selettore è
     * tagliato a `FORNITORI_IN_ELENCO`, e a parità di ragione sociale l'ordine
     * fra due righe è una proprietà del motore.
     *
     * @return Builder<Fornitore>
     */
    protected function fornitoriSelezionabili(int $tenantId, ?int $correnteId = null): Builder
    {
        return Fornitore::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where(fn (Builder $q) => $q->whereNull('deleted_at')
                ->when($correnteId !== null, fn (Builder $q) => $q->orWhere('id', $correnteId)))
            ->orderBy('ragione_sociale')
            ->orderBy('id');
    }

    /**
     * La regola di salvataggio di un campo fornitore: la stessa whitelist
     * dell'elenco, detta in SQL.
     *
     * Serve perché `scegliFornitore()` non è l'unica strada: la property del
     * form è pubblica, e un id forgiato dal browser ci arriva senza passare da
     * nessun selettore. Senza `fornitori.view` il campo non esiste: la regola è
     * `nullable`, e chi salva non legge ciò che è arrivato.
     *
     * @return list<mixed>
     */
    protected function regolaFornitore(int $tenantId, ?int $correnteId = null, bool $obbligatorio = false): array
    {
        if (! Gate::allows('fornitori.view')) {
            return ['nullable'];
        }

        return [
            $obbligatorio ? 'required' : 'nullable',
            'integer',
            Rule::exists('fornitori', 'id')->where(
                fn ($q) => $q->where('tenant_id', $tenantId)
                    ->where(fn ($q) => $q->whereNull('deleted_at')
                        ->when($correnteId !== null, fn ($q) => $q->orWhere('id', $correnteId)))
            ),
        ];
    }

    public function apriFornitori(string $campo): void
    {
        Gate::authorize('fornitori.view');
        abort_if($this->campoFornitore($campo) === null, 404);

        $this->chiudiFornitori();
        $this->selettoreFornitore = $campo;
    }

    public function chiudiFornitori(): void
    {
        $this->reset(['selettoreFornitore', 'cercaFornitore', 'nuovoFornitoreAperto', 'nuovoFornitore']);
        $this->resetValidation(['nuovoFornitore.ragione_sociale', 'nuovoFornitore.email', 'nuovoFornitore.telefono']);
    }

    /**
     * Sceglie un fornitore dall'elenco del selettore aperto.
     *
     * 🔴 L'id arriva dal browser: si rilegge **dalla stessa query dell'elenco**.
     * Fuori da quella c'è un 404, non un 403: un «non ti è permesso»
     * confermerebbe che il fornitore di un altro Ente esiste.
     */
    public function scegliFornitore(int $id): void
    {
        Gate::authorize('fornitori.view');

        [$campo, $contesto] = $this->selettoreInUso();

        $fornitore = $this->fornitoriSelezionabili($contesto['tenant'], $contesto['corrente'])
            ->whereKey($id)
            ->firstOrFail();

        $this->scriviFornitore($campo, (int) $fornitore->id);
        $this->chiudiFornitori();
    }

    /** «Nessun fornitore»: svuota il campo e chiude. */
    public function togliFornitore(): void
    {
        Gate::authorize('fornitori.view');

        [$campo] = $this->selettoreInUso();

        $this->scriviFornitore($campo, null);
        $this->chiudiFornitori();
    }

    /** Apre il mini-form, partendo da ciò che si stava cercando. */
    public function apriNuovoFornitore(): void
    {
        Gate::authorize('fornitori.create');
        $this->selettoreInUso();

        $this->nuovoFornitore = ['ragione_sociale' => trim($this->cercaFornitore), 'email' => null, 'telefono' => null];
        $this->nuovoFornitoreAperto = true;
        $this->resetValidation(['nuovoFornitore.ragione_sociale', 'nuovoFornitore.email', 'nuovoFornitore.telefono']);
    }

    public function annullaNuovoFornitore(): void
    {
        $this->nuovoFornitoreAperto = false;
    }

    /**
     * Crea il fornitore nella sede del campo e lo sceglie.
     *
     * ⚠️ **`fornitori.create` e non il permesso del form ospite.** Poter
     * registrare una macchina non è poter allargare l'anagrafica dei
     * fornitori: sono due permessi, e l'editor dei ruoli può darne uno senza
     * l'altro. Le regole sono quelle della pagina Fornitori (`RegoleFornitore`).
     */
    public function creaFornitore(): void
    {
        Gate::authorize('fornitori.create');

        [$campo, $contesto] = $this->selettoreInUso();

        // Si ripulisce prima di validare: uno spazio in coda a un indirizzo
        // incollato lo farebbe rifiutare da `email`, e un campo facoltativo
        // lasciato vuoto è `null`, non la stringa vuota.
        foreach (['ragione_sociale', 'email', 'telefono'] as $voce) {
            $valore = is_string($this->nuovoFornitore[$voce] ?? null) ? trim($this->nuovoFornitore[$voce]) : '';
            $this->nuovoFornitore[$voce] = $valore === '' && $voce !== 'ragione_sociale' ? null : $valore;
        }

        $validato = $this->validate(
            RegoleFornitore::per('nuovoFornitore', ['ragione_sociale', 'email', 'telefono']),
            attributes: [
                'nuovoFornitore.ragione_sociale' => 'ragione sociale',
                'nuovoFornitore.email' => 'email',
                'nuovoFornitore.telefono' => 'telefono',
            ],
        )['nuovoFornitore'];

        $fornitore = DB::transaction(function () use ($validato, $contesto): Fornitore {
            $fornitore = Fornitore::create($validato + ['tenant_id' => $contesto['tenant']]);

            // 🔴 `BelongsToTenant` riscrive il tenant di chi ha un Ente proprio.
            // Per quasi tutti è la stessa sede del campo; se non lo è (un
            // tecnico che lavora per portafoglio sulla macchina di un altro
            // Ente) il fornitore nascerebbe nell'anagrafica sbagliata, e la
            // macchina non potrebbe nemmeno usarlo. Meglio non crearlo.
            abort_unless((int) $fornitore->tenant_id === $contesto['tenant'], 403);

            return $fornitore;
        });

        $this->scriviFornitore($campo, (int) $fornitore->id);
        $this->chiudiFornitori();
    }

    /**
     * L'elenco del selettore aperto: filtrato da ciò che si scrive e tagliato a
     * `FORNITORI_IN_ELENCO`.
     *
     * `altri` dice che il taglio ha lasciato fuori qualcuno, perché la pagina
     * lo dica: un elenco che finisce senza spiegarlo si legge «sono tutti qui».
     *
     * ⚠️ `protected`: un metodo pubblico di un componente Livewire è
     * un'azione che il browser può chiamare, e questa restituirebbe dei dati.
     * La vista la riceve da `render()`.
     *
     * @return array{righe: Collection<int, Fornitore>, altri: bool}
     */
    protected function elencoFornitori(): array
    {
        $contesto = $this->selettoreFornitore === null ? null : $this->campoFornitore($this->selettoreFornitore);

        if ($contesto === null || ! Gate::allows('fornitori.view')) {
            return ['righe' => new Collection, 'altri' => false];
        }

        $cercato = addcslashes(mb_strtolower(trim($this->cercaFornitore)), '%_\\');

        $righe = $this->fornitoriSelezionabili($contesto['tenant'], $contesto['corrente'])
            // `LOWER(...) LIKE` e non `ILIKE`: deve dire la stessa cosa su
            // SQLite, dove gira la suite, e su Postgres.
            ->when($cercato !== '', fn (Builder $q) => $q->whereRaw("LOWER(ragione_sociale) LIKE ? ESCAPE '\\'", ["%{$cercato}%"]))
            ->limit(self::FORNITORI_IN_ELENCO + 1)
            ->get();

        return [
            'righe' => $righe->take(self::FORNITORI_IN_ELENCO),
            'altri' => $righe->count() > self::FORNITORI_IN_ELENCO,
        ];
    }

    /**
     * I fornitori già scelti nei campi della schermata, per id: servono a
     * scriverne il nome sul selettore chiuso. Una query sola, qualunque sia il
     * numero di campi.
     *
     * ⚠️ Passa da `TenantScope`, e non è un dettaglio: l'id sta in una property
     * pubblica, e uno forgiato con quello del fornitore di un altro Ente non
     * deve farne comparire il nome. Qui semplicemente non si trova.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, Fornitore>
     */
    protected function fornitoriScelti(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (mixed $id) => is_numeric($id) ? (int) $id : 0,
            $ids,
        ))));

        if ($ids === [] || ! Gate::allows('fornitori.view')) {
            return new Collection;
        }

        return Fornitore::withTrashed()->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * Il campo del selettore aperto e il suo contesto, o 404.
     *
     * @return array{0: string, 1: array{tenant:int, corrente:?int}}
     */
    private function selettoreInUso(): array
    {
        $contesto = $this->selettoreFornitore === null ? null : $this->campoFornitore($this->selettoreFornitore);

        abort_if($contesto === null, 404);

        return [$this->selettoreFornitore, $contesto];
    }
}

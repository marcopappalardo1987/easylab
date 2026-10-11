<?php

namespace App\Support\Billing;

use App\Enums\ConteggioStrumenti;
use App\Support\Piani;
use Illuminate\Support\Facades\DB;

/**
 * Il tetto di strumenti di una sede, letto adesso (🔗 ADR-049; ADR-032 il
 * limite di Enti, di cui è il gemello; ADR-035 il listino).
 *
 * Il piano dice **quanti** (`max_strumenti`) e **come si contano**
 * (`conteggio_strumenti`: ogni sede per conto suo, o il cliente in tutto).
 * Questa classe è il solo posto in cui le due cose si compongono coi dati: i
 * gesti che fanno nascere uno strumento, l'albero e l'import CSV, la
 * consumano, e nessuno dei due la riscrive.
 *
 * ## Una condizione d'ingresso, non un'espulsione
 *
 * Come per le sedi (grandfathering, ADR-032): chi è sopra il tetto, perché il
 * piano è stato ristretto o perché è sceso di piano, tiene tutti gli strumenti
 * che ha e non ne aggiunge. `residui()` diventa negativo, ed è uno stato
 * legittimo.
 *
 * ## Quando il tetto non c'è
 *
 * - il piano non ne dichiara uno (`null` = illimitato);
 * - la sede non ha un contratto (`account_id` nullo): non c'è un piano da
 *   applicare;
 * - il piano non è più a catalogo: è un dato da riparare dalla cabina, e non
 *   deve fermare il lavoro di un laboratorio che non ne ha colpa.
 *
 * In tutti e tre i casi non si conta nemmeno.
 *
 * ## ⛔ Il conteggio non passa dagli scope
 *
 * Query builder e non Eloquent, come `ClientiGestiti`. `Strumento` ha
 * `TenantScope` e `DepartmentScope`: contando con quelli, un Responsabile di
 * reparto vedrebbe solo i propri strumenti, cioè un tetto che per lui non si
 * riempie mai. Il tetto è della sede (o del cliente), non di chi guarda. I
 * cestinati non contano: uno strumento eliminato libera il suo posto.
 */
final class TettoStrumenti
{
    private function __construct(
        /** `null` = nessun tetto. */
        public readonly ?int $massimo,
        public readonly int $presenti,
        public readonly ConteggioStrumenti $conteggio,
        /** L'etichetta del piano, per dirla a chi legge. */
        public readonly string $piano,
    ) {}

    /** Il tetto della sede, per chi deve mostrarlo o decidere se aprire un form. */
    public static function dellEnte(int $enteId): self
    {
        return self::leggi($enteId, blocca: false);
    }

    /**
     * La guardia dei gesti che scrivono: va chiamata **dentro la transazione**
     * che crea gli strumenti.
     *
     * 🔴 Blocca la riga dell'Account prima di contare. Senza, due salvataggi
     * insieme leggerebbero ciascuno «c'è ancora un posto» e lo occuperebbero
     * entrambi; con due import il tetto si supererebbe di centinaia. Il lock è
     * sull'account e non sulla sede perché col conteggio per cliente le sedi si
     * contendono lo stesso tetto.
     *
     * @throws TettoStrumentiRaggiunto
     */
    public static function esigi(int $enteId, int $nuovi = 1): void
    {
        $tetto = self::leggi($enteId, blocca: true);

        if (! $tetto->consente($nuovi)) {
            throw new TettoStrumentiRaggiunto($tetto->spiegazione($nuovi));
        }
    }

    /**
     * Quanti se ne possono ancora aggiungere, o `null` se non c'è tetto.
     * Negativo quando si è **sopra**: vedi il docblock di classe.
     */
    public function residui(): ?int
    {
        return $this->massimo === null ? null : $this->massimo - $this->presenti;
    }

    public function consente(int $nuovi = 1): bool
    {
        $residui = $this->residui();

        return $residui === null || $nuovi <= $residui;
    }

    /**
     * La frase per chi si vede rifiutare: dice i numeri e che cosa fare.
     *
     * Un rifiuto senza numeri lascia chi legge a chiedersi quanti strumenti
     * avrebbe diritto di avere: è la stessa lezione del tetto di sedi.
     */
    public function spiegazione(int $nuovi = 1): string
    {
        $tetto = "Il piano {$this->piano} include ".$this->conteggio->tetto($this->massimo);

        $presenti = $this->conteggio === ConteggioStrumenti::PerSede
            ? "questa sede ne ha già {$this->presenti}"
            : "le sedi del contratto ne hanno già {$this->presenti}";

        if ($nuovi === 1) {
            return "{$tetto}, e {$presenti}. Per aggiungerne altri serve un piano più ampio.";
        }

        $residui = max(0, (int) $this->residui());
        $posto = $residui === 0 ? 'non ce ne stanno altri' : 'ce ne '.($residui === 1 ? 'sta' : 'stanno')." ancora {$residui}";

        return "Il file aggiungerebbe {$nuovi} strumenti, ma non c'è posto per tutti. {$tetto}, e {$presenti}: {$posto}. "
            .'Togli righe dal file o passa a un piano più ampio.';
    }

    private static function leggi(int $enteId, bool $blocca): self
    {
        $accountId = DB::table('unita_organizzativa')->where('id', $enteId)->value('account_id');
        $piano = $accountId === null ? null : DB::table('accounts')->where('id', $accountId)->value('piano');

        if ($piano === null || ! Piani::esiste($piano) || Piani::maxStrumenti($piano) === null) {
            return new self(null, 0, ConteggioStrumenti::PerSede, '');
        }

        // Solo adesso, cioè solo quando un tetto c'è: su un piano illimitato
        // la creazione di uno strumento non prende nessun lock in più.
        if ($blocca) {
            DB::table('accounts')->where('id', $accountId)->lockForUpdate()->first(['id']);
        }

        $conteggio = Piani::conteggioStrumenti($piano);

        $presenti = DB::table('strumenti')
            ->whereNull('deleted_at')
            ->when(
                $conteggio === ConteggioStrumenti::PerSede,
                fn ($q) => $q->where('tenant_id', $enteId),
                // Le sedi cestinate non contano: una sede chiusa non deve
                // occupare per sempre il tetto di quelle aperte.
                fn ($q) => $q->whereIn('tenant_id', DB::table('unita_organizzativa')
                    ->where('account_id', $accountId)
                    ->whereNull('deleted_at')
                    ->select('id')),
            )
            ->count();

        return new self(Piani::maxStrumenti($piano), $presenti, $conteggio, Piani::etichetta($piano));
    }
}

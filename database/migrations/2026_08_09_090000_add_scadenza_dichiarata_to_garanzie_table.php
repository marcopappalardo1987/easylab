<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scadenza DICHIARATA della garanzia (ERD §6.1 — ADR-022, emenda ADR-019).
     *
     * ⚠️ **Questo non riapre nulla di ciò che ADR-019 ha chiuso.** ADR-019 ha
     * eliminato la garanzia *a ore*: con essa sono spariti `tipo_scadenza`,
     * `soglia_ore`, `data_scadenza_prevista` e le letture contaore, e restano
     * spariti. La garanzia continua a scadere **solo a data**, e
     * `data_scadenza_effettiva` continua a essere l'unico campo che pilota
     * semaforo e notifiche.
     *
     * Cambia una cosa sola, e la chiede ADR-022: nel form intervento
     * l'operatore scrive «scadenza garanzia del pezzo», cioè **una data**, non
     * un numero di mesi. Convertirla in mesi al salvataggio ripeterebbe
     * l'errore già misurato dal backfill di ADR-019 — l'intero N più vicino
     * sposta la scadenza di giorni — ma là era uno scarto accettato una volta
     * su righe storiche, qui sarebbe una falsificazione sistematica su ogni
     * pezzo registrato, di un dato che è contrattuale e che accende l'arancione
     * a 30 giorni.
     *
     * `durata_mesi` torna quindi nullable perché **una delle due forme di input
     * è nulla per costruzione**, non perché la durata sia diventata
     * facoltativa: vale l'invariante «esattamente uno fra `durata_mesi` e
     * `data_scadenza_dichiarata`», imposto in `Garanzia::normalizzaScadenza()`.
     * Il NOT NULL non è stato indebolito, è stato **spostato di livello** —
     * dalla colonna alla coppia.
     *
     * ⚠️ Il nome NON è `data_scadenza_prevista`: quella colonna la ricrea il
     * `down()` di `2026_08_05_090200`, e un rollback su un DB già migrato
     * esploderebbe con "column already exists" — cioè nel momento peggiore.
     *
     * Due `Schema::table` separate di proposito: su SQLite un `change()`
     * ricostruisce la tabella, e mescolarlo con l'add nella stessa closure è la
     * forma che la migration di ADR-019 tiene già divisa per lo stesso motivo.
     */
    public function up(): void
    {
        Schema::table('garanzie', function (Blueprint $table) {
            $table->date('data_scadenza_dichiarata')->nullable()->after('durata_mesi');
        });

        Schema::table('garanzie', function (Blueprint $table) {
            $table->integer('durata_mesi')->nullable()->change();
        });
    }

    /**
     * Rollback a perdita **dichiarata**, come le migration di ADR-019: le righe
     * nate con una scadenza dichiarata non hanno una durata, e rimettere il
     * NOT NULL senza convertirle le renderebbe non salvabili. Si converte con
     * l'intero più vicino (lo stesso algoritmo del backfill di ADR-019, clamp a
     * 1) accettando lo scarto di giorni — che è esattamente ciò che questa
     * migration esiste per evitare in avanti.
     */
    public function down(): void
    {
        DB::table('garanzie')
            ->whereNull('durata_mesi')
            ->whereNotNull('data_scadenza_dichiarata')
            ->orderBy('id')
            ->each(function (object $riga) {
                $inizio = CarbonImmutable::parse($riga->data_inizio);
                $fine = CarbonImmutable::parse($riga->data_scadenza_dichiarata);
                $mesi = max(1, (int) floor($inizio->diffInMonths($fine)));

                if ($inizio->addMonths($mesi + 1)->diffInDays($fine, true) < $inizio->addMonths($mesi)->diffInDays($fine, true)) {
                    $mesi++;
                }

                DB::table('garanzie')->where('id', $riga->id)->update([
                    'durata_mesi' => $mesi,
                    'data_scadenza_effettiva' => $inizio->addMonths($mesi)->toDateString(),
                ]);
            });

        Schema::table('garanzie', function (Blueprint $table) {
            $table->dropColumn('data_scadenza_dichiarata');
        });

        Schema::table('garanzie', function (Blueprint $table) {
            $table->integer('durata_mesi')->nullable(false)->change();
        });
    }
};

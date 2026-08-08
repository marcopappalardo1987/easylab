<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ripara le garanzie rimaste con `durata_mesi = 0` (ADR-019).
     *
     * **Perché esiste.** La prima versione del backfill in
     * `drop_garanzia_a_ore_from_garanzie_table` non aveva il clamp a 1 mese, e
     * sulle righe la cui data prevista precedeva `data_inizio` — incoerenza che
     * il vecchio modello permetteva — produceva `durata_mesi = 0`: una garanzia
     * che scade il giorno in cui inizia, valore che il form (`min:1`) non
     * potrebbe mai creare. Il backfill è stato corretto, ma era **già girato**
     * sul database di sviluppo: le correzioni di dati vanno in avanti, non
     * riscrivendo una migration già applicata.
     *
     * Su un database creato da zero questa migration non trova nulla da fare —
     * il backfill corretto non produce più zeri — ed è quindi un **no-op
     * idempotente**, non un passo che si può dimenticare di eseguire.
     *
     * Restano garanzie già scadute, che è esattamente ciò che erano: cambia
     * solo che ora sono rappresentabili nell'unico formato ammesso.
     */
    public function up(): void
    {
        $righe = DB::table('garanzie')->where('durata_mesi', '<', 1)
            ->select('id', 'data_inizio')->get();

        foreach ($righe as $riga) {
            DB::table('garanzie')->where('id', $riga->id)->update([
                'durata_mesi' => 1,
                'data_scadenza_effettiva' => CarbonImmutable::parse($riga->data_inizio)
                    ->startOfDay()->addMonth()->toDateString(),
            ]);
        }
    }

    /**
     * Nessun rollback: riportare quelle righe a `durata_mesi = 0` significa
     * ricreare di proposito uno stato che il dominio non ammette.
     */
    public function down(): void
    {
        // volutamente vuoto
    }
};

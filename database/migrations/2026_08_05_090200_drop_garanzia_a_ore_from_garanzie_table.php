<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eliminazione della garanzia "a ore" (ADR-019): era un fraintendimento del
     * briefing di scoping, e il cliente ha chiarito che nel suo dominio non
     * esiste. Resta un'unica forma di garanzia: `data_inizio + durata_mesi`.
     *
     * ⚠️ MIGRATION DISTRUTTIVA — l'ordine dei due passi non è negoziabile.
     *
     * 1. BACKFILL. Le righe `tipo_scadenza = ore` hanno `durata_mesi` NULL (la
     *    loro scadenza viveva in `data_scadenza_prevista`). Se si droppasse
     *    prima, resterebbero righe con durata NULL su una colonna che diventa
     *    NOT NULL, e `Garanzia::normalizzaScadenza()` esploderebbe al primo
     *    salvataggio successivo.
     * 2. DROP delle tre colonne e NOT NULL su `durata_mesi`.
     *
     * **Il compromesso, dichiarato.** Una data arbitraria non è sempre
     * esprimibile come "N mesi esatti da data_inizio": si sceglie l'intero N
     * che avvicina di più `data_inizio + N mesi` alla data prevista, e si
     * riallinea `data_scadenza_effettiva` a quel valore. Le scadenze di quelle
     * righe possono quindi spostarsi di qualche giorno. È preferibile alla
     * sola scrittura di `durata_mesi`: quella lascerebbe in tabella una
     * `data_scadenza_effettiva` che il modello non sa più riprodurre, e che
     * cambierebbe da sola al primo salvataggio — una bomba a orologeria
     * invisibile invece di uno scarto noto e misurato adesso.
     */
    public function up(): void
    {
        $this->backfillDurataMesi();

        Schema::table('garanzie', function (Blueprint $table) {
            $table->dropColumn(['tipo_scadenza', 'soglia_ore', 'data_scadenza_prevista']);
        });

        Schema::table('garanzie', function (Blueprint $table) {
            $table->integer('durata_mesi')->nullable(false)->change();
        });
    }

    /**
     * Query builder e non Eloquent: il modello `Garanzia` ha già perso l'enum
     * `TipoScadenzaGaranzia` e non saprebbe idratare le righe da correggere.
     * Vale anche il global scope sulla privacy dei ricambi, che qui va evitato.
     */
    private function backfillDurataMesi(): void
    {
        $righe = DB::table('garanzie')
            ->whereNull('durata_mesi')
            ->select('id', 'data_inizio', 'data_scadenza_effettiva')
            ->get();

        foreach ($righe as $riga) {
            $inizio = CarbonImmutable::parse($riga->data_inizio)->startOfDay();
            $fine = CarbonImmutable::parse($riga->data_scadenza_effettiva)->startOfDay();

            DB::table('garanzie')->where('id', $riga->id)->update([
                'durata_mesi' => $mesi = self::mesiPiuVicini($inizio, $fine),
                'data_scadenza_effettiva' => $inizio->addMonths($mesi)->toDateString(),
            ]);
        }
    }

    /**
     * Numero intero di mesi (>= 1) che avvicina di più `$inizio + N mesi` a
     * `$fine`.
     *
     * Si confrontano il troncamento e il suo successore invece di dividere per
     * una lunghezza media del mese: i mesi hanno lunghezze diverse e
     * `addMonths` ha una sua semantica sui fine mese (31 gennaio + 1 mese =
     * 28 febbraio). L'unico modo di sapere quale N è più vicino è provarli.
     *
     * `floor()` esplicito perché in Carbon 3 `diffInMonths` restituisce un
     * **float**: lasciarlo tale porterebbe `addMonths()` a ricevere frazioni di
     * mese, con troncamenti impliciti diversi fra i due rami del confronto.
     *
     * **Minimo 1 mese, non 0.** Nei dati esistono garanzie "a ore" la cui data
     * prevista PRECEDE `data_inizio`: incoerenza che il vecchio modello
     * permetteva, perché per quelle righe la scadenza non era legata
     * all'inizio. Senza il clamp uscirebbe `durata_mesi = 0` — una garanzia che
     * scade il giorno in cui inizia, cioè nessuna garanzia, e per giunta un
     * valore che il form (`min:1`) non potrebbe mai produrre. Restano garanzie
     * già scadute, che è ciò che erano: solo, ora sono rappresentabili.
     */
    private static function mesiPiuVicini(CarbonImmutable $inizio, CarbonImmutable $fine): int
    {
        $basso = max(0, (int) floor($inizio->diffInMonths($fine, absolute: false)));

        $scartoBasso = $inizio->addMonths($basso)->diffInDays($fine, absolute: true);
        $scartoAlto = $inizio->addMonths($basso + 1)->diffInDays($fine, absolute: true);

        return max(1, $scartoAlto < $scartoBasso ? $basso + 1 : $basso);
    }

    /**
     * Il rollback ricrea le colonne **vuote**: le garanzie a ore che c'erano
     * prima sono state convertite in garanzie a data e non sono distinguibili
     * dalle altre. Ripristinare la forma della tabella è possibile, ripristinare
     * l'informazione no — per quella serve il dump preso prima della migration.
     */
    public function down(): void
    {
        Schema::table('garanzie', function (Blueprint $table) {
            $table->integer('durata_mesi')->nullable()->change();
        });

        Schema::table('garanzie', function (Blueprint $table) {
            $table->string('tipo_scadenza')->default('data');
            $table->integer('soglia_ore')->nullable();
            $table->date('data_scadenza_prevista')->nullable();
        });
    }
};

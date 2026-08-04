<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rimappatura di `interventi.tipo` all'elenco fissato dal cliente
     * (ADR-021, briefing 3 Ago 2026).
     *
     * `interventi.tipo` è una `string` senza CHECK (ERD §5.2): il vincolo vive
     * nell'enum PHP, quindi qui basta un UPDATE — nessuna ALTER di tipo.
     *
     * ⚠️ ORDINE OBBLIGATO. Questo UPDATE deve girare **prima** che il codice
     * smetta di poter leggere i vecchi valori: `Intervento` casta `tipo` a
     * `TipoIntervento`, e un `from('ispezione')` su una riga non convertita
     * lancerebbe `ValueError` a ogni lettura. Per questo l'UPDATE è scritto in
     * SQL puro con il query builder e non passa da Eloquent: il modello, con
     * l'enum già aggiornato, non riuscirebbe nemmeno a idratare le righe che
     * deve correggere.
     *
     * Mappatura (ADR-021): `ispezione` è lavoro di controllo programmato →
     * ordinaria; `riparazione` è lavoro non programmato su guasto →
     * straordinaria; `manutenzione` generica, che non distingueva i regimi,
     * diventa il caso più frequente → ordinaria.
     */
    private const MAPPA = [
        'manutenzione' => 'manutenzione_ordinaria',
        'ispezione' => 'manutenzione_ordinaria',
        'riparazione' => 'manutenzione_straordinaria',
    ];

    public function up(): void
    {
        foreach (self::MAPPA as $vecchio => $nuovo) {
            DB::table('interventi')->where('tipo', $vecchio)->update(['tipo' => $nuovo]);
        }
    }

    /**
     * Il rollback è **volutamente parziale e dichiarato tale**: la mappatura in
     * avanti è a più-a-uno (`manutenzione` e `ispezione` collassano entrambe su
     * `manutenzione_ordinaria`), quindi l'informazione originale non è
     * ricostruibile. Si torna al valore generico `manutenzione`, che è ciò che
     * l'enum precedente avrebbe comunque accettato.
     *
     * Le righe create dopo questa migration con i tre nuovi regimi non
     * esistevano nel vecchio enum: `full_risk` non ha un antenato e regredisce
     * anch'essa a `manutenzione`, mentre `certificazione` — che nel vecchio
     * elenco non aveva corrispondenza — finisce in `altro`.
     */
    public function down(): void
    {
        DB::table('interventi')
            ->whereIn('tipo', ['manutenzione_ordinaria', 'manutenzione_straordinaria', 'manutenzione_full_risk'])
            ->update(['tipo' => 'manutenzione']);

        DB::table('interventi')->where('tipo', 'certificazione')->update(['tipo' => 'altro']);
    }
};

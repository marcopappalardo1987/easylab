<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'account che è EasyLab stessa, distinto dai clienti (ERD §4.3, S6).
     *
     * Il `SuperadminSeeder` crea un Account e un Ente **di piattaforma**, perché
     * il TenantScope è fail-closed (ADR-018) e un Superadmin senza Ente
     * entrerebbe e troverebbe l'app vuota. Quell'account però è indistinguibile
     * da un cliente, e nella cabina di regia di S6 falserebbe **tutti e quattro**
     * i KPI: «Clienti» +1, «Sedi» +1, «Strumenti» + quelli suoi, e l'MRR
     * verrebbe salvato solo dal fatto che il piano è Free.
     *
     * ⚠️ **Perché una colonna e non un confronto sul nome a ogni query.**
     * L'alternativa era riconoscerlo ogni volta dalla ragione sociale, contro
     * `config('easylab.piattaforma.superadmin.ente')`: una stringa proveniente
     * dall'ambiente, dentro una query aggregata, su un valore che su ogni
     * installazione è diverso. Un flag esplicito costa una migration e poi non
     * va più interrogato.
     *
     * *(Il backfill qui sotto usa proprio quel confronto — una volta sola, in
     * una migration. Non è una contraddizione: ciò che va evitato è farlo
     * diventare un filtro permanente in ogni conteggio, non usarlo per timbrare
     * una riga. La distinzione è stata chiarita dal confronto sul blocco A.)*
     *
     * Fuori da `$fillable`, come `piano` e le colonne di lockout: lo scrive il
     * seeder, non un form.
     *
     * Migration **additiva**, default `false`: ogni account esistente resta un
     * cliente, che è la lettura giusta per tutti tranne uno — e quell'uno lo
     * sistema il backfill qui sotto.
     *
     * ⚠️ **Il backfill non è un di più: senza, il flag non arriva mai sugli
     * ambienti che esistono già.** `php artisan migrate` porta lo schema, non i
     * dati (CLAUDE.md), e il `SuperadminSeeder` che marca l'account va rilanciato
     * a mano — cosa che nessun test segnala e che su un ambiente senza
     * `SUPERADMIN_PASSWORD` in build non farebbe comunque nulla. Il risultato
     * sarebbe una cabina di regia che conta EasyLab fra i clienti su tutti e tre
     * gli ambienti reali, in silenzio.
     *
     * Sul confronto per ragione sociale: è la **stessa chiave** che il
     * `SuperadminSeeder` usa nel suo `firstOrCreate`. Se è abbastanza affidabile
     * per creare quell'account, lo è per marcarlo una volta sola. (Ed è una
     * scrittura one-shot in una migration, non un filtro in una query
     * aggregata — che era l'obiezione vera.)
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Non indicizzata: un filtro che tiene il 99,9% delle righe non usa
            // un indice, e le query della cabina aggregano comunque su tutta la
            // tabella. (Diversa dal caso di `piano`, indicizzata due ore prima
            // per la stessa dashboard: lì il filtro è selettivo.)
            //
            // Niente `->after()`: è un modificatore che **solo MySQL** onora —
            // Postgres e SQLite lo ignorano in silenzio — e questo progetto ha
            // già deciso di non scriverlo (vedi il commento in
            // `2026_08_09_090200_add_visibilita_garanzie_ricambio…`). Una
            // garanzia che nessuno dei due driver in uso fornisce è rumore che
            // si legge come promessa.
            $table->boolean('di_piattaforma')->default(false);
        });

        // ⚠️ **Si risale dalla struttura, non dal nome.** La prima stesura
        // confrontava la ragione sociale con
        // `config('easylab.piattaforma.superadmin.ente')` e, applicata al
        // database di sviluppo, **non ha trovato niente**: lì l'account si
        // chiama «EasyLab (piattaforma)» e la config dice «EasyLab». Mancato per
        // un suffisso, in silenzio — che è esattamente il modo in cui questo
        // backfill sarebbe stato inutile proprio dove serviva.
        //
        // La via strutturale non dipende da come qualcuno ha battuto un nome:
        // chi ha il ruolo Superadmin → il suo Ente (`users.tenant_id`) → il suo
        // account. È la stessa catena che regge l'accesso, quindi se è sbagliata
        // l'app non funziona comunque.
        $accountDiPiattaforma = DB::table('unita_organizzativa')
            ->whereIn('id', DB::table('users')
                ->whereIn('id', DB::table('model_has_roles')
                    ->where('model_type', 'App\Models\User')
                    ->whereIn('role_id', DB::table('roles')->where('name', 'Superadmin')->select('id'))
                    ->select('model_id'))
                ->select('tenant_id'))
            ->whereNotNull('account_id')
            ->pluck('account_id');

        if ($accountDiPiattaforma->isNotEmpty()) {
            DB::table('accounts')->whereIn('id', $accountDiPiattaforma)->update(['di_piattaforma' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('di_piattaforma');
        });
    }
};

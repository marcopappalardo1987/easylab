<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `account_id` sul nodo ente + backfill 1:1 (ERD §4.1/§4.3 — ADR-032).
     *
     * Tre passi nell'ordine obbligato del precedente `add_qr_token`:
     * colonna nullable → backfill → (nessun vincolo finale: la colonna RESTA
     * nullable, perché d'ora in poi la valorizza il provisioning e i nodi
     * non-ente non la usano — la guardia «solo sui nodi ente» vive nel model,
     * dove vale per CRUD, console e seeder; a DB un CHECK ramificherebbe per
     * driver, SQLite non ha ADD CONSTRAINT).
     *
     * **Backfill**: ogni Ente esistente ottiene il suo account (ragione sociale
     * = nome dell'Ente) e come membri gli utenti del suo tenant con ruolo
     * Admin; se non ce ne sono, i Superadmin del tenant (è il caso dell'ente
     * «EasyLab (piattaforma)» del DB di sviluppo); se nemmeno quelli, l'account
     * nasce senza membri e lo si scrive nel log — l'invariante «ogni account ha
     * almeno un membro» vale da domani in avanti, non retroattivamente.
     * Solo query builder, mai il model: gli eventi Eloquent (audit compreso) e
     * i cast non devono toccare un riallineamento di schema.
     *
     * ⚠️ In test il backfill gira su zero righe (SQLite ricreato da zero) e
     * passerebbe verde anche sbagliato: `AccountBackfillTest` ricostruisce
     * apposta lo stato pre-migrazione, e comunque sul DB di sviluppo va
     * verificato a mano — `select count(*) from unita_organizzativa where
     * tipo = 'ente' and account_id is null` deve dare 0.
     */
    public function up(): void
    {
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            // restrict di default sul delete: un account con Enti non si
            // cancella hard (il soft delete del model non arriva mai qui).
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->index('account_id');
        });

        $this->backfill();
    }

    /**
     * Pubblico e separato dallo schema per essere TESTABILE: la suite non può
     * far girare `up()` su righe preesistenti (SQLite in transazione non regge
     * il rollback che ricostruisce una tabella molto referenziata), ma può
     * chiamare questo metodo su Enti veri con `account_id` ancora nullo —
     * `AccountBackfillTest` fa esattamente questo, sullo stesso codice che
     * girerà in produzione. Il `whereNull` è anche la guardia di idempotenza.
     */
    public function backfill(): void
    {
        DB::table('unita_organizzativa')
            ->where('tipo', 'ente')
            ->whereNull('account_id')
            ->orderBy('id')
            ->chunkById(100, function ($enti): void {
                foreach ($enti as $ente) {
                    $accountId = DB::table('accounts')->insertGetId([
                        'ragione_sociale' => $ente->nome,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('unita_organizzativa')
                        ->where('id', $ente->id)
                        ->update(['account_id' => $accountId]);

                    $membri = $this->membriPer($ente->id);
                    if ($membri === []) {
                        Log::warning("Backfill ADR-032: l'ente {$ente->id} («{$ente->nome}») non ha né Admin né Superadmin — account {$accountId} senza membri.");

                        continue;
                    }

                    foreach ($membri as $userId) {
                        DB::table('account_user')->insert([
                            'account_id' => $accountId,
                            'user_id' => $userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    /**
     * Gli utenti del tenant col ruolo dato: Admin, o Superadmin in mancanza.
     *
     * @return list<int>
     */
    private function membriPer(int $tenantId): array
    {
        foreach (['Admin', 'Superadmin'] as $ruolo) {
            $ids = DB::table('users')
                ->join('model_has_roles', function ($join) {
                    $join->on('model_has_roles.model_id', '=', 'users.id')
                        ->where('model_has_roles.model_type', 'App\\Models\\User');
                })
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('users.tenant_id', $tenantId)
                ->where('roles.name', $ruolo)
                ->pluck('users.id')
                ->all();

            if ($ids !== []) {
                return array_map('intval', $ids);
            }
        }

        return [];
    }

    public function down(): void
    {
        // Prima l'indice, poi la colonna: su SQLite il drop della colonna
        // ricostruisce la tabella, e un indice che referenzia una colonna
        // appena sparita fa fallire la ricostruzione.
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
        });
        Schema::table('unita_organizzativa', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
        // Gli account creati dal backfill restano: il down toglie la colonna,
        // non riscrive la storia (principio della migration fix_garanzie).
    }
};

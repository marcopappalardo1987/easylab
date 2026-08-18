<?php

namespace App\Console\Commands;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bootstrap di un tenant: crea il nodo Ente radice, il suo Account (ADR-032) e
 * un utente Admin membro dell'account. Gira in console
 * (CurrentTenant::shouldScope() = false), quindi può creare il nodo ente con
 * tenant_id NULL e poi valorizzarlo = id (FK self-reference).
 * È il seme del provisioning Superadmin di S5 (ADR-012).
 *
 * **La risoluzione dell'account precede ogni scrittura** (ADR-032 punto 7):
 * - `--account=ID`   → l'Ente nuovo si aggancia a quell'account (il cliente
 *                      con più sedi); se l'id non esiste, FAILURE senza
 *                      scritture;
 * - email nuova      → account 1:1 nuovo, ragione sociale = nome dell'Ente
 *                      (il comportamento storico del comando);
 * - email esistente  → il caso che prima era un bug MUTO (`firstOrCreate`
 *                      trovava l'utente e scartava il tenant_id in silenzio):
 *                      ora è semantica — l'Ente si aggancia all'account di
 *                      quell'utente se ne ha uno solo; con più account serve
 *                      `--account=` (l'ambiguità non si indovina); senza
 *                      account ne nasce uno con lui come membro. In ogni caso
 *                      il `tenant_id` dell'utente esistente NON si tocca:
 *                      l'Ente nuovo si raggiunge con lo switcher.
 *
 * Tutto in transazione: con cinque scritture (account, ente, tenant_id,
 * utente, pivot) un fallimento a metà lascerebbe un account orfano o un ente
 * senza intestatario.
 *
 * Il limite di Enti per piano («chi paga di più gestisce più Enti») si farà
 * rispettare QUI quando il piano esisterà — cioè col blocco Cashier.
 */
class ProvisionTenant extends Command
{
    protected $signature = 'easylab:provision-tenant
        {nome : Ragione sociale dell\'Ente}
        {--account= : ID dell\'account esistente a cui agganciare l\'Ente}
        {--admin-email= : Email dell\'utente Admin}
        {--admin-name= : Nome dell\'utente Admin}
        {--admin-password= : Password (se omessa viene generata)}';

    protected $description = 'Crea un Ente (tenant) col suo Account e l\'utente Admin';

    public function handle(): int
    {
        $nome = $this->argument('nome');
        $adminEmail = $this->option('admin-email') ?: Str::slug($nome).'-admin@example.test';
        $adminName = $this->option('admin-name') ?: "Admin {$nome}";
        $generatedPassword = $this->option('admin-password') ?: Str::password(16);

        // Risoluzione PRIMA di scrivere qualunque cosa.
        $accountEsistente = null;

        if ($this->option('account') !== null) {
            $accountEsistente = Account::find($this->option('account'));
            if ($accountEsistente === null) {
                $this->error("Nessun account con id {$this->option('account')}.");

                return self::FAILURE;
            }
        } else {
            $utenteEsistente = User::where('email', $adminEmail)->first();

            if ($utenteEsistente !== null) {
                $suoi = $utenteEsistente->accounts()->get();

                if ($suoi->count() > 1) {
                    $this->error(
                        "{$adminEmail} è membro di {$suoi->count()} account (".
                        $suoi->pluck('id')->implode(', ').
                        '): specificare --account=.'
                    );

                    return self::FAILURE;
                }

                $accountEsistente = $suoi->first();
            }
        }

        [$ente, $admin, $account, $accountNuovo] = DB::transaction(function () use ($nome, $adminEmail, $adminName, $generatedPassword, $accountEsistente) {
            $account = $accountEsistente ?? Account::create(['ragione_sociale' => $nome]);

            $ente = UnitaOrganizzativa::create([
                'tipo' => TipoUnitaOrganizzativa::Ente,
                'nome' => $nome,
                'parent_id' => null,
            ]);
            $ente->forceFill([
                'tenant_id' => $ente->id,
                'account_id' => $account->id,
            ])->saveQuietly();

            $admin = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => $adminName,
                    'password' => Hash::make($generatedPassword),
                    'email_verified_at' => now(),
                ],
            );

            // `tenant_id` è fuori dal Fillable (ADR-032) e si scrive SOLO
            // sull'utente appena nato: riscriverlo a uno esistente lo
            // strapperebbe al suo Ente per effetto collaterale — l'Ente nuovo
            // lo raggiunge con lo switcher.
            if ($admin->wasRecentlyCreated) {
                $admin->forceFill(['tenant_id' => $ente->id])->save();
            }

            if (! $admin->hasRole('Admin')) {
                $admin->assignRole('Admin');
            }

            $account->aggiungiMembro($admin);

            return [$ente, $admin, $account, $accountEsistente === null];
        });

        $this->info("Ente «{$nome}» creato (id {$ente->id}).");
        $this->info("Account: «{$account->ragione_sociale}» (id {$account->id}, ".($accountNuovo ? 'nuovo' : 'esistente').').');
        $this->info("Admin: {$adminEmail}".($admin->wasRecentlyCreated ? '' : ' (utente esistente: resta sul suo Ente, il nuovo si raggiunge con lo switcher)'));
        if ($admin->wasRecentlyCreated && ! $this->option('admin-password')) {
            $this->warn("Password generata: {$generatedPassword}");
        }

        return self::SUCCESS;
    }
}

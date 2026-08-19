<?php

namespace Database\Seeders;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Account Superadmin di piattaforma — il proprietario di EasyLab (ERD §4.1/4.2).
 *
 * Nasce insieme al proprio Account e al proprio Ente perché **non potrebbe
 * esistere senza**: il `TenantScope` è fail-closed (ADR-018) e un utente
 * autenticato con `tenant_id` NULL non vede nulla, quindi un Superadmin senza
 * Ente sarebbe un account che entra e trova l'app vuota. Il cross-tenant lo
 * raggiunge per impersonazione, non per assenza di confine.
 *
 * L'Ente si costruisce come nel provisioning (ADR-032: l'Account sta sopra
 * l'Ente, il nodo radice È il tenant e punta a se stesso).
 *
 * **Deroga consapevole ad ADR-012.** Il provisioning non consegna password:
 * invita e lascia scegliere. Qui la password si consegna, perché questo è
 * l'account che deve poter entrare quando *non esiste ancora nessuno* che
 * possa invitarlo — stessa eccezione già ammessa per il Developer. Il prezzo è
 * che la password vive in una variabile d'ambiente: non finisce mai nel repo,
 * e senza quelle variabili il seeder **non crea nulla** invece di ripiegare su
 * un valore di default (l'errore che il Developer si porta dietro da S0).
 *
 * Idempotente: rilanciarlo aggiorna la password di chi c'è già, non duplica.
 */
class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('easylab.piattaforma.superadmin.email');
        $password = config('easylab.piattaforma.superadmin.password');
        $nomeEnte = config('easylab.piattaforma.superadmin.ente');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'SUPERADMIN_EMAIL/SUPERADMIN_PASSWORD assenti: Superadmin non creato. '.
                'Impostarle nell\'ambiente, RIDISTRIBUIRE (la config è cachata in build) '.
                'e rilanciare `db:seed --class=SuperadminSeeder`.'
            );

            return;
        }

        DB::transaction(function () use ($email, $password, $nomeEnte) {
            $account = Account::firstOrCreate(['ragione_sociale' => $nomeEnte]);

            $ente = UnitaOrganizzativa::where('tipo', TipoUnitaOrganizzativa::Ente)
                ->where('nome', $nomeEnte)
                ->first();

            if ($ente === null) {
                $ente = UnitaOrganizzativa::create([
                    'tipo' => TipoUnitaOrganizzativa::Ente,
                    'nome' => $nomeEnte,
                    'parent_id' => null,
                ]);

                // Il nodo radice è il proprio tenant (ADR-006): `tenant_id` e
                // `account_id` stanno fuori dal fillable e si scrivono qui.
                $ente->forceFill([
                    'tenant_id' => $ente->id,
                    'account_id' => $account->id,
                ])->saveQuietly();
            }

            $superadmin = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => config('easylab.piattaforma.superadmin.nome'),
                    'password' => Hash::make($password),
                ],
            );

            // Come nel provisioning: `tenant_id` si scrive SOLO sull'utente
            // appena nato. Riscriverlo a uno esistente lo strapperebbe al suo
            // Ente per effetto collaterale.
            if ($superadmin->wasRecentlyCreated) {
                $superadmin->forceFill([
                    'tenant_id' => $ente->id,
                    'email_verified_at' => now(),
                ])->save();
            }

            if (! $superadmin->hasRole('Superadmin')) {
                $superadmin->assignRole('Superadmin');
            }

            $account->aggiungiMembro($superadmin);

            $this->command?->info("Superadmin: {$email} sull'Ente «{$nomeEnte}» (id {$ente->id}).");
        });
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\Piani;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

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
 * Il limite di Enti per piano («chi paga di più gestisce più Enti») si fa
 * rispettare qui, prima della transazione (ADR-032, blocco Cashier). La regola
 * però vive su `Account::puoAggiungereEnte()`: questo comando la consuma, e la
 * UI di provisioning di S6 riuserà lo stesso metodo invece di riscriverla.
 *
 * **L'Admin nuovo viene invitato, non gli si consegna una password** (ADR-012):
 * senza `--admin-password` nasce con un tappo random di 64 caratteri che
 * nessuno vede, `email_verified_at` a null, e riceve una mail con un link
 * firmato di 7 giorni per scegliere la propria. Rilanciare lo stesso comando su
 * un invitato che non ha ancora attivato **reinvia** l'invito: non serve un
 * comando a parte. `--admin-password` resta per il lavoro in locale e per i
 * test, e conserva il comportamento storico.
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

        // Senza `--admin-password` l'utente nasce **invitato**: la password è un
        // tappo di 64 caratteri che nessuno vedrà mai — non un segreto da
        // custodire — e la password vera la scelge lui dal link firmato
        // (ADR-012). Con l'opzione resta il comportamento storico, utile in
        // locale e nei test.
        $passwordEsplicita = $this->option('admin-password');
        $passwordIniziale = $passwordEsplicita ?: Str::password(64);

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

        // Il limite di Enti del piano (ADR-032), che il docblock qui sopra
        // prenotava dal blocco S4. Sta **dopo** la risoluzione dell'account e
        // **prima** della transazione, come le altre guardie del comando: chi
        // viene rifiutato non deve lasciare né un account né un utente dietro
        // di sé, e c'è già un test che lo verifica contando le righe.
        //
        // La decisione vive su `Account::puoAggiungereEnte()` e non qui: in S6
        // il provisioning diventa una UI Livewire, e una guardia scritta dentro
        // `handle()` non sarebbe lì. Un account nuovo (nessun `--account`, email
        // mai vista) non ha Enti, quindi passa sempre — ma il controllo si fa
        // lo stesso, senza ramo condizionale: un `max_enti` a 0, se un giorno
        // esistesse, dev'essere rispettato anche dal primo Ente.
        $accountPerLimite = $accountEsistente ?? new Account;

        if (! $accountPerLimite->puoAggiungereEnte()) {
            $max = Piani::maxEnti($accountPerLimite->piano);

            $this->error(
                "«{$accountPerLimite->ragione_sociale}» (id {$accountPerLimite->id}) è sul piano ".
                Piani::etichetta($accountPerLimite->piano).
                ": {$accountPerLimite->enti()->count()} Enti su {$max}, limite raggiunto."
            );
            $this->line('Passare a un piano superiore (easylab:abbona) o cestinare una sede prima di aggiungerne un\'altra.');

            return self::FAILURE;
        }

        [$ente, $admin, $account, $accountNuovo] = DB::transaction(function () use ($nome, $adminEmail, $adminName, $passwordIniziale, $passwordEsplicita, $accountEsistente) {
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
                    'password' => Hash::make($passwordIniziale),
                ],
            );

            // `tenant_id` è fuori dal Fillable (ADR-032) e si scrive SOLO
            // sull'utente appena nato: riscriverlo a uno esistente lo
            // strapperebbe al suo Ente per effetto collaterale — l'Ente nuovo
            // lo raggiunge con lo switcher.
            //
            // ⚠️ `email_verified_at` sta anch'esso fuori dal Fillable, e va
            // scritto QUI: passarlo a `firstOrCreate` non funzionava — il
            // mass-assignment lo scartava in silenzio, e ogni utente creato dal
            // provisioning restava non verificato contro l'intenzione di chi
            // l'aveva scritto. Difetto latente trovato dai test dell'invito.
            //
            // Con `--admin-password` l'utente è subito utilizzabile (verificato:
            // la password la conosce chi l'ha passata); senza, resta a null ed è
            // il click sul link d'invito a fare la verifica (ADR-012).
            if ($admin->wasRecentlyCreated) {
                $admin->forceFill([
                    'tenant_id' => $ente->id,
                    'email_verified_at' => $passwordEsplicita ? now() : null,
                ])->save();
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

        // L'invito parte **fuori dalla transazione**: una mail spedita per una
        // transazione poi rollbackata manderebbe qualcuno su un link che non
        // porta a nulla. E parte solo se c'è qualcuno da invitare — questa
        // unica condizione copre per costruzione i tre casi: utente nuovo senza
        // password (invito), utente già attivo agganciato a una sede nuova
        // (niente: la password ce l'ha), utente invitato in un giro precedente
        // e mai attivato (reinvio, che è il rilancio dello stesso comando).
        if ($passwordEsplicita === null && $admin->email_verified_at === null) {
            try {
                $admin->notify(new InvitoUtente($nome));
                $this->info("Invito inviato a {$adminEmail} (valido ".InvitoUtente::GIORNI_VALIDITA.' giorni).');
            } catch (Throwable $e) {
                // Il provisioning È riuscito: le scritture sono committate. Un
                // SMTP giù non deve far sembrare fallito ciò che c'è a DB —
                // basta rilanciare lo stesso comando per reinviare.
                $this->warn("Invito NON inviato ({$e->getMessage()}): rilanciare lo stesso comando per riprovare.");
            }
        }

        return self::SUCCESS;
    }
}

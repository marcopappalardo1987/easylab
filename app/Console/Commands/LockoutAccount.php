<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;

/**
 * La leva manuale del lockout per insoluto (ADR-013).
 *
 * Blocca (o sblocca) un Account: da quel momento il middleware
 * `account.lockout` chiude la navigazione a tutti gli utenti dei suoi Enti, e
 * lo switcher smette di offrirne le sedi. I dati restano intatti — il lockout
 * è una porta chiusa, non una cancellazione — e la salvaguardia GDPR resta
 * garantita dall'impersonazione, che il middleware lascia passare apposta.
 *
 * È il gesto MANUALE: l'innesco automatico sul pagamento fallito arriva col
 * blocco Cashier (webhook Stripe → `Account::blocca()`), e la UI arriva in S6
 * con la Dashboard Superadmin, dietro il permesso `billing.lockout` (set 🔒,
 * oggi dichiarato e non ancora consumato). In console non c'è utente e non
 * c'è scope (CurrentTenant::shouldScope() = false): chi ha accesso al
 * terminale ha già più di questo comando.
 *
 * Il `--motivo` è obbligatorio per bloccare: la ragione è ciò che la dashboard
 * S6 e il registro audit mostreranno — un lockout muto non si amministra.
 */
class LockoutAccount extends Command
{
    protected $signature = 'easylab:lockout
        {account : ID dell\'account}
        {--sblocca : Rimuove il lockout invece di applicarlo}
        {--motivo= : Ragione del blocco (obbligatoria quando si blocca)}';

    protected $description = 'Blocca o sblocca un Account per insoluto (ADR-013)';

    public function handle(): int
    {
        $account = Account::find($this->argument('account'));

        if ($account === null) {
            $this->error("Nessun account con id {$this->argument('account')}.");

            return self::FAILURE;
        }

        if ($this->option('sblocca')) {
            return $this->sblocca($account);
        }

        return $this->blocca($account);
    }

    private function blocca(Account $account): int
    {
        $motivo = $this->option('motivo');

        if ($motivo === null || trim($motivo) === '') {
            $this->error('Il --motivo è obbligatorio: un lockout senza ragione non si amministra.');

            return self::FAILURE;
        }

        if ($account->is_locked) {
            $this->warn("«{$account->ragione_sociale}» è già in lockout dal {$account->locked_at->format('d/m/Y')} ({$account->locked_reason}).");

            return self::SUCCESS;
        }

        $account->blocca($motivo);

        // Chi esegue deve vedere la PORTATA del gesto: quante sedi si chiudono
        // e quante persone ne amministrano il rapporto.
        $this->info(sprintf(
            'Account «%s» (id %d) in lockout: %d %s, %d membri. Motivo: %s',
            $account->ragione_sociale,
            $account->id,
            $account->enti()->count(),
            $account->enti()->count() === 1 ? 'Ente chiuso' : 'Enti chiusi',
            $account->membri()->count(),
            $motivo,
        ));

        return self::SUCCESS;
    }

    private function sblocca(Account $account): int
    {
        if (! $account->is_locked) {
            $this->warn("«{$account->ragione_sociale}» non è in lockout: nulla da fare.");

            return self::SUCCESS;
        }

        $account->sblocca();

        $this->info("Account «{$account->ragione_sociale}» (id {$account->id}) sbloccato: i suoi Enti tornano accessibili.");

        return self::SUCCESS;
    }
}

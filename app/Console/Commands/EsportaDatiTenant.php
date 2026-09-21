<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Support\Gdpr\EsportazioneNegata;
use App\Support\Gdpr\EsportazioneTenant;
use Illuminate\Console\Command;

/**
 * L'export GDPR dei dati di un Account (accesso/portabilità, ADR-013 «anche in
 * lockout, lato Superadmin»; ADR-032 punto 5: vale per tutti i suoi Enti).
 *
 * **Perché la console.** L'export attraversa per natura il confine dei tenant,
 * come `easylab:lockout` e `easylab:provision-tenant`: ADR-018 non ammette in
 * sessione nessun percorso cross-tenant che non sia l'impersonazione o una porta
 * di piattaforma progettata come tale, e questa porta per il web non esiste.
 * In console non c'è utente e non c'è scope (`CurrentTenant::shouldScope()` è
 * falso): il perimetro lo fa `PerimetroTenant` con filtri espliciti.
 *
 * **`--operatore` è obbligatorio**: chi ha il terminale ha già più di questo
 * comando, ma l'export deve avere un nome nel registro di audit e un permesso
 * dietro. Serve `tenants.view_all` (set 🔒), cioè Superadmin o Developer; la
 * guardia vive in `EsportazioneTenant::autorizza()`, non qui.
 *
 * Il file resta dove lo si scrive (di default `storage/app/private/esportazioni-gdpr/`):
 * contiene dati personali, e va tolto dopo la consegna.
 *
 * 🔗 ERD §4.3 · ADR-013, ADR-018, ADR-032.
 */
class EsportaDatiTenant extends Command
{
    protected $signature = 'easylab:esporta-tenant
        {account : ID dell\'account}
        {--operatore= : Email di chi esegue l\'export (serve il permesso tenants.view_all)}
        {--percorso= : File zip di destinazione; assente, storage/app/private/esportazioni-gdpr/}';

    protected $description = 'Esporta in uno zip tutti i dati di un Account e dei suoi Enti (GDPR, ADR-013)';

    public function handle(EsportazioneTenant $esportazione): int
    {
        $id = (string) $this->argument('account');

        // `ctype_digit` prima della query: su Postgres un id non numerico non
        // arriva a «nessun account», esplode in un 22P02 non gestito.
        $account = ctype_digit($id) ? Account::withTrashed()->find((int) $id) : null;

        if ($account === null) {
            $this->error("Nessun account con id {$this->argument('account')}.");

            return self::FAILURE;
        }

        $email = trim((string) $this->option('operatore'));
        $operatore = $email === '' ? null : User::query()->where('email', $email)->first();

        $percorso = $this->option('percorso') ?: storage_path(sprintf(
            'app/private/esportazioni-gdpr/account-%d-%s.zip',
            $account->getKey(),
            now()->format('Ymd-His'),
        ));

        // Il `finally` dell'export non gira se il processo viene ucciso: su
        // SIGTERM/SIGINT si cancellano i CSV in chiaro prima di uscire. Solo se
        // `pcntl` c'è; senza, resta la cartella di lavoro 0700 sotto storage/.
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use ($esportazione): void {
                $esportazione->interrompi();

                exit(self::FAILURE);
            });
        }

        try {
            $risultato = $esportazione->esegui($account, $operatore, $percorso);
        } catch (EsportazioneNegata $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Export di «{$account->ragione_sociale}» (id {$account->getKey()}) scritto in {$risultato->percorso}");
        $this->line("sha256 {$risultato->sha256} · {$risultato->byte} byte · {$risultato->documenti} documenti");
        $this->table(['file', 'righe'], collect($risultato->righe)->map(fn ($n, $t) => [$t, $n])->values()->all());

        if ($risultato->documentiMancanti !== []) {
            $this->warn('Documenti senza file leggibile (elencati nel manifest): '.implode(', ', $risultato->documentiMancanti));
        }

        $this->warn('Il file contiene dati personali: cancellalo dopo la consegna.');

        return self::SUCCESS;
    }
}

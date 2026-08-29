<?php

namespace App\Console\Commands;

use App\Notifications\InvitoUtente;
use App\Support\Provisioning\ProvisionaEnte;
use App\Support\Provisioning\ProvisioningRifiutato;
use Illuminate\Console\Command;
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
 * **`--sede=` separa i due nomi.** L'argomento `nome` è la **ragione sociale**
 * del cliente; senza `--sede` il nodo Ente prende lo stesso valore, ed è il
 * comportamento storico. Con `--sede` la prima sede si chiama come la chiama chi
 * ci lavora — «Laboratorio San Raffaele» dentro «Gruppo Rossi SpA» — che è la
 * differenza che il Parco clienti rendeva visibile: la colonna SEDE del primo
 * Ente ripeteva la ragione sociale su ogni cliente. La sede **non si può
 * omettere** (il nodo Ente è la radice del tenant: senza, non c'è un posto dove
 * mettere la prima macchina), il suo nome sì.
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
        {nome : Ragione sociale del cliente}
        {--sede= : Nome della prima sede; assente, prende la ragione sociale}
        {--account= : ID dell\'account esistente a cui agganciare l\'Ente}
        {--admin-email= : Email dell\'utente Admin}
        {--admin-name= : Nome dell\'utente Admin}
        {--admin-password= : Password (se omessa viene generata)}';

    protected $description = 'Crea un Ente (tenant) col suo Account e l\'utente Admin';

    public function handle(): int
    {
        $nome = $this->argument('nome');
        $adminEmail = $this->option('admin-email') ?: Str::slug($nome).'-admin@example.test';

        try {
            $esito = (new ProvisionaEnte(
                nome: $nome,
                adminEmail: $adminEmail,
                adminName: $this->option('admin-name') ?: "Admin {$nome}",
                passwordEsplicita: $this->option('admin-password'),
                accountId: $this->option('account') !== null ? (int) $this->option('account') : null,
                // `--sede` non valorizzata vale `null`; valorizzata a vuoto la
                // normalizza `ProvisionaEnte`, che è il solo posto in cui «vuoto
                // = non scelto» è deciso.
                nomeSede: $this->option('sede'),
            ))->esegui();
        } catch (ProvisioningRifiutato $rifiuto) {
            $this->error($rifiuto->getMessage());

            if (str_contains($rifiuto->getMessage(), 'limite raggiunto')) {
                $this->line('Passare a un piano superiore (easylab:abbona) o cestinare una sede prima di aggiungerne un\'altra.');
            }

            if (str_contains($rifiuto->getMessage(), 'specificare quale')) {
                $this->line('Usare --account=ID.');
            }

            return self::FAILURE;
        }

        // ⚠️ `$esito->ente->nome` e non `$nome`: con `--sede` i due valori sono
        // diversi, e stampare quello chiesto invece di quello scritto è il modo
        // in cui un comando racconta un provisioning che non è avvenuto così.
        $this->info("Ente «{$esito->ente->nome}» creato (id {$esito->ente->id}).");
        $this->info("Account: «{$esito->account->ragione_sociale}» (id {$esito->account->id}, ".($esito->accountNuovo ? 'nuovo' : 'esistente').').');
        $this->info("Admin: {$adminEmail}".($esito->adminNuovo ? '' : ' (utente esistente: resta sul suo Ente, il nuovo si raggiunge con lo switcher)'));

        if ($esito->invitoAccodato) {
            // «In consegna» e non «inviato»: la notifica è accodata, quindi da
            // qui non si sa se partirà. Se la coda non c'è (locale, `sync`) è
            // partita davvero — ma la frase deve essere vera in **entrambi** i
            // casi, e questa lo è.
            $this->info("Invito in consegna a {$adminEmail} (link valido ".InvitoUtente::GIORNI_VALIDITA.' giorni).');
        }

        if ($esito->invitoFallito !== null) {
            // Il provisioning È riuscito: le scritture sono committate. Un SMTP
            // giù non deve far sembrare fallito ciò che c'è a DB — basta
            // rilanciare lo stesso comando per reinviare.
            $this->warn("Invito NON inviato ({$esito->invitoFallito}): rilanciare lo stesso comando per riprovare.");
        }

        return self::SUCCESS;
    }
}

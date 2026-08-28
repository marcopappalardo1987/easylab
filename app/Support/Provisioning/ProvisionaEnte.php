<?php

namespace App\Support\Provisioning;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\Piani;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Il provisioning di un Ente, in un posto solo (ADR-012, ADR-032).
 *
 * Estratto da `ProvisionTenant` in S6 perché la UI della cabina di regia deve
 * fare **la stessa cosa**, e «la stessa cosa» scritta due volte diventa due
 * cose diverse al primo cambiamento. Il comando ora fa parsing e stampa; qui
 * vive l'ordine dei gesti, che non è arbitrario:
 *
 * 1. **risoluzione dell'account** — prima di qualunque scrittura;
 * 2. **limite di piano** — fuori dalla transazione: chi viene rifiutato non
 *    deve lasciarsi dietro né un account né un utente;
 * 3. **transazione** — cinque scritture (account, ente, tenant_id, utente,
 *    pivot): un fallimento a metà lascerebbe un account orfano o un ente senza
 *    intestatario;
 * 4. **invito fuori dalla transazione** — una mail spedita per una transazione
 *    poi rollbackata manda qualcuno su un link che non porta a nulla.
 *
 * ⚠️ **Questa classe gira ora anche con un utente autenticato**, ed è la
 * differenza che il comando non aveva mai incontrato: tutti i suoi test usano
 * `$this->artisan()` senza `actingAs()`, dove `CurrentTenant::shouldScope()` è
 * falso. Da UI il Superadmin **è** autenticato ed **è tenant-bound** (ADR-018
 * non concede bypass a nessuno), quindi `BelongsToTenant::creating` timbrerebbe
 * il nodo Ente nuovo col `tenant_id` del Superadmin. Il gesto che lo rimette a
 * posto è nominato — `UnitaOrganizzativa::radicaComeEnte()` — invece di essere
 * un effetto collaterale di `saveQuietly()`.
 */
final class ProvisionaEnte
{
    public function __construct(
        private readonly string $nome,
        private readonly string $adminEmail,
        private readonly string $adminName,
        private readonly ?string $passwordEsplicita = null,
        private readonly ?int $accountId = null,
        /**
         * ⚠️ **«Voglio un cliente nuovo», detto esplicitamente.**
         *
         * Senza questo flag i due gesti — «crea un cliente» e «aggiungi una
         * sede» — sono **indistinguibili da qui**: se l'email dell'amministratore
         * appartiene già a qualcuno, l'Ente si aggancia al suo account. Giusto
         * per il secondo gesto, silenziosamente sbagliato per il primo.
         *
         * Il default è `false`, così il comando conserva il proprio
         * comportamento e i suoi test restano verdi senza essere toccati.
         */
        private readonly bool $esigiAccountNuovo = false,
    ) {}

    /**
     * @throws ProvisioningRifiutato prima di qualunque scrittura
     */
    public function esegui(): EsitoProvisioning
    {
        $accountEsistente = $this->risolviAccount();

        $this->verificaLimiteDiPiano($accountEsistente);

        // Senza password esplicita l'utente nasce **invitato**: la password è un
        // tappo di 64 caratteri che nessuno vedrà mai — non un segreto da
        // custodire — e quella vera la sceglie lui dal link firmato (ADR-012).
        $passwordIniziale = $this->passwordEsplicita ?: Str::password(64);

        [$ente, $admin, $account] = DB::transaction(function () use ($accountEsistente, $passwordIniziale) {
            $account = $accountEsistente ?? Account::create(['ragione_sociale' => $this->nome]);

            $ente = UnitaOrganizzativa::create([
                'tipo' => TipoUnitaOrganizzativa::Ente,
                'nome' => $this->nome,
                'parent_id' => null,
            ]);

            $ente->radicaComeEnte($account);

            $admin = User::firstOrCreate(
                ['email' => $this->adminEmail],
                [
                    'name' => $this->adminName,
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
            if ($admin->wasRecentlyCreated) {
                $admin->forceFill([
                    'tenant_id' => $ente->id,
                    'email_verified_at' => $this->passwordEsplicita ? now() : null,
                ])->save();
            }

            if (! $admin->hasRole('Admin')) {
                $admin->assignRole('Admin');
            }

            $account->aggiungiMembro($admin);

            return [$ente, $admin, $account];
        });

        return $this->invitaSeServe($ente, $admin, $account, $accountEsistente === null);
    }

    /**
     * L'account a cui agganciare l'Ente, o `null` se ne va creato uno nuovo.
     *
     * Tre casi (ADR-032 punto 7): id esplicito; email mai vista → account 1:1
     * nuovo; email esistente → il suo account, se ne ha **uno solo**. Con più
     * account serve l'id, perché l'ambiguità non si indovina.
     */
    private function risolviAccount(): ?Account
    {
        if ($this->accountId !== null) {
            $account = Account::find($this->accountId);

            if ($account === null) {
                throw new ProvisioningRifiutato(
                    "Nessun account con id {$this->accountId}.",
                    ProvisioningRifiutato::ACCOUNT_INESISTENTE,
                );
            }

            return $account;
        }

        $utenteEsistente = User::where('email', $this->adminEmail)->first();

        if ($utenteEsistente === null) {
            return null;
        }

        $suoi = $utenteEsistente->accounts()->get();

        // 🔴 **Il gesto «crea un cliente» non deve poter agganciare a un
        // contratto altrui.** Senza questo ramo, «crea» e «aggiungi una sede»
        // sono indistinguibili da qui: se l'email appartiene già a qualcuno,
        // l'Ente finisce sul suo account — giusto per il secondo gesto,
        // silenziosamente sbagliato per il primo. Da console si nota, perché il
        // comando stampa «Account: … (esistente)»; da un form intitolato «Nuovo
        // cliente» no, e l'operatore legge un successo per una sede finita sul
        // contratto di un terzo che non ha mai nominato — l'account **di
        // piattaforma** compreso.
        if ($suoi->isNotEmpty() && $this->esigiAccountNuovo) {
            throw new ProvisioningRifiutato(
                "{$this->adminEmail} amministra già ".
                $suoi->pluck('ragione_sociale')->map(fn ($r) => "«{$r}»")->implode(', ').
                ': una sede in più si aggiunge dalla riga di quel cliente, non creandone uno nuovo.',
                ProvisioningRifiutato::GIA_AMMINISTRA,
            );
        }

        if ($suoi->count() > 1) {
            // Le **ragioni sociali** e non gli id: la tabella della cabina gli id
            // non li mostra, quindi un messaggio che li elenca è un vicolo cieco
            // per chi lo legge da lì. In console il comando aggiunge da sé
            // l'indicazione dell'opzione.
            throw new ProvisioningRifiutato(
                "{$this->adminEmail} è membro di {$suoi->count()} account (".
                $suoi->pluck('ragione_sociale')->map(fn ($r) => "«{$r}»")->implode(', ').
                '): indicare a quale agganciare la sede.',
                ProvisioningRifiutato::AMBIGUO,
            );
        }

        return $suoi->first();
    }

    /**
     * Il limite di Enti del piano (ADR-032), **fuori dalla transazione**.
     *
     * La regola vive su `Account::puoAggiungereEnte()` e non qui: due chiamanti
     * la consumano, nessuno dei due la riscrive. Un account nuovo non ha Enti,
     * quindi passa sempre — ma il controllo si fa lo stesso, senza ramo
     * condizionale: un `max_enti` a 0, se un giorno esistesse, dev'essere
     * rispettato anche dal primo Ente.
     */
    private function verificaLimiteDiPiano(?Account $account): void
    {
        $perLimite = $account ?? new Account;

        // ⚠️ **Un piano fuori catalogo non deve esplodere qui.**
        // `puoAggiungereEnte()` passa da `Piani::maxEnti()`, che **lancia**
        // `InvalidArgumentException` su un codice che non conosce — giusto per un
        // getter in console, sbagliato per una guardia dietro a un form. È lo
        // stesso ragionamento di `MetrichePiattaforma`: la cabina è l'unica
        // schermata da cui quel dato si ripara, e morire proprio lì è il modo
        // peggiore di segnalarlo. Diventa un rifiuto **spiegato**, con la strada.
        if (! Piani::esiste($perLimite->piano)) {
            throw new ProvisioningRifiutato(
                "«{$perLimite->ragione_sociale}» è su un piano che non è più a catalogo («{$perLimite->piano}»): ".
                'va rimesso su un piano valido prima di aggiungergli una sede.',
                ProvisioningRifiutato::PIANO_FUORI_CATALOGO,
            );
        }

        if ($perLimite->puoAggiungereEnte()) {
            return;
        }

        $max = Piani::maxEnti($perLimite->piano);
        $attuali = $perLimite->enti()->count();

        throw new ProvisioningRifiutato(
            "«{$perLimite->ragione_sociale}» (id {$perLimite->id}) è sul piano ".
            Piani::etichetta($perLimite->piano).
            ": {$attuali} ".($attuali === 1 ? 'Ente' : 'Enti')." su {$max}, limite raggiunto.",
            ProvisioningRifiutato::LIMITE_RAGGIUNTO,
        );
    }

    /**
     * L'invito, **fuori dalla transazione** e solo se c'è qualcuno da invitare.
     *
     * L'unica condizione copre per costruzione i tre casi: utente nuovo senza
     * password (invito), utente già attivo agganciato a una sede nuova (niente:
     * la password ce l'ha), utente invitato in un giro precedente e mai attivato
     * (reinvio, che è il rilancio dello stesso gesto).
     */
    private function invitaSeServe(UnitaOrganizzativa $ente, User $admin, Account $account, bool $accountNuovo): EsitoProvisioning
    {
        $base = fn (bool $inviato, ?string $errore) => new EsitoProvisioning(
            ente: $ente,
            admin: $admin,
            account: $account,
            accountNuovo: $accountNuovo,
            adminNuovo: $admin->wasRecentlyCreated,
            invitoAccodato: $inviato,
            invitoFallito: $errore,
        );

        if ($this->passwordEsplicita !== null || $admin->email_verified_at !== null) {
            return $base(false, null);
        }

        try {
            // L'id dell'Ente rende l'invito brandizzabile: senza, la prima email
            // che un cliente riceve da Easy Lab sarebbe l'unica senza il proprio
            // marchio (🔗 MarchioEmail). È un `int`, non un model: attraversa la
            // coda senza rifetchare nulla.
            $admin->notify(new InvitoUtente($this->nome, $this->adminEmail, $ente->id));

            return $base(true, null);
        } catch (Throwable $e) {
            // Il provisioning È riuscito: le scritture sono committate. Un SMTP
            // giù non deve far sembrare fallito ciò che c'è a DB.
            return $base(false, $e->getMessage());
        }
    }
}

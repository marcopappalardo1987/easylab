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
        /**
         * 🔴 **L'hash di una password già scelta, per il self-signup pubblico**
         * (ADR-012).
         *
         * Serve a un caso che né la console né la cabina hanno: chi si
         * registra da sé sceglie la password **prima** di pagare, e l'utente
         * nasce **dopo** il ritorno da Stripe. Fra i due momenti la credenziale
         * vive già hashata su `registrazioni.password_hash`, quindi non c'è
         * nessuna password in chiaro da passare a `$passwordEsplicita` — e
         * rihashare un hash lo distruggerebbe.
         *
         * ⚠️ **Il cast `hashed` di `User` lo riconosce e lo lascia stare**
         * (`castAttributeAsHashedString` rihasha solo ciò che hashato non è),
         * quindi il valore arriva a database intatto e la password scelta al
         * modulo funziona al primo login. Un test lo prova facendo il login.
         *
         * ⚠️ **Implica `email_verified_at`**, come `$passwordEsplicita`: chi
         * arriva di qui ha già cliccato il link di verifica, quindi non c'è
         * nessun invito da mandare — e mandarlo significherebbe offrirgli di
         * riscrivere la password che ha appena scelto.
         *
         * ⚠️ **Ultimo parametro, e opzionale**, per la ragione già scritta
         * sopra `$esigiAccountNuovo`: i chiamanti storici lo costruiscono senza,
         * e un parametro in mezzo li romperebbe tutti in una volta.
         */
        private readonly ?string $passwordHash = null,
        /**
         * Il nome del **nodo Ente**, quando non deve essere la ragione sociale.
         *
         * Nasce da una domanda posta guardando il Parco clienti: la colonna
         * SEDE del primo Ente ripeteva la ragione sociale, e la proposta era
         * «che il tenant non abbia sedi di default, così le nomino io».
         *
         * ⛔ **La struttura non si può togliere**: il nodo Ente *è* la radice
         * del tenant — `strumenti.tenant_id`, `interventi.tenant_id`,
         * `garanzie.tenant_id` e `ricambi.tenant_id` puntano tutti lì — quindi
         * un Account senza nessun Ente non è uno stato rappresentabile: non ci
         * sarebbe un posto dove mettere la prima macchina. Ciò che si può
         * togliere è **l'imposizione del nome**, che è poi ciò che serviva: la
         * ragione sociale resta dell'Account, la sede si chiama come la chiama
         * chi ci lavora («Laboratorio San Raffaele», non «Gruppo Rossi SpA»).
         *
         * Assente — o vuoto, che non è un nome — il comportamento è quello di
         * sempre: nome dell'Ente = `$nome`. La normalizzazione sta **qui** e non
         * nei chiamanti perché la decisione «vuoto = non scelto» dev'essere una
         * sola: due copie divergono, e la divergenza si chiamerebbe «una sede
         * con il nome vuoto» in una tabella che non ha altro da mostrare.
         *
         * ⚠️ **Ultimo parametro e opzionale**, per la ragione già scritta sopra
         * `$esigiAccountNuovo` e `$passwordHash`: i chiamanti storici lo
         * costruiscono senza, e un parametro in mezzo li romperebbe tutti in una
         * volta.
         *
         * ⚠️ **Non tocca l'invito.** `InvitoUtente` continua a ricevere `$nome`,
         * cioè la ragione sociale: la prima email che il cliente riceve deve
         * nominare il **contratto** che sta aprendo, non il capannone.
         */
        private readonly ?string $nomeSede = null,
    ) {}

    /**
     * Come si chiama il nodo Ente: la sede se è stata nominata, altrimenti il
     * nome del cliente. Vedi il docblock di `$nomeSede`.
     */
    private function nomeDellEnte(): string
    {
        $sede = trim((string) $this->nomeSede);

        return $sede !== '' ? $sede : $this->nome;
    }

    /**
     * La credenziale è già stata scelta da chi la userà: niente invito, e
     * `email_verified_at` valorizzato alla nascita.
     *
     * Una domanda sola con due risposte possibili — password in chiaro
     * (provisioning da console/cabina) o hash (self-signup) — perché i due
     * rami che la consumano devono restare **d'accordo**: se `email_verified_at`
     * e l'invito rispondessero a condizioni diverse, esisterebbe uno stato in
     * cui l'utente è verificato e riceve comunque un invito a impostare la
     * password che ha già.
     */
    private function credenzialeGiaScelta(): bool
    {
        return $this->passwordEsplicita !== null || $this->passwordHash !== null;
    }

    /**
     * L'email appartiene a una persona **cestinata**? Allora ci si ferma qui
     * (🔗 ADR-038).
     *
     * 🔴 Sta in cima a `esegui()` e non dentro `risolviAccount()`, dove
     * copriva solo metà dei casi: col ramo `--account=` quella risoluzione
     * **ritorna prima** di guardare l'email, e il `firstOrCreate` più sotto
     * sarebbe arrivato all'INSERT. `users.email` è unique senza condizione,
     * quindi la riga cestinata occupa l'indirizzo: il risultato sarebbe stato
     * un 500 al posto di un rifiuto — su un percorso che parte anche dalla
     * registrazione pubblica.
     *
     * ⛔ E non si ripristina di nascosto: rimettere in servizio una persona è
     * un gesto che qualcuno deve fare **guardandolo**, dalla schermata Utenti,
     * non un effetto collaterale di «crea un cliente».
     *
     * @throws ProvisioningRifiutato
     */
    private function rifiutaSeCestinato(): void
    {
        $cestinato = User::onlyTrashed()->where('email', $this->adminEmail)->exists();

        if ($cestinato) {
            throw new ProvisioningRifiutato(
                "L'indirizzo {$this->adminEmail} appartiene a una persona cestinata: ripristinala dalla schermata Utenti prima di riusarlo.",
                ProvisioningRifiutato::UTENTE_CESTINATO,
            );
        }
    }

    /**
     * @throws ProvisioningRifiutato prima di qualunque scrittura
     */
    public function esegui(): EsitoProvisioning
    {
        $this->rifiutaSeCestinato();

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
                // ⚠️ **Non `$this->nome`**: quello è la ragione sociale
                // dell'Account, e usarlo anche qui era l'imposizione che il
                // Parco clienti rendeva visibile (colonna SEDE = ragione
                // sociale su ogni primo Ente). Vedi `$nomeSede`.
                'nome' => $this->nomeDellEnte(),
                'parent_id' => null,
            ]);

            $ente->radicaComeEnte($account);

            $admin = User::firstOrCreate(
                ['email' => $this->adminEmail],
                [
                    'name' => $this->adminName,
                    // ⚠️ `Hash::make` **solo** quando la password arriva in
                    // chiaro: `$passwordHash` è già un hash, e rihasharlo
                    // produrrebbe una credenziale che nessuno conosce.
                    'password' => $this->passwordHash ?? Hash::make($passwordIniziale),
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
                    'email_verified_at' => $this->credenzialeGiaScelta() ? now() : null,
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

        // Senza `withTrashed()`, e non è una dimenticanza: il caso «l'email è
        // di una persona cestinata» è già stato rifiutato da
        // `rifiutaSeCestinato()` in cima a `esegui()`. Leggerlo anche qui
        // darebbe a quella riga un secondo esito possibile — «amministra già
        // questi account» — per una persona che non amministra più niente.
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

        if ($this->credenzialeGiaScelta() || $admin->email_verified_at !== null) {
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

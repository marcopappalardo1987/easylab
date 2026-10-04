<?php

namespace App\Models;

use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Scopes\DepartmentScope;
use App\Models\Scopes\TenantScope;
use App\Support\Piani;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Cashier\Billable;
use RuntimeException;

/**
 * L'Account: l'intestatario del rapporto commerciale (ERD §4.3 — ADR-032).
 *
 * Possiede N Enti e paga EasyLab (rapporto A di ADR-002): qui vivono il
 * customer Stripe/`Billable`, i dati fiscali (ADR-010) e il lockout (ADR-013).
 * Non è un Rivenditore — quello è un merchant terzo che incassa in proprio, e
 * resta dall'altra parte del confine di ADR-002 (rapporto C, Stripe Connect,
 * fuori V1).
 *
 * **È il Billable del progetto** (`use Billable`, blocco Cashier). Non `User`,
 * che ha soft delete ed è una credenziale e non un cliente: legare
 * l'abbonamento a chi ha fatto il primo login significa perderlo quando quella
 * persona lascia l'azienda. Non il nodo ente, che con N sedi darebbe N
 * abbonamenti allo stesso cliente. Conseguenza pratica: la chiave esterna di
 * `subscriptions` è `account_id`, non `user_id` — è `getForeignKey()` che la
 * decide, ed è il motivo per cui le migration del pacchetto non sono state
 * pubblicate.
 *
 * **Primo modello di piattaforma del progetto**: vive sopra i tenant, quindi
 * niente `BelongsToTenant` — l'esenzione è dichiarata nel meta-test
 * (`TenantScopeGuardrailTest::NON_TENANT_MODELS`) ed è il pattern per i futuri
 * modelli di questo livello (`resellers`, che oggi un modello non ce l'ha).
 * Chi lo interroga è per definizione fuori dal proprio scope: viste
 * esplicitamente non-scopate gate da permessi di piattaforma (ADR-018), fino
 * ad allora console e provisioning.
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use AuditsDomainWrites, Billable, HasFactory, SoftDeletes;

    /**
     * Solo l'anagrafica commerciale: sono dati di form (onboarding, dashboard
     * Superadmin) ed è giusto che l'audit del trait li tracci.
     *
     * Le colonne del lockout e `piano` restano FUORI, come `forced_*` su
     * Strumento (ADR-005) e `visibilita_garanzie_ricambio` (ADR-029): il
     * lockout è un gesto coi suoi metodi (`blocca()`/`sblocca()`, ADR-013) e
     * il piano è ciò che il cliente ha **comprato** — nessuno dei due è un
     * campo che un form possa forgiare. La traccia passa da
     * `attributiDerivatiTracciati()` — non da `activity()` a mano, che
     * violerebbe la regola «o il trait o le esplicite, mai entrambi».
     *
     * Fuori restano anche `stripe_id`/`pm_*`/`trial_ends_at`, che scrive
     * Cashier: sono lo specchio locale di uno stato che vive su Stripe, e
     * infatti non sono nemmeno tracciate dall'audit — la riga di dominio la
     * produce `cambiaPiano()`, non il riflesso di un customer id.
     */
    /**
     * Come `visibilita_garanzie_ricambio` su UnitaOrganizzativa: il default di
     * colonna si applica all'INSERT, quindi un'istanza appena creata avrebbe
     * `is_locked` a null finché qualcuno non la rilegge — e sia la guardia di
     * `blocca()` sia l'`old` della riga di audit leggerebbero null invece di
     * false. Stessa ragione per `piano`: senza, `Piani::maxEnti($account->piano)`
     * su un'istanza fresca riceverebbe null e lancerebbe.
     */
    protected $attributes = [
        'is_locked' => false,
        'piano' => 'free',
        // Stessa ragione delle due sopra, e l'ha trovata il suo test: senza,
        // un'istanza fresca leggerebbe `null` e un conteggio scritto come
        // «escludi i di_piattaforma» si comporterebbe in modo diverso a
        // seconda che il model venga dal DB o dalla memoria.
        'di_piattaforma' => false,
    ];

    protected $fillable = [
        'ragione_sociale',
        'partita_iva',
        'codice_fiscale',
        'pec',
        'codice_destinatario_sdi',
    ];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'di_piattaforma' => 'boolean',
            'locked_at' => 'datetime',
            'stripe_locked_at' => 'datetime',
            // Non decorativo: `ManagesSubscriptions::onGenericTrial()` fa
            // `$this->trial_ends_at->isFuture()`. Senza cast è una stringa, e
            // ogni chiamata a `onTrial()`/`subscribed()` va in fatal.
            'trial_ends_at' => 'datetime',
        ];
    }

    /** «Creazione account», non «Creazione account…qualcosa»: nome-primo (ADR-027). */
    protected function nomeDominio(): string
    {
        return 'account';
    }

    /**
     * Le colonne del lockout contano per l'audit benché fuori-fillable: sono
     * quelle che decidono l'accesso di tutti gli utenti degli Enti
     * dell'account. Senza, la riga di `blocca()`/`sblocca()` non registrerebbe
     * nulla (`dontLogEmptyChanges`): l'audit vedrebbe il gesto sparire.
     *
     * @return list<string>
     */
    protected function attributiDerivatiTracciati(): array
    {
        return [
            'is_locked',
            'locked_at',
            'locked_reason',
            'stripe_locked_at',
            'stripe_lock_reason',
            'piano',
            // Ciò che la cabina ha chiesto al cliente di attivare (ADR-045):
            // un gesto commerciale, e senza traccia nessuno saprebbe chi lo ha
            // proposto né quando è stato onorato.
            'piano_proposto',
            // Decide se l'account **esiste commercialmente**: sparire dai
            // conteggi è un effetto grande quanto un lockout, e una colonna con
            // quell'effetto senza traccia sarebbe l'unica del gruppo. Oggi la
            // scrive solo il seeder; domani una leva di console o una UI.
            'di_piattaforma',
        ];
    }

    /**
     * Il lockout per insoluto (ADR-013): chiude la navigazione a tutti gli
     * utenti degli Enti dell'account (middleware `account.lockout`) e lo
     * switcher verso le sue sedi.
     *
     * Idempotente come no-op, non come riscrittura: su un account già bloccato
     * non si tocca `locked_at` — documenta QUANDO è iniziato l'insoluto, che è
     * il dato che conta in un sollecito — e non si produce una seconda riga di
     * audit. `void` e non `bool`, a differenza di `passaAllEnte()`: qui non
     * c'è una guardia il cui esito vada comunicato — chi arriva qui (il
     * comando console oggi, la UI S6 dietro `billing.lockout` domani) ha già
     * il diritto di farlo.
     */
    public function blocca(string $motivo): void
    {
        if ($this->locked_at !== null) {
            return;
        }

        $this->forceFill([
            'locked_at' => now(),
            'locked_reason' => $motivo,
            'is_locked' => true,
        ])->save();
    }

    public function sblocca(): void
    {
        if ($this->locked_at === null) {
            return;
        }

        $this->forceFill([
            'locked_at' => null,
            'locked_reason' => null,
            // Spegne la SUA sorgente, non il lockout: se Stripe tiene ancora
            // chiuso per insoluto, l'account resta chiuso.
            'is_locked' => $this->stripe_locked_at !== null,
        ])->save();
    }

    /**
     * Il lockout **automatico**, scritto dal webhook Stripe quando la
     * subscription finisce in `unpaid`/`canceled` o viene cancellata.
     *
     * ⚠️ **Perché è una sorgente separata e non lo stesso `blocca()`.** I due
     * gesti sono idempotenti come no-op, e con un solo `locked_at` il secondo
     * ad arrivare non lascerebbe traccia. Le conseguenze non sono teoriche:
     *
     * - account già chiuso da Stripe, poi chiuso a mano per contenzioso → il
     *   gesto manuale sarebbe un no-op, e il primo pagamento riuscito
     *   **riaprirebbe il contenzioso**;
     * - account chiuso a mano, poi insoluto → nessuna traccia dell'insoluto, e
     *   allo sblocco manuale il cliente tornerebbe operativo **senza pagare**,
     *   perché nessun evento futuro lo richiuderebbe.
     *
     * Con due sorgenti ortogonali `is_locked` significa «almeno una delle due è
     * accesa», e ciascuna si spegne da sé. L'invariante ha il suo test, nei due
     * ordini di arrivo.
     *
     * `stripe_lock_reason` è annotazione **interna** (ADR-013): porta la
     * subscription, lo stato e l'evento, e `/bloccato` non la mostra.
     */
    public function bloccaPerStripe(string $motivo): void
    {
        if ($this->stripe_locked_at !== null) {
            return;
        }

        $this->forceFill([
            'stripe_locked_at' => now(),
            'stripe_lock_reason' => $motivo,
            'is_locked' => true,
        ])->save();
    }

    public function sbloccaPerStripe(): void
    {
        if ($this->stripe_locked_at === null) {
            return;
        }

        $this->forceFill([
            'stripe_locked_at' => null,
            'stripe_lock_reason' => null,
            // Un blocco manuale in corso sopravvive al pagamento riuscito: è
            // esattamente ciò che questa separazione esiste per garantire.
            'is_locked' => $this->locked_at !== null,
        ])->save();
    }

    /**
     * Il piano commerciale dell'account (ADR-032).
     *
     * `accounts.piano` è l'**unica** fonte del piano: nessun percorso lo deduce
     * da `subscribed()`, perché il piano Free non ha alcuna subscription da
     * interrogare (ADR-002 — è omaggiato a fronte di un contratto fisico).
     *
     * No-op se il piano non cambia, così un webhook ripetuto non produce una
     * seconda riga di audit.
     *
     * ⚠️ **Ogni cambio di piano chiude la proposta** (ADR-045), nella stessa
     * scrittura: una proposta serve finché il cliente non ha comprato qualcosa,
     * e lasciarla accesa dopo farebbe dire alla cabina «in attesa di pagamento»
     * di un cliente che sta pagando.
     */
    public function cambiaPiano(string $piano): void
    {
        if (! Piani::esiste($piano)) {
            throw new InvalidArgumentException(
                "Piano «{$piano}» non a catalogo: `accounts.piano` accetta solo ".implode(', ', Piani::codici()).'.'
            );
        }

        if ($this->piano === $piano) {
            return;
        }

        $this->forceFill(['piano' => $piano, 'piano_proposto' => null])->save();
    }

    /**
     * Il piano a pagamento che la cabina **propone** al cliente (ADR-045), o
     * `null` per ritirare la proposta.
     *
     * 🔴 **Non tocca `piano`**, ed è tutto il senso della colonna: finché il
     * cliente non paga resta sul piano che ha, e nessuno risulta pagante senza
     * una subscription. A scrivere il piano vero è il webhook, quando Stripe
     * conferma l'incasso.
     *
     * Solo piani **a pagamento**: un piano gratuito non si propone, si assegna
     * (`cambiaPiano()`), perché non c'è nessun pagamento da aspettare.
     *
     * Ripetere la stessa proposta non scrive nulla e non lascia una seconda
     * riga di audit: il modello non è sporco, e Eloquent non salva.
     */
    public function proponiPiano(?string $piano): void
    {
        if ($piano !== null && (! Piani::esiste($piano) || Piani::eGratuito($piano))) {
            throw new InvalidArgumentException(
                "Piano «{$piano}» non proponibile: si propone solo un piano a pagamento a catalogo."
            );
        }

        $this->forceFill(['piano_proposto' => $piano])->save();
    }

    /**
     * Quanto vale questo account al mese, a **listino**, in centesimi.
     *
     * Esiste come metodo e non come chiamata inline perché è il posto dove
     * domani entrerebbe uno sconto per-account, e perché `$attributes` dà a
     * un'istanza fresca un `piano` valido — quindi non lancia mai su un model
     * appena costruito.
     *
     * ⚠️ Somma di questi valori = MRR **a listino**, non incassato: un account
     * in lockout continua a valere il suo piano finché il contratto non finisce
     * (ADR-013 — il blocco è una porta chiusa, non una disdetta).
     */
    public function valoreMensileCent(): int
    {
        return Piani::prezzoMensileCent($this->piano);
    }

    /**
     * Quanti Enti l'account può ancora aprire, o `null` se il piano è
     * illimitato. Negativo se è **sopra** il limite: succede dopo un downgrade,
     * ed è uno stato legittimo (vedi `puoAggiungereEnte()`).
     */
    public function slotEntiResidui(): ?int
    {
        $max = Piani::maxEnti($this->piano);

        return $max === null ? null : $max - $this->enti()->count();
    }

    /**
     * La decisione vive **qui** e non dentro `easylab:provision-tenant`, benché
     * ADR-032 dica «si fa rispettare al provisioning»: il provisioning oggi è
     * un comando e in S6 diventa una UI Livewire, e una guardia scritta in
     * `handle()` non sarebbe lì. Il comando la consuma, la UI la riuserà.
     *
     * **Downgrade: grandfathering.** Chi scende a un piano più piccolo tiene
     * tutte le sedi che ha — nessuna viene chiusa, nessuno viene bloccato — ma
     * non ne apre altre finché non risale. Il limite è una condizione
     * d'ingresso, non un'espulsione: cestinare sedi in uso per effetto di un
     * webhook sarebbe una perdita di dati decisa da una macchina.
     */
    public function puoAggiungereEnte(): bool
    {
        $residui = $this->slotEntiResidui();

        return $residui === null || $residui > 0;
    }

    /**
     * Gli Enti dell'account.
     *
     * `withoutGlobalScopes([...])` — eccezione nominata, la quarta del progetto
     * dopo `User::ente()`, `Garanzia::deiPezziMontati()` e
     * `Strumento::percorsoUbicazione()`. Il motivo è costitutivo: gli Enti di
     * un account sono per definizione fuori dal tenant corrente — è la ragione
     * per cui esiste lo switcher — e il TenantScope li filtrerebbe via tutti
     * tranne quello attivo. Il confine non si perde: la chiave è `account_id`,
     * non un filtro che qualcuno può dimenticare, e chi arriva qui è già
     * passato dall'appartenenza (`membri`).
     *
     * ⚠️ **I due scope sono elencati per nome, e non è pedanteria.** Fino al
     * blocco Cashier qui c'era `withoutGlobalScopes()` nudo, che rimuove anche
     * `SoftDeletingScope`: gli Enti **cestinati** finivano nel conteggio. Finché
     * l'unico consumatore era la riga informativa di `easylab:lockout` il
     * difetto era invisibile; con il limite di Enti per piano quel conteggio
     * decide se un cliente può aprire una sede, e una sede chiusa mesi fa gli
     * occuperebbe uno slot per sempre. Elencare i due scope (gli unici che
     * `UnitaOrganizzativa` registra: `BelongsToTenant`, `BelongsToOrgNode`) è
     * anche ciò che chiede la Policy di Code Review — un bypass va nominato,
     * non ottenuto per effetto collaterale.
     *
     * @return HasMany<UnitaOrganizzativa, $this>
     */
    public function enti(): HasMany
    {
        return $this->hasMany(UnitaOrganizzativa::class, 'account_id')
            ->withoutGlobalScopes([TenantScope::class, DepartmentScope::class]);
    }

    /**
     * I membri: chi amministra il rapporto commerciale. È la condizione che la
     * Policy billing (blocco Cashier) verificherà dietro `billing.manage_own`
     * — permesso condizione necessaria, il dato restringe (gerarchia ADR-029).
     *
     * @return BelongsToMany<User, $this>
     */
    public function membri(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')->withTimestamps();
    }

    public function aggiungiMembro(User $utente): void
    {
        $this->membri()->syncWithoutDetaching([$utente->id]);
    }

    /**
     * Invariante di ADR-032: **ogni account ha sempre almeno un membro** — un
     * account senza nessuno che possa amministrarlo è un contratto senza
     * controparte. Il `detach()` nudo resta tecnicamente possibile, ma questa
     * è la via maestra e il seeder demo riverifica l'invariante a posteriori.
     */
    public function rimuoviMembro(User $utente): void
    {
        DB::transaction(function () use ($utente): void {
            // 🔴 Il lock sulla riga dell'Account serializza il controllo e il
            // detach. Senza, due richieste possono vedere ciascuna «l'altro
            // membro esiste» e rimuovere entrambe, violando ADR-032 pur avendo
            // eseguito correttamente la guardia una per volta.
            self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $altri = $this->membri()->whereKeyNot($utente->id)->exists();

            if (! $altri) {
                throw new RuntimeException(
                    "L'utente {$utente->id} è l'ultimo membro dell'account {$this->id}: un account non resta senza amministratori (ADR-032)."
                );
            }

            $this->membri()->detach($utente->id);
        });
    }

    /**
     * Come l'account si presenta su Stripe.
     *
     * I default di Cashier sono `$this->name` e `$this->email`, colonne che un
     * Account non ha: senza questi tre override il customer nascerebbe anonimo.
     */
    public function stripeName(): ?string
    {
        return $this->ragione_sociale;
    }

    /**
     * L'indirizzo a cui Stripe manda ricevute e solleciti.
     *
     * **Il primo membro, non la PEC**, ed è una scelta di recapito e non di
     * forma: molte caselle PEC italiane rifiutano posta ordinaria da mittenti
     * non-PEC, quindi le email di Stripe verrebbero scartate **in silenzio** e
     * il primo segnale sarebbe un cliente che dice «non mi è mai arrivato
     * nulla». La PEC resta nei metadata, dov'è il suo posto: serve alla
     * fatturazione elettronica (ADR-010), non alle notifiche di un SaaS.
     *
     * `orderBy` esplicito: senza, l'ordine di un `belongsToMany` non è
     * garantito e ogni `syncStripeCustomerDetails()` potrebbe cambiare l'email
     * del customer senza che nessuno l'abbia deciso. L'invariante «ogni account
     * ha almeno un membro» (ADR-032) garantisce che un indirizzo ci sia.
     */
    public function stripeEmail(): ?string
    {
        return $this->membri()->orderBy('users.id')->value('email');
    }

    /**
     * Metadata del customer: servono a risalire dall'oggetto Stripe all'account
     * anche a mano, dalla dashboard, quando qualcosa non torna.
     *
     * I dati fiscali stanno qui e non altrove perché in V1 il software non fa
     * e-invoicing (ADR-010): li porta con sé per il giorno in cui servirà, e
     * intanto li rende leggibili accanto ai pagamenti.
     */
    public function stripeMetadata(): array
    {
        return array_filter([
            'account_id' => (string) $this->id,
            'piano' => $this->piano,
            'partita_iva' => $this->partita_iva,
            'codice_fiscale' => $this->codice_fiscale,
            'pec' => $this->pec,
            'codice_destinatario_sdi' => $this->codice_destinatario_sdi,
        ], fn ($valore) => $valore !== null);
    }
}

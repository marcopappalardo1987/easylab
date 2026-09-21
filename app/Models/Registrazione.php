<?php

namespace App\Models;

use Database\Factories\RegistrazioneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

/**
 * Una registrazione pubblica in corso (ERD §4.3 — 🔗 ADR-012 il provisioning,
 * ADR-032 l'Account come intestatario, ADR-002 il rapporto commerciale).
 *
 * È la **sala d'attesa** fra il modulo di `/registrati` e il primo pagamento
 * riuscito. La decisione di prodotto è netta: *l'account nasce solo se il
 * pagamento è andato a buon fine*, quindi fino a `completata_at` non esiste né
 * un `User`, né un `Account`, né un Ente — esiste solo questa riga.
 *
 * **Modello di PIATTAFORMA: niente `BelongsToTenant`**, come `Account`,
 * `Errore` e `Piano`. Chi si registra non ha ancora un Ente, e il percorso che
 * scrive questa riga gira **senza nessuno autenticato**: uno scope sul tenant
 * corrente la renderebbe invisibile a chi la crea. L'esenzione è dichiarata per
 * nome in `TenantScopeGuardrailTest::NON_TENANT_MODELS`.
 *
 * **Niente `AuditsDomainWrites`**, e l'esenzione è in
 * `AuditCoverageGuardrailTest::ESENZIONI`: il trait tratterebbe come «gesto di
 * una persona» ogni salvataggio di una riga che nessuna persona identificata
 * compie — chi la crea è un anonimo, e non c'è nessun causer da scrivere. Ciò
 * che merita il registro è il **risultato** (un account nato, un pagamento
 * riuscito), e quello lo scrive `CompletaRegistrazione` con
 * `performedOn($account)`.
 *
 * ⛔ **Nessun `activity(` in questo file, e non è una preferenza.** Una riga di
 * audit per ogni salvataggio racconterebbe i **tentativi**, cioè l'anagrafica di
 * chi non è ancora cliente, in un registro che si legge con `tenants.view_all`.
 * L'unica scrittura che ha per soggetto una `Registrazione` la fa
 * `App\Support\Registrazione\CompletaRegistrazione::registraIlRifiuto()`, ed è
 * il solo caso in cui il silenzio costerebbe più della riga: un pagamento
 * **incassato** che non è diventato un account, e per cui l'Account da nominare
 * non esiste. `SoggettiAudit` la etichetta col `nome_ente` e mai con l'email.
 *
 * ## 🔴 `password_hash` è un segreto in TRANSITO
 *
 * Vive qui solo perché la password si sceglie **prima** di pagare e l'utente
 * nasce **dopo**: fra i due momenti c'è un giro su un dominio di terzi. Appena
 * `users.password` esiste, questa colonna viene azzerata nella stessa
 * transazione — conservarla sarebbe una seconda copia di una credenziale in una
 * tabella che nessuna Policy protegge.
 */
class Registrazione extends Model
{
    /** @use HasFactory<RegistrazioneFactory> */
    use HasFactory, Notifiable, Prunable;

    /** Il plurale italiano non si indovina: `registrazioni`, non `registraziones`. */
    protected $table = 'registrazioni';

    /**
     * ⛔ **Vuoto di proposito: qui NON si assegna per massa.** Ogni colonna di
     * questa tabella è una decisione — l'email che riceverà il link, l'hash
     * della password, il piano su cui si aprirà l'abbonamento, il timbro di
     * completamento — e l'unico chiamante è un controller che riceve input da
     * una superficie **pubblica e non autenticata**. Un `$fillable` qui sarebbe
     * la via per cui un campo nascosto del form decide `completata_at`.
     * Si scrive con `forceFill()`, come `tenant_id` su `User`.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'email_verificata_at' => 'datetime',
            'completata_at' => 'datetime',
        ];
    }

    /**
     * Quanto vive una registrazione **mai completata**.
     *
     * Trenta giorni: abbastanza perché chi ha lasciato a metà il pagamento
     * possa riprendere dal link ricevuto (che vale molto meno), troppo poco
     * perché un hash di password e un'email non verificata restino a database
     * per sempre. Chi si è registrato e non ha pagato non è un cliente e non
     * deve diventare uno storico.
     */
    public const GIORNI_PENDENTE = 30;

    /**
     * 🔴 **Si potano solo le PENDENTI**, mai le completate.
     *
     * Una registrazione completata è la traccia di come un cliente è entrato —
     * l'unica riga che lega un `Account` al modulo pubblico invece che al
     * provisioning da console — e non porta più il segreto: `password_hash` è
     * già azzerato nella transazione di completamento. Potarla toglierebbe la
     * risposta a «da dove è arrivato questo contratto?» senza cancellare un
     * solo dato che non viva già, identico, in `users`.
     *
     * ⚠️ **Whitelist e non blacklist**, come `Errore::prunable()`: si nomina la
     * condizione che *deve* valere per cancellare, così una colonna di stato
     * aggiunta domani non fa cadere righe nuove nella potatura per difetto.
     *
     * @return Builder<Registrazione>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNull('completata_at')
            ->where('created_at', '<', now()->subDays(self::GIORNI_PENDENTE));
    }

    /**
     * L'email a cui vanno il link di verifica e il benvenuto.
     *
     * Esplicito benché `RoutesNotifications` prenderebbe da sé l'attributo
     * `email`: è l'unico recapito di questo modello, e dipendere da una
     * convenzione per una notifica che porta un URL firmato è un modo di
     * scoprire un rename nel posto sbagliato.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    /** L'account nato da questa registrazione, se il pagamento è arrivato. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** La casella è stata verificata: solo da qui in poi il checkout si apre. */
    public function emailVerificata(): bool
    {
        return $this->email_verificata_at !== null;
    }

    /**
     * 🔴 L'impronta che lega il link di verifica al **contenuto** della riga,
     * non solo al suo id.
     *
     * Una riga non verificata si riusa quando qualcuno ripete il modulo
     * (`RegistrazionePubblica::rigaPendentePer`), e il POST è pubblico: chi
     * conosce l'indirizzo riscrive `password_hash`. Firmato sul solo id, il link
     * già spedito alla vittima restava valido e verificava la password
     * dell'attaccante. `Hash::make` sala ogni hash, quindi ogni riscrittura
     * cambia l'impronta e **spegne i link precedenti**. HMAC con la chiave
     * dell'app: l'URL non espone nulla dell'hash.
     */
    public function improntaVerifica(): string
    {
        return hash_hmac('sha256', 'registrazione-verifica|'.$this->getKey().'|'.$this->password_hash, (string) config('app.key'));
    }

    /** L'account esiste già: ogni gesto successivo è un no-op. */
    public function completata(): bool
    {
        return $this->completata_at !== null;
    }
}

<?php

namespace App\Support\Utenti;

use App\Models\UnitaOrganizzativa;
use App\Models\User;
use App\Notifications\InvitoUtente;
use App\Support\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Crea una persona e la **invita** (🔗 ADR-038).
 *
 * Sorella di `App\Support\Provisioning\ProvisionaEnte`, con lo stesso mestiere
 * ridotto all'osso: quella classe crea *un Ente col suo Admin* — ruolo
 * hard-coded, Account, sede, limite di piano — e non sa fare altro. Questa crea
 * **una persona in un Ente che esiste già**, col ruolo che le viene detto, ed è
 * ciò che serviva alle due schermate nuove: `/utenti` (il cliente aggiunge i
 * suoi) e `/piattaforma/tecnici` (EasyLab crea i propri tecnici).
 *
 * ## L'ordine dei gesti non è arbitrario
 *
 * 1. **si risolve e si rifiuta prima di scrivere** — email già presa, persona
 *    cestinata, ruolo non conferibile: tutto qui, quando non c'è ancora niente
 *    da disfare;
 * 2. **la transazione** crea l'utente, gli scrive `tenant_id` e gli assegna il
 *    ruolo;
 * 3. **l'invito parte fuori dalla transazione**, in `try/catch`: un SMTP giù non
 *    deve far sembrare fallito ciò che a database c'è.
 *
 * ## Le tre cose che non si possono scrivere «normalmente»
 *
 * - ⛔ **`tenant_id` è fuori dal `$fillable`** (🔗 ADR-032): si scrive con
 *   `forceFill` e **solo su un utente appena nato**. Riscriverlo a uno esistente
 *   lo strapperebbe al suo Ente per effetto collaterale.
 * - ⛔ **`email_verified_at` idem**, e passarlo a `firstOrCreate` verrebbe
 *   scartato **in silenzio** — difetto latente già pagato nel provisioning. Qui
 *   resta `null` di proposito: `NULL` **è** lo stato «invitato» (🔗 ADR-012),
 *   insieme alla password tappo di 64 caratteri che nessuno vedrà mai.
 * - ⛔ **Il ruolo si assegna con `assignRole()`**, mai scrivendo il pivot: solo
 *   l'API di spatie invalida `spatie.permission.cache`, che è **condivisa fra i
 *   processi**. `ScrittureRbacGuardrailTest` lo rende rosso, e il difetto che
 *   previene — «il permesso gliel'ho tolto e quello entra lo stesso» — dura
 *   fino a 24 ore.
 *
 * ## L'audit si scrive a mano, ed è voluto
 *
 * `User` è **esente** dal trait `AuditsDomainWrites` per scelta dichiarata (la
 * sua vita è già raccontata da `AuditLogSubscriber` su un altro canale: login,
 * 2FA, impersonazione). Le righe di questa classe seguono quindi la strada di
 * `User::passaAllEnte()`: `activity()` esplicita, un atto con un nome. ⚠️ Il
 * guardrail rende rosso chi usa **entrambe** le strade sullo stesso modello.
 *
 * ⚠️ **Questa classe non autorizza niente.** Chi può invitare chi, e con quale
 * ruolo, lo decidono i chiamanti insieme a `RuoliAssegnabili` — che va chiesto
 * **nell'azione che scrive**, non solo dove si disegna la tendina.
 */
final class InvitaUtente
{
    /**
     * @param  UnitaOrganizzativa|null  $ente  L'Ente della persona. `null` è la
     *                                         forma **esterna** di 🔗 ADR-030: il
     *                                         tecnico di EasyLab, che non sta in
     *                                         nessun Ente e raggiunge i clienti
     *                                         per portafoglio ∪ assegnazione.
     */
    public function __construct(
        private readonly string $nome,
        private readonly string $email,
        private readonly string $ruolo,
        private readonly ?UnitaOrganizzativa $ente = null,
    ) {}

    /**
     * @throws InvitoRifiutato prima di qualunque scrittura
     */
    public function esegui(): EsitoInvito
    {
        $email = mb_strtolower(trim($this->email));

        // 🔴 `withTrashed()`: `users.email` è unique **senza condizione**, quindi
        // una persona cestinata continua a occupare l'indirizzo (🔗 ADR-038).
        // Senza questa lettura il `create` più sotto sbatterebbe sull'unique —
        // un 500 al posto di un messaggio.
        $esistente = User::withTrashed()->where('email', $email)->first();

        if ($esistente !== null) {
            throw InvitoRifiutato::per($esistente, $email);
        }

        $utente = DB::transaction(function () use ($email) {
            $trova = fn (): ?User => User::withTrashed()->where('email', $email)->first();

            try {
                // ⚠️ Savepoint proprio dentro la transazione esterna. Due
                // inviti concorrenti possono superare entrambi la SELECT qui
                // sopra; il vincolo unique sceglie il vincitore. Su Postgres
                // la collisione abortisce la transazione corrente, quindi
                // senza questo livello annidato la rilettura nel catch
                // fallirebbe con 25P02 invece di restituire un rifiuto umano.
                $utente = (new User)->getConnection()->transaction(fn (): User => User::create([
                    'name' => trim($this->nome),
                    'email' => $email,
                    // Un tappo, non un segreto: la password vera la sceglie chi
                    // riceve l'invito, dal link firmato (🔗 ADR-012).
                    'password' => Str::password(64),
                ]));
            } catch (QueryException $e) {
                // Non si ispeziona lo SQLSTATE (23505 su Postgres, messaggio
                // diverso su SQLite): se il concorrente ha scritto l'email,
                // quella riga decide il rifiuto; altrimenti l'errore era altro
                // e deve continuare a salire.
                $esistente = $trova();

                if ($esistente !== null) {
                    throw InvitoRifiutato::per($esistente, $email);
                }

                throw $e;
            }

            // ⛔ `forceFill` e **solo qui**, su un utente appena nato: vedi il
            // docblock di classe. `email_verified_at` resta `null`, ed è ciò
            // che significa «invitato».
            $utente->forceFill(['tenant_id' => $this->ente?->id])->save();

            $utente->assignRole($this->ruolo);

            return $utente;
        });

        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($utente)
            ->withProperties([
                'ruolo' => $this->ruolo,
                'tenant_id' => $this->ente?->id,
                'email' => $email,
            ])
            ->log('Persona invitata');

        return $this->invita($utente);
    }

    /**
     * L'invito, **fuori dalla transazione**.
     *
     * L'Ente serve alla notifica per due cose diverse: il **nome** compare nel
     * testo, l'**id** rende l'email brandizzabile (🔗 `MarchioEmail`) senza
     * portarsi dietro un solo byte di logo attraverso la coda.
     *
     * ⚠️ Per un tecnico di EasyLab l'Ente non c'è: l'invito prende il nome della
     * piattaforma e nessun marchio di cliente — che è corretto, perché non è di
     * un cliente che quella persona sta ricevendo l'accesso.
     */
    private function invita(User $utente): EsitoInvito
    {
        $nomeEnte = $this->ente?->nome ?? config('app.name');

        try {
            $utente->notify(new InvitoUtente($nomeEnte, $utente->email, $this->ente?->id));

            return new EsitoInvito($utente, true, true, null);
        } catch (Throwable $e) {
            // La persona **esiste**: le scritture sono committate. Ripetere il
            // gesto riprova a consegnare.
            return new EsitoInvito($utente, true, false, $e->getMessage());
        }
    }
}

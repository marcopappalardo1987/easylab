<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * L'invitato imposta la propria password (ADR-012, metà provisioning).
 *
 * **Perché una URL firmata e non il flusso di reset password di Fortify**, che
 * pure esiste già stilizzato: il broker `users` scade a **60 minuti**
 * (config/auth.php) e Fortify valida i token con quello. Un invito che muore
 * in un'ora non è un invito — l'admin di un laboratorio apre la posta il
 * giorno dopo. La firma temporanea di Laravel porta la scadenza dentro l'URL e
 * la sceglie chi invita (7 giorni): è il precedente del QR (🔗 ADR-003), dove
 * `signed` sta davanti a tutto per la stessa ragione — manomettere l'id o la
 * data invalida la firma **prima** che il route-model binding vada a cercare
 * l'utente, quindi non c'è modo di enumerare gli id.
 *
 * **Lo stato «invitato» non è una colonna**: è `email_verified_at IS NULL` con
 * una password random di 64 caratteri mai comunicata a nessuno. Il click sul
 * link E l'impostazione della password *sono* la verifica della casella, e
 * quando l'invitato finisce si valorizza `email_verified_at`. Nessun
 * `users.is_active` (documentato nell'ERD ma mai nato): un terzo stato avrebbe
 * significato tre sorgenti di verità per «questo utente può entrare?».
 *
 * **Due metodi in una classe e non due invokable** come `AccessoQr`, ed è
 * un'eccezione consapevole alla convenzione: la guardia «invito già usato»
 * deve valere identica in GET e POST, e un metodo privato condiviso è **una**
 * occasione di sbagliarla invece di due.
 *
 * **Niente auto-login dopo il set.** L'Admin invitato è un ruolo che richiede
 * la 2FA: al primo accesso `two-factor.enforce` lo porta all'attivazione. La
 * catena invito → password → login → 2FA è il flusso giusto, e autenticarlo
 * qui salterebbe il passaggio in cui quella catena si chiude.
 */
class ImpostaPasswordInvito extends Controller
{
    use PasswordValidationRules;

    public function mostra(Request $request, User $user)
    {
        if ($this->giaAttivato($user)) {
            return $this->allaLogin('Questo invito è già stato utilizzato: accedi con la tua password.');
        }

        return view('auth.imposta-password-invito', ['invitato' => $user]);
    }

    public function imposta(Request $request, User $user)
    {
        // La stessa guardia del GET, prima di qualunque scrittura: un link
        // riaperto (o un POST replicato) non deve poter riscrivere la password
        // di un account già in uso.
        if ($this->giaAttivato($user)) {
            return $this->allaLogin('Questo invito è già stato utilizzato: accedi con la tua password.');
        }

        // Le regole di robustezza arrivano dal trait di Fortify: la definizione
        // resta UNA, la stessa del reset e della registrazione.
        $validati = $request->validate(['password' => $this->passwordRules()]);

        $user->forceFill([
            'password' => Hash::make($validati['password']),
            'email_verified_at' => now(),
        ])->save();

        // L'accettazione di un invito è un evento di identità, come login e
        // impersonazione: sta sul canale audit e non nel log applicativo.
        activity(AuditLog::NAME)
            ->performedOn($user)
            ->log('Invito accettato');

        return $this->allaLogin('Password impostata: ora puoi accedere.');
    }

    private function giaAttivato(User $user): bool
    {
        return $user->email_verified_at !== null;
    }

    private function allaLogin(string $messaggio)
    {
        return redirect()->route('login')->with('status', $messaggio);
    }
}

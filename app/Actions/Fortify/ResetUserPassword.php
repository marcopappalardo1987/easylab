<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Reset della password dimenticata (Fortify; 🔗 ADR-012 per l'invito).
 *
 * 🔴 Il reset **verifica la casella**: il token è arrivato a quell'indirizzo e
 * chi lo usa l'ha letto. Senza `email_verified_at` l'invito (ADR-012), che
 * considera «già attivato» solo chi ha la casella verificata, restava aperto: chi
 * aveva il link di invito riscriveva, giorni dopo, la password scelta qui
 * (caccia T1bB-1, security pass S7).
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}

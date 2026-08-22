<?php

namespace App\Listeners;

use App\Support\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * Scrive nel log `audit` (activitylog) le azioni di sicurezza già esistenti:
 * impersonation, autenticazione e 2FA. I modelli di business di S2+ aggiungeranno
 * le proprie azioni sensibili (semaforo.force, lockout, accessi tecnici).
 */
class AuditLogSubscriber
{
    public function suImpersonationAvviata(TakeImpersonation $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->impersonator)
            ->performedOn($event->impersonated)
            ->withProperties(['ip' => request()->ip()])
            ->log('Impersonation avviata');
    }

    public function suImpersonationTerminata(LeaveImpersonation $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->impersonator)
            ->performedOn($event->impersonated)
            ->withProperties(['ip' => request()->ip()])
            ->log('Impersonation terminata');
    }

    public function suLogin(Login $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->user)
            ->withProperties(['ip' => request()->ip(), 'guard' => $event->guard])
            ->log('Login');
    }

    public function suLogout(Logout $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->user)
            ->withProperties(['ip' => request()->ip(), 'guard' => $event->guard])
            ->log('Logout');
    }

    public function suLoginFallito(Failed $event): void
    {
        activity(AuditLog::NAME)
            ->withProperties([
                'ip' => request()->ip(),
                'email' => $event->credentials['email'] ?? null,
                'guard' => $event->guard,
            ])
            ->log('Login fallito');
    }

    public function suDueFattoriAbilitati(TwoFactorAuthenticationEnabled $event): void
    {
        activity(AuditLog::NAME)->causedBy($event->user)->log('2FA abilitato');
    }

    public function suDueFattoriConfermati(TwoFactorAuthenticationConfirmed $event): void
    {
        activity(AuditLog::NAME)->causedBy($event->user)->log('2FA confermato');
    }

    public function suDueFattoriDisabilitati(TwoFactorAuthenticationDisabled $event): void
    {
        activity(AuditLog::NAME)->causedBy($event->user)->log('2FA disabilitato');
    }

    /**
     * 🔴 I metodi **non** si chiamano `handleXxx`, e non è una preferenza.
     *
     * Laravel scopre da sé i listener in `app/Listeners`, registrando ogni
     * metodo che comincia per `handle` e ha un evento come parametro tipizzato.
     * Con la mappa qui sotto **più** la scoperta automatica, questo subscriber
     * risultava iscritto **due volte**: ogni login, ogni logout, ogni 2FA e ogni
     * impersonazione scrivevano **due righe identiche** nel registro di audit —
     * da S1, in silenzio, e su una tabella che ADR-027 dichiara non
     * riscrivibile.
     *
     * Trovato guardando la vista Audit su staging il 22 Ago 2026: nessun test se
     * ne era accorto perché tutti asserivano con `first()` o `where(...)->exists()`,
     * cioè cercavano **una** riga e la trovavano.
     *
     * Le due strade per chiuderlo erano togliere questa mappa e affidarsi alla
     * scoperta, oppure tenerla e sottrarsi alla scoperta. Si è scelta la seconda:
     * un elenco esplicito di otto eventi si legge, si rivede e non dipende da una
     * convenzione di nomi — mentre la scoperta è implicita e si romperebbe al
     * primo metodo rinominato senza che nulla lo dica.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            TakeImpersonation::class => 'suImpersonationAvviata',
            LeaveImpersonation::class => 'suImpersonationTerminata',
            Login::class => 'suLogin',
            Logout::class => 'suLogout',
            Failed::class => 'suLoginFallito',
            TwoFactorAuthenticationEnabled::class => 'suDueFattoriAbilitati',
            TwoFactorAuthenticationConfirmed::class => 'suDueFattoriConfermati',
            TwoFactorAuthenticationDisabled::class => 'suDueFattoriDisabilitati',
        ];
    }
}

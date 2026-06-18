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
    public function handleTakeImpersonation(TakeImpersonation $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->impersonator)
            ->performedOn($event->impersonated)
            ->withProperties(['ip' => request()->ip()])
            ->log('Impersonation avviata');
    }

    public function handleLeaveImpersonation(LeaveImpersonation $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->impersonator)
            ->performedOn($event->impersonated)
            ->withProperties(['ip' => request()->ip()])
            ->log('Impersonation terminata');
    }

    public function handleLogin(Login $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->user)
            ->withProperties(['ip' => request()->ip(), 'guard' => $event->guard])
            ->log('Login');
    }

    public function handleLogout(Logout $event): void
    {
        activity(AuditLog::NAME)
            ->causedBy($event->user)
            ->withProperties(['ip' => request()->ip(), 'guard' => $event->guard])
            ->log('Logout');
    }

    public function handleFailed(Failed $event): void
    {
        activity(AuditLog::NAME)
            ->withProperties([
                'ip' => request()->ip(),
                'email' => $event->credentials['email'] ?? null,
                'guard' => $event->guard,
            ])
            ->log('Login fallito');
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        activity(AuditLog::NAME)->causedBy($event->user)->log('2FA abilitato');
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        activity(AuditLog::NAME)->causedBy($event->user)->log('2FA confermato');
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        activity(AuditLog::NAME)->causedBy($event->user)->log('2FA disabilitato');
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            TakeImpersonation::class => 'handleTakeImpersonation',
            LeaveImpersonation::class => 'handleLeaveImpersonation',
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            TwoFactorAuthenticationEnabled::class => 'handleTwoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'handleTwoFactorDisabled',
        ];
    }
}

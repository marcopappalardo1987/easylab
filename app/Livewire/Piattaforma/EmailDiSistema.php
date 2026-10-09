<?php

namespace App\Livewire\Piattaforma;

use App\Notifications\EmailDiProva;
use App\Support\AuditLog;
use App\Support\Email\CampioniEmail;
use App\Support\Email\CatalogoEmail;
use App\Support\Email\InterruttoriEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Piattaforma → Email: cosa scrive l'applicazione, a chi e quando; con un
 * interruttore per le email informative e una prova di invio per tutte
 * (🔗 ADR-047; ADR-011 le notifiche, ADR-018 la piattaforma).
 *
 * ## Tre cose, e nessuna tocca i dati di un cliente
 *
 * 1. **Elenca** il catalogo (`CatalogoEmail`): è la risposta a «quali email
 *    partono?», che prima stava sparsa in nove classi e due comandi.
 * 2. **Accende e spegne** le email informative, per tutti i clienti insieme.
 * 3. **Manda una prova** di qualunque email a un indirizzo scelto, con dati
 *    finti (`CampioniEmail`).
 *
 * ## 🔴 Il permesso è `tenants.provision`, e si rifà a ogni azione
 *
 * Un interruttore qui decide cosa ricevono **tutti** i clienti, e la prova
 * manda posta col marchio di Easy Lab a un indirizzo qualunque: sono gesti di
 * chi governa la piattaforma. `tenants.provision` è nel set bloccato, quindi
 * nessun editor di ruoli lo può dare a un cliente. Il `can:` di rotta dice
 * «puoi stare qui»; ogni metodo lo richiede di nuovo, perché le azioni Livewire
 * non ripassano dal middleware della pagina.
 *
 * ## La prova è sincrona, e ha un tetto
 *
 * Chi la preme vuole sapere se il server di posta l'ha accettata: per questo
 * non passa dalla coda, e l'esito — anche il rifiuto, con le parole del server
 * — si legge in pagina. Venti al minuto per persona: bastano a provarle tutte,
 * non a usare la pagina per spedire posta.
 */
#[Title('Email — Piattaforma — Easy Lab')]
#[Layout('components.layouts.app')]
class EmailDiSistema extends Component
{
    public const PERMESSO = 'tenants.provision';

    private const PROVE_AL_MINUTO = 20;

    /** L'indirizzo a cui mandare le prove. Arriva dal browser: lo valida `inviaProva()`. */
    public string $indirizzoProva = '';

    public function mount(): void
    {
        // Parte dall'indirizzo di chi prova: è il caso più comune, e un campo
        // vuoto sarebbe un clic in più a ogni visita.
        $this->indirizzoProva = (string) auth()->user()?->email;
    }

    public function accendi(mixed $chiave): void
    {
        $this->imposta($chiave, true);
    }

    public function spegni(mixed $chiave): void
    {
        $this->imposta($chiave, false);
    }

    /**
     * ⛔ La chiave arriva dal browser: deve essere a catalogo **e** di
     * un'email sospendibile. Le email di servizio non hanno interruttore, e
     * un `$wire.call('spegni', 'invito')` non deve poterne creare uno.
     */
    private function imposta(mixed $chiave, bool $attiva): void
    {
        Gate::authorize(self::PERMESSO);

        if (! CatalogoEmail::esiste($chiave) || ! CatalogoEmail::trova($chiave)->sospendibile) {
            throw new NotFoundHttpException;
        }

        InterruttoriEmail::imposta($chiave, $attiva, auth()->user());

        $nome = CatalogoEmail::trova($chiave)->nome;

        $this->annuncia($attiva
            ? "«{$nome}» è accesa: da adesso parte verso chi deve riceverla."
            : "«{$nome}» è spenta: da adesso non parte più verso nessuno.");
    }

    public function inviaProva(mixed $chiave): void
    {
        Gate::authorize(self::PERMESSO);

        if (! CatalogoEmail::esiste($chiave)) {
            throw new NotFoundHttpException;
        }

        $this->indirizzoProva = trim($this->indirizzoProva);

        $this->validate(
            ['indirizzoProva' => ['required', 'email', 'max:255']],
            ['indirizzoProva.required' => 'Scrivi l\'indirizzo a cui mandare la prova.'],
            ['indirizzoProva' => 'indirizzo di prova'],
        );

        $limite = 'email-di-prova:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limite, self::PROVE_AL_MINUTO)) {
            $this->annuncia('Troppe prove in un minuto: aspetta qualche secondo e riprova.', fallita: true);

            return;
        }

        RateLimiter::hit($limite, 60);

        $nome = CatalogoEmail::trova($chiave)->nome;

        try {
            Notification::route('mail', $this->indirizzoProva)
                ->notifyNow(new EmailDiProva(CampioniEmail::messaggio($chiave, $this->indirizzoProva)));
        } catch (Throwable $guasto) {
            // Le parole del server di posta sono l'informazione: «non è
            // partita» senza il perché costringerebbe ad andare a cercarlo nei log.
            report($guasto);
            $this->annuncia("La prova di «{$nome}» NON è partita: {$guasto->getMessage()}", fallita: true);

            return;
        }

        // Audit a mano: da qui esce posta col marchio di Easy Lab verso un
        // indirizzo scelto da una persona, e deve restare scritto chi e dove.
        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->withProperties(['email' => $chiave, 'nome' => $nome, 'destinatario' => $this->indirizzoProva])
            ->log('Email di prova inviata');

        $this->annuncia("Prova di «{$nome}» inviata a {$this->indirizzoProva}.");
    }

    private function annuncia(string $messaggio, bool $fallita = false): void
    {
        session()->flash('email', $messaggio);
        session()->flash('emailFallita', $fallita);
    }

    public function render(): View
    {
        Gate::authorize(self::PERMESSO);

        $voci = [];

        foreach (CatalogoEmail::tutte() as $chiave => $tipo) {
            $voci[] = [
                'tipo' => $tipo,
                'attiva' => InterruttoriEmail::attiva($chiave),
            ];
        }

        return view('livewire.piattaforma.email-di-sistema', [
            'informative' => array_values(array_filter($voci, fn (array $v) => $v['tipo']->sospendibile)),
            'diServizio' => array_values(array_filter($voci, fn (array $v) => ! $v['tipo']->sospendibile)),
        ]);
    }
}

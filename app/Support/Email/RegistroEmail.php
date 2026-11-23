<?php

namespace App\Support\Email;

use App\Models\EmailInviata;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use WeakMap;

/**
 * Scrive il registro delle email (🔗 ADR-064): una riga quando un'email sta
 * per partire, completata quando il server di posta la accetta.
 *
 * ## Agganciato agli eventi di Laravel, non alle notifiche
 *
 * `MessageSending` e `MessageSent` passano da **ogni** invio, qualunque sia la
 * strada: notifica accodata, comando delle 06:00, prova dalla piattaforma. Una
 * riga scritta da ciascuna notifica sarebbe diciotto posti in cui ricordarsene.
 * Il tipo si ricava dalla classe che Laravel mette nei dati del messaggio
 * (`__laravel_notification`), tradotta in voce di catalogo da
 * `CatalogoEmail::chiavePerClasse()`.
 *
 * ## Le due metà si ritrovano per oggetto
 *
 * Laravel non dà un id comune ai due eventi, ma l'oggetto `Email` che passa
 * dal primo è lo stesso che il secondo porta come messaggio originale. Una
 * `WeakMap` lo lega alla riga: se per qualche ragione il legame manca, al
 * secondo evento si scrive una riga nuova già accettata, invece di perderla.
 *
 * ## 🔴 Il registro non deve mai fermare la posta
 *
 * Ogni scrittura sta in un `try`, e un guasto finisce in `report()`: una
 * tabella irraggiungibile toglie una riga al registro, non un'email a un
 * cliente. Il primo listener non restituisce mai `false`, che per
 * `MessageSending` vorrebbe dire «non inviare».
 *
 * ⛔ Il corpo del messaggio non si conserva: si leggono solo indirizzi,
 * oggetto e intestazioni.
 */
final class RegistroEmail
{
    /** @var WeakMap<Email, int> */
    private WeakMap $inViaggio;

    /** Impedisce che il report di un guasto, se a sua volta manda un'email, rientri qui all'infinito. */
    private bool $staRiportando = false;

    public function __construct()
    {
        $this->inViaggio = new WeakMap;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, [$this, 'inInvio']);
        $events->listen(MessageSent::class, [$this, 'accettata']);
    }

    public function inInvio(MessageSending $evento): void
    {
        try {
            $riga = (new EmailInviata)->forceFill([
                ...$this->descrizione($evento->message, $evento->data),
                'stato' => EmailInviata::STATO_IN_INVIO,
            ]);
            $riga->save();

            $this->inViaggio[$evento->message] = $riga->id;
        } catch (Throwable $e) {
            $this->riporta($e);
        }
    }

    public function accettata(MessageSent $evento): void
    {
        try {
            $messaggio = $evento->sent->getOriginalMessage();

            if (! $messaggio instanceof Email) {
                return;
            }

            $esito = [
                'stato' => EmailInviata::STATO_ACCETTATA,
                'accettata_at' => now(),
                'message_id' => Str::limit($this->idDelServer($evento, $messaggio), 250, ''),
            ];

            $id = $this->inViaggio[$messaggio] ?? null;
            unset($this->inViaggio[$messaggio]);

            $riga = $id === null ? null : EmailInviata::query()->find($id);

            ($riga ?? (new EmailInviata)->forceFill($this->descrizione($messaggio, $evento->data)))
                ->forceFill($esito)
                ->save();
        } catch (Throwable $e) {
            $this->riporta($e);
        }
    }

    /**
     * @param  array<string, mixed>  $dati
     * @return array<string, string|null>
     */
    private function descrizione(Email $messaggio, array $dati): array
    {
        $classe = $dati['__laravel_notification'] ?? $dati['__laravel_mailable'] ?? null;
        $classe = is_string($classe) ? $classe : null;
        $notifica = $dati['__laravel_notification_id'] ?? null;

        $indirizzi = fn (array $elenco) => array_map(fn (Address $a) => $a->getAddress(), $elenco);

        return [
            'tipo' => CatalogoEmail::chiavePerClasse($classe),
            'classe' => $classe === null ? null : Str::limit(class_basename($classe), 120, ''),
            'destinatari' => implode(', ', array_unique([
                ...$indirizzi($messaggio->getTo()),
                ...$indirizzi($messaggio->getCc()),
                ...$indirizzi($messaggio->getBcc()),
            ])),
            'oggetto' => $messaggio->getSubject() === null ? null : Str::limit($messaggio->getSubject(), 500, ''),
            'mittente' => ($messaggio->getFrom()[0] ?? null)?->getAddress(),
            'mailer' => is_string($dati['mailer'] ?? null) ? Str::limit($dati['mailer'], 30, '') : null,
            'notifica_id' => is_string($notifica) && Str::isUuid($notifica) ? $notifica : null,
        ];
    }

    /** Per SES l'id con cui il server conosce il messaggio; per gli altri trasporti quello che danno. */
    private function idDelServer(MessageSent $evento, Email $messaggio): string
    {
        return $messaggio->getHeaders()->get('X-SES-Message-ID')?->getBodyAsString()
            ?? $evento->sent->getMessageId();
    }

    private function riporta(Throwable $e): void
    {
        if ($this->staRiportando) {
            return;
        }

        $this->staRiportando = true;

        try {
            report($e);
        } finally {
            $this->staRiportando = false;
        }
    }
}

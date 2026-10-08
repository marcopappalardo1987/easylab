<?php

namespace App\Support\Email;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gli interruttori delle email: quello di piattaforma e quello di ciascuna
 * persona (🔗 ADR-047; ADR-011 la preferenza, registro trattamenti T4).
 *
 * Un'email informativa parte solo se **entrambi** dicono sì:
 *
 *   1. la piattaforma l'ha accesa (`interruttori_email`, o lo stato di nascita);
 *   2. il destinatario non vi ha rinunciato (colonna `riceve_email_*`).
 *
 * È l'unico posto in cui la domanda «questa email parte?» ha risposta: lo
 * chiamano le `via()` delle notifiche e i servizi che le accodano.
 *
 * ## Niente model, e niente cache
 *
 * La tabella è una chiave e un booleano: un model porterebbe un global scope da
 * esentare e nessun comportamento. E niente cache perché a leggerla sono anche
 * i worker della coda, che vivono ore: uno stato in memoria farebbe partire
 * email che qualcuno ha appena spento.
 */
final class InterruttoriEmail
{
    private const TABELLA = 'interruttori_email';

    /**
     * True se l'email è accesa a livello di piattaforma.
     *
     * Le email di servizio rispondono sempre sì: non hanno un interruttore, e
     * una riga scritta a mano in tabella non deve poterle spegnere.
     */
    public static function attiva(string $chiave): bool
    {
        $tipo = CatalogoEmail::trova($chiave);

        if (! $tipo->sospendibile) {
            return true;
        }

        $riga = DB::table(self::TABELLA)->where('chiave', $chiave)->value('attiva');

        return $riga === null ? $tipo->nataAccesa : (bool) $riga;
    }

    /**
     * Accende o spegne un'email per tutti, e lo scrive nel registro di audit.
     *
     * ⛔ Solo le sospendibili: spegnere l'invito chiuderebbe fuori chiunque
     * venga creato da quel momento.
     *
     * Ripetere lo stato che c'è già non scrive nulla e non lascia una seconda
     * riga: il registro deve dire chi ha cambiato cosa, non chi ha cliccato.
     */
    public static function imposta(string $chiave, bool $attiva, ?User $chi = null): void
    {
        $tipo = CatalogoEmail::trova($chiave);

        if (! $tipo->sospendibile) {
            throw new InvalidArgumentException("L'email «{$tipo->nome}» è di servizio: non si spegne.");
        }

        if (self::attiva($chiave) === $attiva) {
            return;
        }

        // Due rami e non `updateOrInsert`: quello riscriverebbe `created_at` a
        // ogni cambio, cioè cancellerebbe l'unica data che dice da quando
        // l'interruttore esiste.
        $riga = DB::table(self::TABELLA)->where('chiave', $chiave);

        $riga->exists()
            ? $riga->update(['attiva' => $attiva, 'updated_at' => now()])
            : DB::table(self::TABELLA)->insert([
                'chiave' => $chiave, 'attiva' => $attiva, 'created_at' => now(), 'updated_at' => now(),
            ]);

        // ⚠️ Audit a mano: non c'è un model, quindi non c'è un trait a scriverlo
        // (stessa forma del portafoglio dei tecnici, 🔗 ADR-027).
        activity(AuditLog::NAME)
            ->causedBy($chi)
            ->withProperties(['email' => $chiave, 'nome' => $tipo->nome, 'attiva' => $attiva])
            ->log($attiva ? 'Email accesa' : 'Email spenta');
    }

    /**
     * True se **questa persona** vuole l'email.
     *
     * Chi non è un `User` — una casella raggiunta per indirizzo — non ha
     * preferenze da leggere, e la risposta è sì.
     */
    public static function voluta(string $chiave, object $destinatario): bool
    {
        $colonna = CatalogoEmail::trova($chiave)->preferenza;

        if ($colonna === null || ! $destinatario instanceof User) {
            return true;
        }

        // ⚠️ La colonna può non essere **caricata**: un'istanza appena creata
        // non ha i default di colonna (si applicano all'INSERT), e una query
        // con `select()` può averla lasciata fuori. Leggere `null` come «no»
        // toglierebbe l'email a chi non vi ha mai rinunciato; leggerlo come
        // «sì» la manderebbe a chi vi ha rinunciato. Si chiede al database.
        $valore = array_key_exists($colonna, $destinatario->getAttributes())
            ? $destinatario->getAttribute($colonna)
            : User::query()->whereKey($destinatario->getKey())->value($colonna);

        // Ancora `null` vuol dire una persona che non esiste nel database: non
        // ha potuto rinunciare a niente.
        return $valore === null ? true : (bool) $valore;
    }

    /** Le due domande insieme: è ciò che chiede `via()`. */
    public static function parte(string $chiave, object $destinatario): bool
    {
        return self::attiva($chiave) && self::voluta($chiave, $destinatario);
    }
}

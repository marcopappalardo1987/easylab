<?php

namespace App\Support\Notifiche;

use App\Models\Strumento;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Il referente di una macchina, visto come **destinatario** (🔗 ADR-054).
 *
 * Il referente è la persona del laboratorio a cui quella macchina fa capo: nome,
 * cognome, email e cellulare stanno sulla scheda dello strumento. Non è una
 * persona di Easy Lab — spesso non ha un account — quindi le email gli arrivano
 * **per indirizzo**, e solo se l'indirizzo c'è.
 *
 * Questo oggetto esiste per tre ragioni:
 *
 * - la domanda «a chi si scrive» ha una risposta sola (`di()`), e chi notifica
 *   non rilegge le colonne per conto suo;
 * - il confronto fra indirizzi è uno (`corrispondeA()`), senza maiuscole: è ciò
 *   che decide se il referente è **già** fra i destinatari, e quindi se
 *   l'email parte una volta o due;
 * - alla notifica arrivano scalari, come per ogni altra email (vedi
 *   `AvvisiIntervento`): niente model in coda.
 */
final readonly class Referente
{
    private function __construct(
        public string $email,
        public ?string $nome,
    ) {}

    /** `null` se la macchina non ha un referente a cui scrivere. */
    public static function di(Strumento $strumento): ?self
    {
        $email = mb_strtolower(trim((string) $strumento->referente_email));

        return $email === '' ? null : new self($email, $strumento->referenteNomeCompleto());
    }

    /**
     * I referenti delle macchine indicate, per id di macchina: è la domanda dei
     * due avvisi programmati, che parlano di più macchine alla volta.
     *
     * 🔴 `tenant_id` è esplicito: in console i global scope non filtrano, e
     * questa è una lettura di dati personali che finiscono in un'email.
     *
     * @param  list<int>  $strumentoIds
     * @return array<int, self>
     */
    public static function delleMacchine(int $tenantId, array $strumentoIds): array
    {
        return Strumento::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $strumentoIds)
            // Risparmia le righe senza referente, che sono quasi tutte. A
            // garantire che non ne passi una vuota è il `filter()` più sotto.
            ->whereNotNull('referente_email')
            ->get(['id', 'referente_nome', 'referente_cognome', 'referente_email'])
            ->mapWithKeys(fn (Strumento $strumento) => [$strumento->id => self::di($strumento)])
            ->filter()
            ->all();
    }

    public function corrispondeA(?string $email): bool
    {
        return mb_strtolower(trim((string) $email)) === $this->email;
    }

    /** La casella a cui scrivere: un destinatario senza account. */
    public function casella(): AnonymousNotifiable
    {
        return Notification::route('mail', $this->email);
    }
}

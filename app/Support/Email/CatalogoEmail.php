<?php

namespace App\Support\Email;

use App\Enums\StatoSemaforo;
use InvalidArgumentException;

/**
 * Tutte le email che l'applicazione manda, in un posto solo (🔗 ADR-047;
 * ADR-011 le notifiche programmate, ADR-012 gli inviti, ADR-045 il piano).
 *
 * Fino al 9 Ott 2026 l'elenco esisteva solo come somma delle classi in
 * `app/Notifications`: per sapere cosa partiva, quando e verso chi bisognava
 * leggere nove file e due comandi. Ora la pagina Piattaforma → Email lo legge
 * da qui, e da qui lo leggono anche gli interruttori e le prove di invio.
 *
 * ## Due famiglie
 *
 * - **Informative** (`sospendibile`): dicono al cliente o al tecnico che è
 *   successo qualcosa. Si accendono e si spengono dalla piattaforma, e chi le
 *   riceve può rinunciarvi dalle proprie preferenze.
 * - **Di servizio**: senza, qualcuno non entra (invito, recupero password,
 *   verifica dell'indirizzo) o non sa che il proprio account non c'è più. Non
 *   hanno interruttore, apposta.
 *
 * ⚠️ **Le sei email nate il 9 Ott 2026 nascono spente** (`nataAccesa:
 * false`): un deploy non deve cominciare a scrivere ai clienti di sua
 * iniziativa. Si provano dalla pagina, e si accendono quando si è deciso.
 *
 * ⚠️ Le chiavi sono **identificatori stabili**: finiscono nella tabella
 * `interruttori_email` e nel registro di audit. Rinominarne una vuol dire
 * perdere lo stato del suo interruttore.
 */
final class CatalogoEmail
{
    public const RIEPILOGO_SCADENZE = 'riepilogo_scadenze';

    public const AVVISO_OBSOLESCENZA = 'avviso_obsolescenza';

    public const MACCHINA_SEGNALATA = 'macchina_segnalata';

    public const INTERVENTO_PROGRAMMATO = 'intervento_programmato';

    public const INTERVENTO_ESEGUITO = 'intervento_eseguito';

    public const INTERVENTO_ASSEGNATO = 'intervento_assegnato';

    public const ACCOUNT_BLOCCATO = 'account_bloccato';

    public const PIANO_CAMBIATO = 'piano_cambiato';

    public const INVITO = 'invito';

    public const PROPOSTA_PIANO = 'proposta_piano';

    public const VERIFICA_INDIRIZZO = 'verifica_indirizzo';

    public const ACCOUNT_GIA_ESISTENTE = 'account_gia_esistente';

    public const BENVENUTO = 'benvenuto';

    public const ACCOUNT_ELIMINATO = 'account_eliminato';

    public const NUOVO_ERRORE = 'nuovo_errore';

    public const RECUPERO_PASSWORD = 'recupero_password';

    /**
     * Nell'ordine in cui si leggono in pagina: prima ciò che arriva ai clienti
     * ogni giorno, poi ciò che segue un gesto, in fondo il servizio.
     *
     * @return array<string, TipoEmail>
     */
    public static function tutte(): array
    {
        $voci = [
            new TipoEmail(
                chiave: self::RIEPILOGO_SCADENZE,
                nome: 'Riepilogo scadenze',
                quando: 'Ogni giorno alle 06:00, solo se un intervento o una garanzia è appena scaduto o è entrato nei 30 giorni dalla scadenza. Non ripete mai un avviso già dato.',
                aChi: 'Admin e Tenant per tutto l\'Ente, Responsabile Reparto per i suoi reparti, chi ha un intervento assegnato per i soli suoi.',
                sospendibile: true,
                preferenza: 'riceve_email_scadenze',
            ),
            new TipoEmail(
                chiave: self::AVVISO_OBSOLESCENZA,
                nome: 'Avviso obsolescenza',
                quando: 'Ogni giorno alle 06:15, con le macchine che hanno appena superato la soglia di età dell\'Ente. Anche subito, quando la soglia viene abbassata.',
                aChi: 'Admin e Tenant per tutto l\'Ente, Responsabile Reparto per i suoi reparti.',
                sospendibile: true,
                preferenza: 'riceve_email_scadenze',
            ),
            new TipoEmail(
                chiave: self::MACCHINA_SEGNALATA,
                nome: 'Macchina segnalata',
                quando: 'Quando qualcuno segnala una macchina portando il semaforo a mano su «'.StatoSemaforo::Arancione->etichetta().'» o «'.StatoSemaforo::Rosso->etichetta().'». Porta il motivo scritto da chi la segnala.',
                aChi: 'Admin e Tenant dell\'Ente, Responsabile Reparto se la macchina è nei suoi reparti. Mai a chi l\'ha segnalata.',
                sospendibile: true,
                nataAccesa: false,
                preferenza: 'riceve_email_macchine_segnalate',
            ),
            new TipoEmail(
                chiave: self::INTERVENTO_PROGRAMMATO,
                nome: 'Intervento programmato',
                quando: 'Quando qualcuno pianifica un intervento su una macchina. Non parte per gli interventi inseriti già eseguiti.',
                aChi: 'Admin e Tenant dell\'Ente, Responsabile Reparto se la macchina è nei suoi reparti. Mai a chi lo ha pianificato.',
                sospendibile: true,
                nataAccesa: false,
                preferenza: 'riceve_email_interventi_programmati',
            ),
            new TipoEmail(
                chiave: self::INTERVENTO_ESEGUITO,
                nome: 'Intervento eseguito',
                quando: 'Quando un intervento viene chiuso come eseguito.',
                aChi: 'Admin e Tenant dell\'Ente, Responsabile Reparto se la macchina è nei suoi reparti. Mai a chi lo ha chiuso.',
                sospendibile: true,
                nataAccesa: false,
                preferenza: 'riceve_email_interventi_eseguiti',
            ),
            new TipoEmail(
                chiave: self::INTERVENTO_ASSEGNATO,
                nome: 'Intervento assegnato',
                quando: 'Quando un intervento viene assegnato a una persona, o passa da una persona a un\'altra.',
                aChi: 'La persona a cui è stato assegnato. Mai se se lo assegna da sé.',
                sospendibile: true,
                nataAccesa: false,
                preferenza: 'riceve_email_interventi_assegnati',
            ),
            new TipoEmail(
                chiave: self::ACCOUNT_BLOCCATO,
                nome: 'Account bloccato',
                quando: 'Quando un account viene bloccato: a mano dalla piattaforma, o da Stripe per un pagamento non riuscito.',
                aChi: 'Tutti i membri dell\'account.',
                sospendibile: true,
                nataAccesa: false,
            ),
            new TipoEmail(
                chiave: self::PIANO_CAMBIATO,
                nome: 'Piano cambiato',
                quando: 'Quando cambia il piano di un account che esiste già: attivazione, cambio, ritorno al piano in comodato d\'uso dopo una disdetta.',
                aChi: 'Tutti i membri dell\'account.',
                sospendibile: true,
                nataAccesa: false,
            ),
            new TipoEmail(
                chiave: self::INVITO,
                nome: 'Invito',
                quando: 'Quando inviti una persona, crei un cliente o premi «Reinvia l\'invito». Il link vale 7 giorni.',
                aChi: 'La persona invitata.',
            ),
            new TipoEmail(
                chiave: self::PROPOSTA_PIANO,
                nome: 'Piano da attivare',
                quando: 'Quando crei un cliente dalla piattaforma scegliendo un piano a pagamento.',
                aChi: 'L\'amministratore del cliente.',
            ),
            new TipoEmail(
                chiave: self::VERIFICA_INDIRIZZO,
                nome: 'Verifica indirizzo',
                quando: 'Quando qualcuno compila il modulo pubblico per aprire un account.',
                aChi: 'Chi ha compilato il modulo.',
            ),
            new TipoEmail(
                chiave: self::ACCOUNT_GIA_ESISTENTE,
                nome: 'Account già esistente',
                quando: 'Quando qualcuno compila il modulo pubblico con un\'email che ha già un account.',
                aChi: 'Il titolare di quell\'email.',
            ),
            new TipoEmail(
                chiave: self::BENVENUTO,
                nome: 'Benvenuto',
                quando: 'Quando un account nasce da un pagamento riuscito: modulo pubblico o link di pagamento.',
                aChi: 'Il nuovo cliente.',
            ),
            new TipoEmail(
                chiave: self::ACCOUNT_ELIMINATO,
                nome: 'Account eliminato',
                quando: 'Quando elimini definitivamente un cliente dalla piattaforma.',
                aChi: 'Tutti i membri di quell\'account.',
            ),
            new TipoEmail(
                chiave: self::NUOVO_ERRORE,
                nome: 'Nuovo errore',
                quando: 'Quando l\'applicazione registra un errore nuovo, o se ne riapre uno già risolto. Al massimo 20 al giorno.',
                aChi: 'La casella indicata in ERRORI_ALERT_EMAIL, se impostata.',
            ),
            new TipoEmail(
                chiave: self::RECUPERO_PASSWORD,
                nome: 'Recupero password',
                quando: 'Quando qualcuno lo chiede dalla pagina di accesso. Il link vale 60 minuti.',
                aChi: 'Chi lo ha chiesto.',
            ),
        ];

        return array_combine(array_map(fn (TipoEmail $t) => $t->chiave, $voci), $voci);
    }

    public static function esiste(mixed $chiave): bool
    {
        return is_string($chiave) && array_key_exists($chiave, self::tutte());
    }

    public static function trova(string $chiave): TipoEmail
    {
        return self::tutte()[$chiave]
            ?? throw new InvalidArgumentException("Email «{$chiave}» non a catalogo.");
    }
}

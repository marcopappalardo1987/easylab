<?php

namespace App\Support\Email;

use App\Enums\StatoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use App\Models\User;
use App\Notifications\AccountBloccato;
use App\Notifications\AccountEliminato;
use App\Notifications\AccountGiaEsistente;
use App\Notifications\AvvisoObsolescenza;
use App\Notifications\BenvenutoRegistrazione;
use App\Notifications\DigestScadenze;
use App\Notifications\InterventoAssegnato;
use App\Notifications\InterventoEseguito;
use App\Notifications\InterventoProgrammato;
use App\Notifications\InvitoUtente;
use App\Notifications\MacchinaSegnalata;
use App\Notifications\NuovoErrore;
use App\Notifications\PianoCambiato;
use App\Notifications\PropostaPiano;
use App\Notifications\VerificaEmailRegistrazione;
use App\Support\Notifiche\RigaAvviso;
use App\Support\Notifiche\RigaObsolescenza;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Un esemplare di ogni email del catalogo, con **dati finti** (🔗 ADR-047).
 *
 * Serve alle prove di invio di Piattaforma → Email: prende la notifica vera, le
 * dà dati di esempio e ne restituisce il messaggio pronto. La vista, il tema e
 * il marchio sono quelli dell'email reale — è il punto della prova.
 *
 * ## 🔴 Nessun dato vero, mai
 *
 * Qui non si legge una riga di nessun cliente: macchine, interventi e persone
 * sono inventati in memoria e non toccano il database. Una prova che pescasse
 * «l'ultimo intervento» manderebbe a un indirizzo scelto a mano i dati di un
 * cliente a caso.
 *
 * L'unica cosa vera è il **marchio**: le email del cliente si vedono con quello
 * dell'Ente di chi prova (`CurrentTenant`), o con quello di Easy Lab se non ne
 * ha uno.
 *
 * ⚠️ I link dentro le prove non portano da nessuna parte: gli id sono zero e
 * le firme sono vere ma su oggetti che non esistono. Lo dice la pagina.
 */
final class CampioniEmail
{
    private const ENTE = 'Laboratorio di prova';

    private const CLIENTE = 'Laboratorio di prova srl';

    private const MACCHINA = 'Autoclave AC-200';

    private const UBICAZIONE = 'Laboratorio di prova › Microbiologia';

    /**
     * Il messaggio di prova di questa email, con l'oggetto marcato.
     *
     * `[Prova]` in testa all'oggetto e non nel corpo: è ciò che si legge nella
     * lista della posta, e una prova scambiata per un avviso vero è l'unico
     * danno che questa pagina può fare.
     */
    public static function messaggio(string $chiave, string $indirizzo): MailMessage
    {
        $destinatario = self::persona($indirizzo);
        [$notifica, $notificabile] = self::campione($chiave, $indirizzo, $destinatario);

        $messaggio = $notifica->toMail($notificabile);

        return $messaggio->subject('[Prova] '.($messaggio->subject ?? CatalogoEmail::trova($chiave)->nome));
    }

    /**
     * @return array{0: Notification, 1: object} la notifica e chi la «riceve»
     */
    private static function campione(string $chiave, string $indirizzo, User $persona): array
    {
        $ente = (int) (CurrentTenant::id() ?? 0);

        return match ($chiave) {
            CatalogoEmail::RIEPILOGO_SCADENZE => [new DigestScadenze($ente, self::ENTE, [
                self::riga('Taratura annuale', '-3 days'),
                self::riga('Manutenzione ordinaria', '+12 days'),
            ]), $persona],

            CatalogoEmail::AVVISO_OBSOLESCENZA => [new AvvisoObsolescenza($ente, self::ENTE, 10, [
                RigaObsolescenza::daStrumento((new Strumento)->forceFill([
                    'id' => 0, 'nome' => self::MACCHINA, 'data_installazione' => now()->subYears(12),
                ])),
            ]), $persona],

            CatalogoEmail::MACCHINA_SEGNALATA => [new MacchinaSegnalata(
                enteId: $ente, enteNome: self::ENTE, strumentoId: 0, strumentoNome: self::MACCHINA,
                ubicazione: self::UBICAZIONE, stato: 'Non idoneo', nonIdonea: true,
                motivo: 'Perdita di pressione durante il ciclo', autore: 'Giulia Verdi',
            ), $persona],

            CatalogoEmail::INTERVENTO_PROGRAMMATO => [new InterventoProgrammato(
                enteId: $ente, enteNome: self::ENTE, strumentoId: 0, strumentoNome: self::MACCHINA,
                ubicazione: self::UBICAZIONE, tipo: 'Manutenzione ordinaria', descrizione: 'Sostituzione guarnizioni',
                scadenza: now()->addDays(20)->format('d/m/Y'), assegnatario: 'Giulia Verdi', autore: 'Marco Bianchi',
            ), $persona],

            CatalogoEmail::INTERVENTO_ESEGUITO => [new InterventoEseguito(
                enteId: $ente, enteNome: self::ENTE, strumentoId: 0, strumentoNome: self::MACCHINA,
                ubicazione: self::UBICAZIONE, tipo: 'Taratura e certificazione', descrizione: 'Taratura annuale',
                eseguitoIl: now()->format('d/m/Y'), autore: 'Giulia Verdi', conReport: true,
                prossima: now()->addYear()->format('d/m/Y'),
            ), $persona],

            CatalogoEmail::INTERVENTO_ASSEGNATO => [new InterventoAssegnato(
                enteId: $ente, enteNome: self::ENTE, strumentoId: 0, strumentoNome: self::MACCHINA,
                ubicazione: self::UBICAZIONE, tipo: 'Manutenzione ordinaria', descrizione: 'Sostituzione guarnizioni',
                scadenza: now()->addDays(20)->format('d/m/Y'), assegnatoDa: 'Marco Bianchi',
            ), $persona],

            CatalogoEmail::ACCOUNT_BLOCCATO => [new AccountBloccato(self::CLIENTE), $persona],
            CatalogoEmail::PIANO_CAMBIATO => [new PianoCambiato(self::CLIENTE, 'Free', 'SaaS'), $persona],
            CatalogoEmail::INVITO => [new InvitoUtente(self::ENTE, $indirizzo, $ente ?: null), $persona],
            CatalogoEmail::PROPOSTA_PIANO => [new PropostaPiano(self::CLIENTE, 'SaaS', 4900, $indirizzo), $persona],
            CatalogoEmail::VERIFICA_INDIRIZZO => [new VerificaEmailRegistrazione($indirizzo), self::richiedente()],
            CatalogoEmail::ACCOUNT_GIA_ESISTENTE => [new AccountGiaEsistente, $persona],
            CatalogoEmail::BENVENUTO => [new BenvenutoRegistrazione(self::ENTE, $indirizzo), $persona],
            CatalogoEmail::ACCOUNT_ELIMINATO => [new AccountEliminato(self::CLIENTE), $persona],
            CatalogoEmail::NUOVO_ERRORE => [new NuovoErrore('App\\Exceptions\\EsempioDiProva', 'app/Esempio.php:42', 3, 0), $persona],
            CatalogoEmail::RECUPERO_PASSWORD => [new ResetPassword('token-di-prova'), $persona],
        };
    }

    /** La persona finta a cui l'email «arriva»: in memoria, mai salvata. */
    private static function persona(string $indirizzo): User
    {
        return (new User)->forceFill([
            'id' => 0,
            'name' => 'Persona di prova',
            'email' => $indirizzo,
        ]);
    }

    /**
     * Chi ha compilato il modulo pubblico, per l'email di verifica: un oggetto
     * qualunque con i quattro membri che quella notifica legge. Non il model
     * vero — le righe del modulo pubblico hanno i loro custodi, e questa
     * classe non è fra quelli.
     */
    private static function richiedente(): object
    {
        return new class
        {
            public string $nome_referente = 'Persona di prova';

            public string $nome_ente = 'Laboratorio di prova';

            public function getKey(): int
            {
                return 0;
            }

            public function improntaVerifica(): string
            {
                return 'impronta-di-prova';
            }
        };
    }

    /** Una riga del riepilogo, scaduta o in arrivo secondo la data. */
    private static function riga(string $descrizione, string $quando): RigaAvviso
    {
        return RigaAvviso::daIntervento(
            (new Intervento)->forceFill([
                'id' => 0,
                'strumento_id' => 0,
                'descrizione' => $descrizione,
                'stato' => StatoIntervento::NonFatto,
                'data_scadenza' => now()->modify($quando),
            ]),
            self::MACCHINA,
            null,
        );
    }
}

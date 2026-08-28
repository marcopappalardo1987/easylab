<?php

namespace App\Support\Mail;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Scopes\DepartmentScope;
use App\Models\Scopes\TenantScope;
use App\Models\UnitaOrganizzativa;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Il **marchio** con cui esce un'email: nome in testata, colore del filetto e
 * del pulsante, logo incorporato (🔗 ADR-011 canali; ADR-018 nessun bypass;
 * ADR-033 il marchio; ADR-034 le email non hanno tema).
 *
 * ## 🔴 L'unico punto in cui si decide di chi è il marchio, e prende un int
 *
 * `perEnte()` riceve **l'id dell'Ente**, e non lo legge da `auth()`, da
 * `CurrentTenant` o da `request()`. Non è pedanteria di firma: è l'unica difesa
 * che esista qui.
 *
 * In console e sulle code i global scope si **ritirano tutti**
 * (`CurrentTenant::shouldScope()` è falso — ADR-011), quindi sul worker
 * `UnitaOrganizzativa::find($id)` vede *qualunque* Ente. È ciò che permette al
 * digest di brandizzarsi — l'invio avviene fuori da una richiesta e non c'è
 * nessun tenant corrente da cui partire — ma significa anche che nulla, a valle,
 * impedirebbe di leggere l'Ente sbagliato. Se l'id venisse da `auth()`, un
 * digest accodato per l'Ente A e consegnato mentre un utente dell'Ente B è
 * autenticato uscirebbe col logo di B: la fuga fra tenant, per posta, dentro
 * un'email che nessuno rilegge. L'id arriva quindi **sempre dal payload della
 * notifica**, che è serializzato al momento dell'accodamento.
 *
 * ## Il fail-closed è «Easy Lab», mai «il tenant corrente»
 *
 * Ogni ricaduta — id nullo, Ente inesistente, Ente cestinato, nodo che non è un
 * Ente — porta a `piattaforma()`. Una vista che dimenticasse di passare il
 * marchio manda un'email **non brandizzata**, che è un difetto estetico; il
 * contrario manderebbe l'email di un cliente col marchio di un altro, che è un
 * incidente. È ADR-018 portato sulla posta.
 *
 * ⛔ **Il branding sta nel CORPO, mai nella busta.** Nessun `From` e nessun
 * `Reply-To` per tenant: ADR-011 mette la recapitabilità (SPF/DKIM/DMARC) sul
 * dominio Easy Lab, e un mittente sul dominio del cliente romperebbe
 * l'allineamento DKIM mandando tutto in spam.
 */
final class MarchioEmail
{
    /** `primary-600` del Design System (🔗 ADR-033). */
    public const COLORE_EASYLAB = '#06589c';

    /**
     * ⛔ **PNG e non SVG**, e non è una svista: Gmail, Outlook desktop e
     * Outlook.com non rendono SVG. Per la stessa ragione l'upload del tenant
     * accetta solo png/jpg. Il percorso è relativo a `public_path()`.
     */
    public const LOGO_EASYLAB = 'brand/easylab-logo.png';

    /** Il disco privato di `config/filesystems.php` (`throw => true`). */
    public const DISCO = 'documenti';

    /**
     * Il `cid:` già calcolato, e per quale `Message`.
     *
     * ⚠️ **Non è un'ottimizzazione: senza, il logo finisce nell'email DUE
     * volte.** Nel canale mail delle notifiche `MailChannel::buildView()`
     * restituisce due closure — html e testo — e `Mailer::addContent()` le
     * invoca **entrambe** con lo stesso `Illuminate\Mail\Message`. La vista
     * `mail/*.blade.php` è una sola e viene resa due volte, quindi `cid()`
     * verrebbe chiamata due volte e `embed()` aggiungerebbe due `DataPart`
     * distinti (il `cid` è casuale a ogni chiamata). Memorizzarlo sull'istanza
     * basta perché è la **stessa** istanza a viaggiare nei dati delle due
     * rese: `toMail()` la costruisce una volta sola.
     *
     * ⚠️ E non si può distinguere la resa testuale da quella html guardando il
     * tipo: il `TextMessage` che restituisce stringa vuota da `embed()` è usato
     * solo da `Mailable::buildView()`, **non** dal canale delle notifiche.
     */
    private ?string $cid = null;

    private ?int $cidPerMessaggio = null;

    private function __construct(
        public readonly string $nome,
        /** `#rrggbb`. */
        public readonly string $colore,
        /** `#ffffff` o `#0f172a`, **calcolato** — vedi `inchiostroSu()`. */
        public readonly string $coloreTesto,
        /** Percorso sul disco `documenti`; `null` = logo Easy Lab. */
        public readonly ?string $logoPath,
        public readonly bool $diPiattaforma,
    ) {}

    /**
     * Il marchio del prodotto: è il default di ogni vista email e la ricaduta
     * di ogni caso incerto.
     */
    public static function piattaforma(): self
    {
        return new self(
            nome: (string) config('app.name'),
            colore: self::COLORE_EASYLAB,
            coloreTesto: self::inchiostroSu(self::COLORE_EASYLAB),
            logoPath: null,
            diPiattaforma: true,
        );
    }

    /**
     * Il marchio dell'Ente indicato — o quello di piattaforma, se non se ne
     * trova uno.
     *
     * 🔴 **`withoutGlobalScopes()` con la lista esplicita, mai nudo.** Il nudo
     * toglierebbe anche `SoftDeletingScope`: è il difetto già pagato da
     * `Account::enti()` (ADR-032), che contava le sedi cestinate. Qui
     * significherebbe brandizzare col nome di un Ente cancellato — e
     * `UnitaOrganizzativa` usa entrambi i trait (`BelongsToTenant` +
     * `BelongsToOrgNode`), quindi gli scope da nominare sono due.
     *
     * ⚠️ **Mai un'eccezione da qui.** Il chiamante è `toMail()` di una notifica
     * `ShouldQueue`: un throw sarebbe un job fallito, cioè un digest o un
     * invito che non partono per un marchio.
     */
    public static function perEnte(?int $enteId): self
    {
        if ($enteId === null) {
            return self::piattaforma();
        }

        $ente = UnitaOrganizzativa::withoutGlobalScopes([TenantScope::class, DepartmentScope::class])
            ->whereKey($enteId)
            ->where('tipo', TipoUnitaOrganizzativa::Ente)
            ->first();

        if ($ente === null) {
            return self::piattaforma();
        }

        $colore = $ente->marchio_colore ?? self::COLORE_EASYLAB;

        return new self(
            nome: $ente->nome,
            colore: $colore,
            coloreTesto: self::inchiostroSu($colore),
            logoPath: $ente->marchio_logo_path,
            diPiattaforma: false,
        );
    }

    /**
     * L'inchiostro leggibile **su** un fondo: luminanza relativa WCAG (sRGB
     * linearizzato, 0.2126/0.7152/0.0722).
     *
     * ⚠️ **Il testo del pulsante si calcola e non si fissa a bianco.** Il colore
     * lo sceglie il cliente e può essere giallo canarino: bianco su giallo è
     * illeggibile. «Il contrasto è una relazione fra due colori, non una
     * proprietà di uno» (ADR-034) — e questo è il posto in cui quella frase
     * diventa codice.
     *
     * La soglia 0,4 (invece del classico 0,179 di WCAG) tiene il testo scuro
     * anche sui medi: su un fondo a metà strada `#0f172a` fa più contrasto del
     * bianco, ed è il caso in cui un cliente sbaglia più facilmente.
     */
    public static function inchiostroSu(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6 || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return '#ffffff';
        }

        $canale = function (int $componente): float {
            $c = $componente / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        $luminanza = 0.2126 * $canale((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $canale((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $canale((int) hexdec(substr($hex, 4, 2)));

        return $luminanza > 0.4 ? '#0f172a' : '#ffffff';
    }

    /**
     * L'URI `cid:` del logo incorporato, o `null` se non c'è un `Message` su cui
     * incorporarlo.
     *
     * ⚠️ **Il `cid` si calcola nella VISTA e si passa giù come attributo**: un
     * componente Blade anonimo non eredita i dati del padre, e `$message` viene
     * iniettato da `Mailer` nei dati della *vista*, quindi non è visibile dentro
     * `x-mail::message` né dentro `x-mail::header`.
     *
     * ⚠️ **`null` non è un errore**: `MailMessage::render()` — la strada che i
     * test usano per leggere il corpo — rende il markdown senza passare da
     * `Mailer`, quindi `$message` lì non esiste affatto. La testata resta
     * leggibile senza immagine, perché il nome dell'Ente è **testo**.
     *
     * ⚠️ Il `try/catch` **non è difensivismo**: il disco `documenti` ha
     * `throw => true` (deliberato, il docblock di `config/filesystems.php`
     * spiega perché), quindi un file cancellato a mano fa esplodere `get()` — e
     * in coda significa job fallito, cioè `failed()` che scrive «invito non
     * consegnato» per un logo mancante.
     */
    public function cid(mixed $message): ?string
    {
        if (! $message instanceof Message) {
            return null;
        }

        if ($this->cid !== null && $this->cidPerMessaggio === spl_object_id($message)) {
            return $this->cid;
        }

        $this->cidPerMessaggio = spl_object_id($message);

        if ($this->logoPath === null) {
            return $this->cid = $message->embed(public_path(self::LOGO_EASYLAB));
        }

        try {
            $disco = Storage::disk(self::DISCO);

            if (! $disco->exists($this->logoPath)) {
                throw new RuntimeException('file assente sul disco');
            }

            return $this->cid = $message->embedData(
                $disco->get($this->logoPath),
                'marchio.'.$this->estensione(),
                $this->mime(),
            );
        } catch (Throwable $e) {
            Log::warning('Logo del marchio non leggibile', [
                'path' => $this->logoPath,
                'errore' => $e->getMessage(),
            ]);

            return $this->cid = $message->embed(public_path(self::LOGO_EASYLAB));
        }
    }

    private function estensione(): string
    {
        return strtolower((string) pathinfo((string) $this->logoPath, PATHINFO_EXTENSION));
    }

    private function mime(): string
    {
        return match ($this->estensione()) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}

<?php

namespace App\Models;

use App\Support\Email\CatalogoEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * Un'email che l'applicazione ha consegnato, o provato a consegnare, al server
 * di posta (🔗 ADR-064; ADR-011 e ADR-047 le email, ADR-063 il server).
 *
 * **Modello di PIATTAFORMA: niente `BelongsToTenant`**, come `Errore`. Le email
 * partono soprattutto da console e da coda (il riepilogo delle 06:00, le
 * notifiche accodate), dove un tenant corrente non esiste; e chi legge il
 * registro è tenant-bound (ADR-018): uno scope filtrerebbe sul cliente di chi
 * guarda e nasconderebbe proprio le righe che servono. Il confine è il permesso
 * della pagina, `tenants.view_all`, che è nel set bloccato.
 *
 * Le righe le scrive solo `App\Support\Email\RegistroEmail`, agganciato agli
 * eventi di invio di Laravel: nessuna persona le crea o le modifica.
 *
 * ## Tre stati visibili, due scritti
 *
 * - **in invio**: la riga nasce quando l'email sta per partire;
 * - **accettata**: il server di posta l'ha presa in carico (per SES, con il suo
 *   id). Non vuol dire «letta», né «nella casella»: vuol dire che è partita da
 *   noi;
 * - **non partita**: ancora «in invio» dopo dieci minuti. Il server l'ha
 *   rifiutata o non ha risposto. Non si scrive: si deduce, perché Laravel non
 *   avvisa quando un invio fallisce. Il motivo sta in Piattaforma → Errori.
 *
 * Un'email accodata che fallisce e viene ritentata lascia una riga per
 * tentativo: legate dallo stesso `notifica_id`.
 *
 * ## Si conserva trenta giorni
 *
 * Decisione di Marco del 23 Nov 2026: il registro serve a vedere che cosa è
 * partito di recente, non a tenere un archivio di posta. Ogni notte alle 03:30
 * `model:prune` (`App\Support\Retention::MODELLI`) toglie le righe più vecchie
 * di trenta giorni.
 *
 * @property int $id
 * @property string|null $tipo
 * @property string|null $classe
 * @property string $destinatari
 * @property string|null $oggetto
 * @property string|null $mittente
 * @property string|null $mailer
 * @property string $stato
 * @property string|null $message_id
 * @property string|null $notifica_id
 * @property Carbon|null $accettata_at
 * @property Carbon $created_at
 */
class EmailInviata extends Model
{
    use Prunable;

    protected $table = 'email_inviate';

    public const STATO_IN_INVIO = 'in_invio';

    public const STATO_ACCETTATA = 'accettata';

    /** Stato visibile e mai scritto: «in invio» oltre la soglia. */
    public const STATO_NON_PARTITA = 'non_partita';

    /** Dopo quanti minuti un'email ancora «in invio» si considera non partita. */
    public const NON_PARTITA_DOPO_MINUTI = 10;

    public const GIORNI_DI_CONSERVAZIONE = 30;

    /**
     * ⛔ Nessun `$fillable`: le righe le scrive solo `RegistroEmail`, con
     * `forceFill()`. Un form non ha niente da scrivere qui.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'accettata_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::GIORNI_DI_CONSERVAZIONE));
    }

    public function statoVisibile(): string
    {
        if ($this->stato === self::STATO_ACCETTATA) {
            return self::STATO_ACCETTATA;
        }

        return $this->created_at->lt(now()->subMinutes(self::NON_PARTITA_DOPO_MINUTI))
            ? self::STATO_NON_PARTITA
            : self::STATO_IN_INVIO;
    }

    /**
     * Le righe nello stato visibile dato. Specchio di `statoVisibile()` in SQL:
     * la soglia dei dieci minuti va applicata anche al filtro, o «Non partite»
     * mostrerebbe anche le email che stanno partendo adesso.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNelloStato(Builder $query, string $stato): void
    {
        $soglia = now()->subMinutes(self::NON_PARTITA_DOPO_MINUTI);

        match ($stato) {
            self::STATO_ACCETTATA => $query->where('stato', self::STATO_ACCETTATA),
            self::STATO_NON_PARTITA => $query->where('stato', self::STATO_IN_INVIO)->where('created_at', '<', $soglia),
            self::STATO_IN_INVIO => $query->where('stato', self::STATO_IN_INVIO)->where('created_at', '>=', $soglia),
            // Vuoto o inventato (arriva dalla query string): nessun filtro, invece di una pagina vuota.
            default => null,
        };
    }

    /** Il nome che l'email ha in Piattaforma → Email, o un nome che dica che cos'era. */
    public function nomeTipo(): string
    {
        if ($this->tipo !== null && CatalogoEmail::esiste($this->tipo)) {
            return CatalogoEmail::trova($this->tipo)->nome;
        }

        return $this->classe === 'EmailDiProva' ? 'Prova di invio' : ($this->classe ?? 'Email');
    }
}

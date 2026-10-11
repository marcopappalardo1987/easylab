<?php

namespace App\Models;

use App\Enums\ConteggioStrumenti;
use App\Models\Concerns\AuditsDomainWrites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un piano commerciale del listino (ERD §4.3 — 🔗 ADR-035, ADR-002, ADR-032).
 *
 * Fino al 27 Ago 2026 il listino era `config('easylab.piani.catalogo')`:
 * cambiarlo voleva dire un commit, un deploy e uno sviluppatore. ADR-035 lo
 * sposta a database e ne fa una schermata di piattaforma. La config resta il
 * **bootstrap** (la migration di backfill la legge una volta sola), esattamente
 * come `config/rbac.php` è il bootstrap della matrice dei permessi — con una
 * differenza dichiarata e voluta: **qui non esiste un seeder rilanciabile**,
 * quindi il gesto che là distrugge le personalizzazioni qui non è nemmeno
 * esprimibile.
 *
 * **Niente `BelongsToTenant`**: è un modello di **piattaforma**, come `Account`
 * ed `Errore`. Un listino non appartiene a un Ente, e scoparlo al tenant
 * corrente lo renderebbe invisibile proprio a chi lo governa. L'esenzione è
 * dichiarata per nome in `TenantScopeGuardrailTest::NON_TENANT_MODELS`.
 *
 * **Niente soft delete, e niente cancellazione**: un piano è referenziato per
 * **stringa** da `accounts.piano`, e cancellarlo produrrebbe di colpo N clienti
 * «fuori catalogo» che nell'MRR valgono 0 €. Si **archivia** (`attivo = false`),
 * che toglie il piano dalle offerte future e basta.
 *
 * ⚠️ **`attivo` governa l'offribilità, mai l'esistenza.** `Piani::codici()` e
 * `Piani::esiste()` continuano a includere gli archiviati: è la condizione
 * perché un account rimasto su un piano ritirato continui a valere il suo
 * prezzo in `MetrichePiattaforma`, che itera sui codici a catalogo e butta il
 * resto in `pianiSconosciuti`.
 */
class Piano extends Model
{
    use AuditsDomainWrites;

    /** Il plurale italiano non si indovina: `piani`, non `pianos`. */
    protected $table = 'piani';

    /**
     * Solo ciò che un form della schermata può forgiare.
     *
     * **Fuori** restano `codice`, `gratuito`, `valuta` e le tre colonne di
     * Stripe, per la stessa ragione per cui `piano` e le colonne di lockout
     * stanno fuori dal `$fillable` di `Account`: non sono campi, sono **gesti**,
     * e li scrive `GovernoListino` con `forceFill()`.
     *
     * `codice` e `gratuito` sono di più: sono **immutabili dopo la creazione**.
     * Il codice perché `accounts.piano` lo conserva come stringa e rinominarlo
     * non aggiornerebbe nessun account — li renderebbe tutti fuori catalogo in
     * un colpo solo. `gratuito` perché ribaltarlo su un piano già venduto
     * marcherebbe come paganti dei clienti che non hanno alcuna subscription.
     */
    protected $fillable = [
        'etichetta',
        'max_enti',
        // Il tetto di strumenti e il modo di contarlo (ADR-049): campi del
        // form del listino come `max_enti`, e stare qui li mette nell'audit.
        'max_strumenti',
        'conteggio_strumenti',
        'prezzo_mensile_cent',
        'attivo',
        'ordine',
    ];

    /**
     * Stessa ragione di `Account`: il default di colonna si applica
     * all'INSERT, quindi un'istanza appena costruita leggerebbe `null` finché
     * qualcuno non la rilegge — e `gratuito` a null si comporta come `false`
     * per caso, non per decisione.
     */
    protected $attributes = [
        'gratuito' => false,
        'attivo' => true,
        'prezzo_mensile_cent' => 0,
        'valuta' => 'eur',
        'ordine' => 0,
        'conteggio_strumenti' => 'per_sede',
    ];

    protected function casts(): array
    {
        return [
            'gratuito' => 'boolean',
            'attivo' => 'boolean',
            'max_enti' => 'integer',
            'max_strumenti' => 'integer',
            'conteggio_strumenti' => ConteggioStrumenti::class,
            'prezzo_mensile_cent' => 'integer',
            'ordine' => 'integer',
            'stripe_sincronizzato_at' => 'datetime',
        ];
    }

    /** «Creazione piano», non «Creazione Piano»: nome-primo (ADR-027). */
    protected function nomeDominio(): string
    {
        return 'piano';
    }

    /**
     * L'**effetto** della sincronizzazione, non solo l'input del form: senza,
     * l'audit direbbe che qualcuno ha cambiato un prezzo e tacerebbe sul fatto
     * che quel prezzo è (o non è) arrivato su Stripe.
     *
     * `stripe_ultimo_errore` resta fuori di proposito: è un messaggio di
     * diagnosi che cambia a ogni tentativo, e riempirebbe il registro di righe
     * che non sono gesti di nessuno.
     *
     * @return list<string>
     */
    protected function attributiDerivatiTracciati(): array
    {
        return ['stripe_product_id', 'stripe_sincronizzato_at'];
    }

    /** Il tetto di strumenti detto a parole (🔗 ADR-049): vedi `ConteggioStrumenti::tetto()`. */
    public function tettoStrumentiInParole(): string
    {
        return $this->conteggio_strumenti->tetto($this->max_strumenti);
    }

    /** @return HasMany<PrezzoPiano, $this> */
    public function prezzi(): HasMany
    {
        return $this->hasMany(PrezzoPiano::class);
    }

    /**
     * Il price su cui si aprono le **nuove** subscription. Gli altri restano in
     * `prezzi_piano` e non sono spazzatura: sono ciò su cui i clienti vecchi
     * continuano a fatturare, e la sola strada con cui il webhook li riconosce.
     *
     * @return HasOne<PrezzoPiano, $this>
     */
    public function prezzoCorrente(): HasOne
    {
        return $this->hasOne(PrezzoPiano::class)->where('corrente', true);
    }
}

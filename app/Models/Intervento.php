<?php

namespace App\Models;

use App\Enums\StatoIntervento;
use App\Enums\TipoIntervento;
use App\Models\Concerns\AuditsDomainWrites;
use App\Models\Concerns\BelongsToOrgNodeThroughStrumento;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Semaforo;
use Carbon\CarbonInterface;
use Database\Factories\InterventoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Attività/intervento su uno strumento (ERD §5.2 — ADR-005/007/009): la fonte
 * di verità del semaforo. Una taratura è un intervento
 * `tipo = taratura_e_certificazione` (ADR-009; il valore `taratura` è stato
 * rimappato da ADR-021 il 3 Ago 2026). Visibile anche al ruolo Tenant.
 *
 * Non ha collocazione propria nell'albero: eredita la restrizione di reparto
 * dallo strumento (BelongsToOrgNodeThroughStrumento). NON usare BelongsToOrgNode
 * — filtrerebbe su `unita_organizzativa_id`, colonna qui inesistente.
 *
 * Invariante: `stato = fatto` ⇔ `data_esecuzione` valorizzata. Imposta
 * nell'hook `saving`, quindi usare segnaFatto()/riapri() e MAI un update
 * by-query (`Intervento::where(...)->update(...)` non emette eventi e
 * aggirerebbe l'invariante).
 *
 * Debito noto (ADR-015): al trasferimento cross-tenant di uno strumento gli
 * interventi devono seguirlo. BelongsToTenant blocca il cambio di `tenant_id`
 * sul model, quindi servirà un update by-query esplicito (che non passa per gli
 * eventi). L'invariante `interventi.tenant_id == strumenti.tenant_id` regge la
 * sicurezza di DepartmentThroughStrumentoScope: va preservata.
 */
class Intervento extends Model
{
    /** @use HasFactory<InterventoFactory> */
    /**
     * **Audit (ADR-027): il trait, e deliberatamente nient'altro.**
     *
     * Niente `attributiDerivatiTracciati()`: su `Garanzia` quell'hook serve
     * perché `data_scadenza_effettiva` — il campo che pilota il semaforo — sta
     * FUORI da `$fillable`. Qui tutto ciò che decide qualcosa (`stato`,
     * `data_esecuzione`, `data_scadenza`, `tipo`, `tecnico_id`) è già
     * assegnabile, quindi già tracciato: aggiungerlo «per simmetria»
     * duplicherebbe colonne e basta.
     *
     * Niente `nomeDominio()`: il default dà «intervento», che è il sostantivo
     * giusto.
     *
     * Niente `activity()` esplicite, e il criterio è quello di ADR-027 letto
     * per esteso: le esplicite servono quando l'informazione che conta **non è
     * una colonna** — in `Strumento::forzaSemaforo()` è il fatto che il forzato
     * SCAVALCA uno stato calcolato che in tabella non esiste. Qui invece
     * chiudere un intervento È scrivere `stato` e `data_esecuzione`: l'elenco
     * dei campi cambiati dice già tutto, e una riga esplicita in più
     * significherebbe due righe per un gesto solo.
     */
    use AuditsDomainWrites, BelongsToOrgNodeThroughStrumento, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'interventi';

    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'strumento_id',
        'tecnico_id',
        'descrizione',
        'tipo',
        'data_scadenza',
        'stato',
        'data_esecuzione',
    ];

    protected $attributes = [
        'stato' => StatoIntervento::NonFatto->value,
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoIntervento::class,
            'stato' => StatoIntervento::class,
            'data_scadenza' => 'date',
            'data_esecuzione' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Intervento $intervento): void {
            if ($intervento->stato === StatoIntervento::Fatto) {
                $intervento->data_esecuzione ??= today();

                return;
            }

            $intervento->data_esecuzione = null;
        });
    }

    /**
     * Spunta "Fatto" (permesso `interventi.complete`). Senza data esplicita
     * l'esecuzione è oggi.
     *
     * Allinea anche la data di montaggio dei ricambi registrati su questo
     * intervento: vedi `allineaRicambiAllaEsecuzione()`.
     */
    public function segnaFatto(?CarbonInterface $data = null): bool
    {
        $this->stato = StatoIntervento::Fatto;
        $this->data_esecuzione = $data;

        return DB::transaction(function (): bool {
            $salvato = $this->save();
            $this->allineaRicambiAllaEsecuzione();

            return $salvato;
        });
    }

    /**
     * Un pezzo è montato quando l'intervento viene ESEGUITO, non quando lo si
     * registra (ADR-022).
     *
     * Il servizio che crea le righe non può saperlo: su un intervento
     * pianificato `data_esecuzione` è NULL e ripiega su oggi, così un ricambio
     * annotato in anticipo risultava «montato» giorni prima che qualcuno lo
     * toccasse. La data vera si conosce solo qui, alla chiusura — quindi è qui
     * che si scrive.
     *
     * Sta nel model e non nel componente perché `segnaFatto()` è **l'unica
     * via** per chiudere un intervento (mai update by-query, vedi il docblock
     * della classe): ogni chiamante presente e futuro — la scheda oggi, la
     * vista mobile del tecnico domani — eredita l'allineamento senza doverlo
     * ricordare.
     *
     * ⚠️ La `data_inizio` della garanzia segue il montaggio, ma **solo se
     * resta prima della scadenza dichiarata**: il model esige quel confine, e
     * spostare l'inizio oltre una scadenza ravvicinata farebbe esplodere la
     * chiusura di un intervento per colpa di un refuso in una riga ricambio.
     * In quel caso la garanzia si lascia com'è: un dato informativo incoerente
     * è meno grave di un'operazione che non si può più completare. La scadenza
     * — l'unica grandezza che pilota il semaforo — non è toccata in nessun caso.
     *
     * `riapri()` NON riporta indietro le date: un pezzo montato resta montato,
     * e riaprire un intervento significa "c'è ancora da fare", non "non è mai
     * successo".
     *
     * ✅ **Deciso il 15 Ago 2026** (tab Ricambi), al posto del rinvio che stava
     * qui: la data di montaggio è ora editabile, e una riga corretta a mano
     * porta `data_manuale = true`. Questo metodo **la salta**. Delle tre uscite
     * possibili è l'unica che non perde silenziosamente il lavoro di una
     * persona: allineare solo le righe NULL avrebbe reso la chiusura incapace
     * di correggere una data automatica sbagliata, e lasciar vincere
     * l'automatismo avrebbe fatto sparire una correzione senza dirlo.
     *
     * **La regola dell'allineamento non vive più qui**: sta in
     * `RicambioUtilizzo::fissaMontaggio()`, che questo metodo chiama. Era
     * `protected`, quindi esercitabile solo chiudendo un intervento — e la
     * correzione dal tab avrebbe avuto bisogno della stessa regola, cioè di
     * una seconda copia dello stesso confine.
     */
    protected function allineaRicambiAllaEsecuzione(): void
    {
        $esecuzione = $this->data_esecuzione;

        if ($esecuzione === null) {
            return;
        }

        $this->ricambiUtilizzi()->where('data_manuale', false)->get()
            ->each(fn (RicambioUtilizzo $utilizzo) => $utilizzo->fissaMontaggio($esecuzione));
    }

    /**
     * Torna a "non fatto": l'hook `saving` azzera la data di esecuzione.
     */
    public function riapri(): bool
    {
        $this->stato = StatoIntervento::NonFatto;

        return $this->save();
    }

    /**
     * Scaduto-non-fatto (ADR-005): unica definizione di "in ritardo", che il
     * motore semaforo (punto 4) riusa invece di ridefinirla. Un intervento in
     * scadenza OGGI non è ancora scaduto.
     *
     * NON usare `data_scadenza->isPast()`: il cast `date` produce un Carbon a
     * mezzanotte, quindi una scadenza di oggi risulterebbe passata alle 00:01.
     * `scadute()` è il gemello SQL: un test ne verifica l'allineamento.
     */
    public function isScaduto(): bool
    {
        return $this->stato === StatoIntervento::NonFatto
            && $this->data_scadenza->lt(today());
    }

    /**
     * Gemello SQL di isScaduto(), per filtrare tra più strumenti (semaforo,
     * scadenzario, scheduler S5). Confronto diretto sulla colonna `date`: un
     * `whereDate()` applicherebbe DATE() e butterebbe via l'indice
     * (strumento_id, stato, data_scadenza).
     *
     * @param  Builder<Intervento>  $query
     */
    public function scopeScadute(Builder $query): void
    {
        $query->where('stato', StatoIntervento::NonFatto->value)
            ->where('data_scadenza', '<', today()->toDateString());
    }

    /**
     * Interventi aperti la cui scadenza è già passata O cade entro la soglia
     * "imminente": esattamente le due condizioni che accendono l'arancione
     * (ADR-005). È la forma SQL della regola di App\Support\Semaforo::calcola(),
     * e serve a filtrare TRA strumenti (elenco, dashboard S6) dove il calcolo
     * per-model non è utilizzabile senza rompere la paginazione.
     *
     * La soglia arriva da Semaforo, non è riscritta qui: un test verifica che
     * questo scope e il calcolo per-model restino d'accordo.
     *
     * Il confine è INCLUSIVO (oggi+soglia è imminente) ma si esprime come
     * `< oggi+soglia+1`, MAI come `<= oggi+soglia`: su SQLite le date sono
     * stringhe 'Y-m-d H:i:s' e `'2026-08-31 00:00:00' <= '2026-08-31'` è falso
     * (confronto lessicografico), mentre su Postgres è vero. Con `<` sul giorno
     * successivo i due driver danno lo stesso risultato.
     *
     * @param  Builder<Intervento>  $query
     */
    public function scopeApertiEntroSoglia(Builder $query): void
    {
        $query->where('stato', StatoIntervento::NonFatto->value)
            ->where('data_scadenza', '<', today()->addDays(Semaforo::giorniImminente() + 1)->toDateString());
    }

    /**
     * Nome dell'assegnatario per la UI, mai quello di un utente di un altro
     * Ente: `tecnico_id` è una FK su `users`, che NON ha il TenantScope. I
     * Tecnici esterni (`tenant_id` null, ADR-007) restano legittimamente
     * visibili. La validazione di `tecnico_id` in scrittura arriva col punto 3.
     */
    public function tecnicoLabel(): string
    {
        $tecnico = $this->tecnico;

        if ($tecnico === null) {
            return '—';
        }

        return $tecnico->tenant_id === null || $tecnico->tenant_id === $this->tenant_id
            ? $tecnico->name
            : '—';
    }

    public function strumento(): BelongsTo
    {
        return $this->belongsTo(Strumento::class, 'strumento_id');
    }

    /**
     * Assegnatario dell'intervento (ADR-007).
     */
    public function tecnico(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    /**
     * Pezzi montati durante questo intervento (ERD §7.2 — ADR-022), il più
     * recente in alto.
     *
     * È anche l'idioma con cui si risolve una riga da rimuovere:
     * `$intervento->ricambiUtilizzi()->findOrFail($id)` riapplica TenantScope e
     * il livello 2 e vincola `intervento_id`, quindi copre in un colpo altro
     * tenant, fuori sotto-albero e id appartenente a un altro intervento.
     */
    public function ricambiUtilizzi(): HasMany
    {
        return $this->hasMany(RicambioUtilizzo::class, 'intervento_id')
            // ⚠️ NULL = «non ancora montato», e va IN CIMA: è la riga che
            // aspetta qualcosa, non la più vecchia. Il CASE è esplicito perché
            // SQLite ordina i NULL per primi e Postgres per ultimi — senza,
            // l'ordine cambierebbe fra locale e produzione (trappola nota).
            ->orderByRaw('case when data is null then 0 else 1 end')
            ->orderByDesc('data')
            ->orderByDesc('id');
    }
}

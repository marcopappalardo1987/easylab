<?php

namespace App\Models;

use App\Enums\StatoIntervento;
use App\Enums\StatoSemaforo;
use App\Models\Concerns\BelongsToOrgNode;
use App\Models\Concerns\BelongsToTenant;
use App\Support\AuditLog;
use App\Support\DiagnosiSemaforo;
use App\Support\MotivoSemaforo;
use App\Support\Semaforo;
use Database\Factories\StrumentoFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * Asset/strumento (ERD §5.1). Collocato in un nodo dell'albero
 * (`unita_organizzativa_id` = ubicazione). Usa il default `orgNodeColumn`
 * (`unita_organizzativa_id`), quindi eredita gratis l'isolamento per Ente
 * (BelongsToTenant) e la restrizione sotto-albero del Responsabile
 * (BelongsToOrgNode).
 */
class Strumento extends Model
{
    /** @use HasFactory<StrumentoFactory> */
    use BelongsToOrgNode, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'strumenti';

    /**
     * NOTA: le colonne `forced_*` sono deliberatamente ESCLUSE. Il form della
     * scheda e l'import CSV scrivono per mass-assignment: tenendole fuori,
     * nessun payload — presente o futuro — può forzare il semaforo scavalcando
     * forzaSemaforo()/rimuoviForzatura(), che sono l'unica via.
     */
    protected $fillable = [
        'tenant_id',
        'reseller_id',
        'unita_organizzativa_id',
        'nome',
        'modello',
        'matricola',
        'parametri_tecnici',
        'data_installazione',
    ];

    protected function casts(): array
    {
        return [
            'parametri_tecnici' => 'array',
            'data_installazione' => 'date',
            'forced_state' => StatoSemaforo::class,
            'forced_at' => 'datetime',
        ];
    }

    /**
     * Ubicazione corrente (nodo dipartimento/sotto-laboratorio).
     */
    public function unita(): BelongsTo
    {
        return $this->belongsTo(UnitaOrganizzativa::class, 'unita_organizzativa_id');
    }

    /**
     * Attività/interventi (ERD §5.2): storico passato + pianificati, scadenza
     * più recente in alto. Fonte di verità del semaforo (ADR-005).
     */
    public function interventi(): HasMany
    {
        return $this->hasMany(Intervento::class, 'strumento_id')
            ->orderByDesc('data_scadenza')
            ->orderByDesc('id');
    }

    /**
     * Soglia di obsolescenza del proprio Ente (ADR-014), in anni. Configurabile
     * per tenant dall'anagrafica; il fallback a 10 copre i contesti in cui il
     * nodo ente non è leggibile (fixture senza tenant, job non scopati).
     */
    public function sogliaObsolescenza(): int
    {
        return $this->tenant?->soglia_obsolescenza_anni ?? 10;
    }

    /**
     * Obsolescenza (ADR-014): `(oggi − data_installazione) >= soglia`, campo
     * DERIVATO e mai persistito. Confine INCLUSIVO — installato esattamente N
     * anni fa oggi è già obsoleto.
     *
     * Senza `data_installazione` non è mai obsoleto: manca la base del calcolo,
     * e dichiararlo tale sarebbe un'affermazione non sostenuta dai dati.
     *
     * È SOLO una segnalazione: non blocca la manutenzione e **non tocca il
     * semaforo** — il badge ⏳ convive col pallino invece di alterarlo
     * (Design System §4).
     */
    public function isObsoleto(): bool
    {
        if ($this->data_installazione === null) {
            return false;
        }

        return $this->data_installazione->lte(today()->subYears($this->sogliaObsolescenza()));
    }

    /**
     * Garanzie del macchinario (ERD §6.1), la più vicina a scadere in alto.
     * Solo `soggetto = macchina`: le righe `ricambio` hanno `strumento_id` NULL
     * e si raggiungeranno via `ricambio_utilizzo` in S4.
     */
    public function garanzie(): HasMany
    {
        return $this->hasMany(Garanzia::class, 'strumento_id')
            ->orderBy('data_scadenza_effettiva')
            ->orderBy('id');
    }

    /**
     * Garanzia con la scadenza effettiva più vicina: il secondo ingresso del
     * semaforo (ADR-004/005). Speculare a prossimoInterventoAperto().
     *
     * Nota privacy per S4: il semaforo è un AGGREGATO e dovrà considerare anche
     * le garanzie ricambio, bypassando GaranziaRicambioPrivacyScope — il
     * pallino non rivela la riga. La colonna "Prossima scadenza" dell'elenco è
     * invece un DETTAGLIO e dovrà restare filtrata per permesso.
     */
    public function prossimaGaranzia(): ?Garanzia
    {
        if ($this->relationLoaded('garanzie')) {
            return $this->garanzie
                ->sortBy([['data_scadenza_effettiva', 'asc'], ['id', 'asc']])
                ->first();
        }

        return $this->garanzie()->first(); // la relazione ordina già asc
    }

    /**
     * Interventi aperti (non_fatto), scadenza più vicina in cima: la fonte da
     * cui nascono sia il "prossimo" sia i motivi della diagnosi (ADR-024).
     *
     * Se la relazione è già caricata la riusa (zero query extra); altrimenti
     * una get() servita dall'indice (strumento_id, stato, data_scadenza).
     * `reorder()` è OBBLIGATORIO: la relazione ordina per data_scadenza DESC,
     * quindi senza si otterrebbe l'ordine rovesciato.
     *
     * @return Collection<int, Intervento>
     */
    public function interventiAperti(): Collection
    {
        if ($this->relationLoaded('interventi')) {
            return $this->interventi
                ->filter(fn (Intervento $i) => $i->stato === StatoIntervento::NonFatto)
                ->sortBy([['data_scadenza', 'asc'], ['id', 'asc']])
                ->values();
        }

        return $this->interventi()->reorder()
            ->where('stato', StatoIntervento::NonFatto->value)
            ->orderBy('data_scadenza')->orderBy('id')
            ->get();
    }

    /**
     * Prossimo intervento aperto (scadenza minima): serve alla colonna
     * "Prossima scadenza" e al blocco Panoramica.
     *
     * È il primo di `interventiAperti()` e non una query a sé: il filtro
     * "aperto" e l'ordinamento vivono in un posto solo. Costa una riga in più
     * letta dal DB rispetto a una LIMIT 1, su un insieme che è quello di un
     * singolo strumento — il calcolo bulk dell'elenco, che è il path dove i
     * volumi contano, non passa di qui.
     */
    public function prossimoInterventoAperto(): ?Intervento
    {
        return $this->interventiAperti()->first();
    }

    /**
     * Diagnosi del semaforo (ADR-024): stato **e motivi che lo determinano**.
     *
     * Il modello assembla i CANDIDATI dalle proprie fonti — è lui a sapere
     * quali sono — e il motore decide quali superano la soglia. Nessun
     * confronto con la soglia qui, nessuna nozione di "fonte" là: le due
     * responsabilità restano separate, ed è ciò che permetterà a S4 di
     * aggiungere le garanzie ricambio (ADR-020) toccando solo questo metodo.
     *
     * Non sono motivi, di proposito: l'**obsolescenza** (ADR-014 — segnalazione
     * sull'età, non tocca il semaforo) e la **forzatura** (vince sullo stato ma
     * non spiega il calcolato: la Panoramica le mostra entrambe, ADR-005).
     */
    public function diagnosiSemaforo(): DiagnosiSemaforo
    {
        $garanzie = $this->relationLoaded('garanzie') ? $this->garanzie : $this->garanzie()->get();

        return Semaforo::diagnostica(
            ...$this->interventiAperti()->map(MotivoSemaforo::daIntervento(...)),
            ...$garanzie->map(MotivoSemaforo::daGaranziaMacchina(...)),
        );
    }

    /**
     * Stato calcolato (ADR-005): derivato, MAI persistito. Considera sia gli
     * interventi aperti sia le garanzie, entrambi ridotti a una data — le
     * garanzie ci arrivano già normalizzate in `data_scadenza_effettiva`
     * (ADR-004), quindi il motore non sa nulla di ore né di durate.
     *
     * Delega alla diagnosi (ADR-024) invece di chiamare `Semaforo::calcola()`
     * sui minimi: header, audit delle forzature e Panoramica condividono così
     * UNA derivazione e non possono mostrare stati diversi. Equivalente al
     * calcolo bulk dell'elenco, che resta sui minimi: min(date) <= soglia
     * ⇔ esiste una data <= soglia (due alignment test lo verificano).
     */
    public function statoSemaforoCalcolato(): StatoSemaforo
    {
        return $this->diagnosiSemaforo()->stato;
    }

    /**
     * Stato mostrato = forzato se presente, altrimenti calcolato (ADR-005).
     * La UI deve chiamare SEMPRE questo metodo, mai statoSemaforoCalcolato():
     * è l'unico punto in cui la forzatura vince, e vale anche per le forme SQL
     * dell'elenco (filtro e ordinamento), che replicano questa stessa regola.
     */
    public function statoSemaforoEffettivo(): StatoSemaforo
    {
        return $this->forced_state ?? $this->statoSemaforoCalcolato();
    }

    /**
     * Utente che ha forzato il semaforo (ADR-005).
     */
    public function forcedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forced_by');
    }

    /**
     * Forza manualmente il semaforo (ADR-005, permesso `semaforo.force`): lo
     * stato forzato vince su quello calcolato finché non viene rimosso. La
     * forzatura non nasconde nulla — la lista interventi resta la fonte di
     * verità e continua a mostrare gli scaduti.
     *
     * Il motivo è OBBLIGATORIO solo per il rosso: dichiarare uno strumento
     * "non idoneo" senza dire perché lascia l'audit log senza la sola
     * informazione che conta. Per verde e arancione resta opzionale (ADR-005:
     * "opzionale ma raccomandato"). La guardia sta qui e non solo nel form:
     * vale per qualunque chiamante futuro (QR S4, scheduler S5).
     *
     * `forced_by` è sempre l'utente autenticato, mai un valore in ingresso.
     */
    public function forzaSemaforo(StatoSemaforo $stato, ?string $motivo = null): bool
    {
        if ($stato === StatoSemaforo::Rosso && blank($motivo)) {
            throw new InvalidArgumentException('Il motivo è obbligatorio quando si forza il rosso (ADR-005).');
        }

        $calcolato = $this->statoSemaforoCalcolato();

        $this->forced_state = $stato;
        $this->forced_by = auth()->id();
        $this->forced_at = now();
        $this->forced_reason = blank($motivo) ? null : $motivo;

        $salvato = $this->save();

        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($this)
            ->withProperties([
                'stato_calcolato' => $calcolato->value,
                'forced_state' => $stato->value,
                'motivo' => $this->forced_reason,
            ])
            ->log('Semaforo forzato');

        return $salvato;
    }

    /**
     * Rimuove la forzatura: lo stato mostrato torna a quello calcolato.
     * Loggata come la forzatura — riabilitare uno strumento dichiarato non
     * idoneo è un atto sensibile quanto dichiararlo tale.
     */
    public function rimuoviForzatura(): bool
    {
        $precedente = $this->forced_state;

        $this->forced_state = null;
        $this->forced_by = null;
        $this->forced_at = null;
        $this->forced_reason = null;

        $salvato = $this->save();

        activity(AuditLog::NAME)
            ->causedBy(auth()->user())
            ->performedOn($this)
            ->withProperties([
                'forced_state_rimosso' => $precedente?->value,
                'stato_calcolato' => $this->statoSemaforoCalcolato()->value,
            ])
            ->log('Forzatura semaforo rimossa');

        return $salvato;
    }

    /**
     * Storico spostamenti (append-only), più recente in alto.
     */
    public function spostamenti(): HasMany
    {
        return $this->hasMany(SpostamentoStrumento::class, 'strumento_id')
            ->orderByDesc('data')
            ->orderByDesc('id');
    }

    /**
     * Pezzi montati su questa macchina (ERD §7.2 — ADR-008/022), il più recente
     * in alto: è l'ordine con cui il tab Ricambi li mostrerà.
     *
     * Nota per ADR-020: le garanzie di questi pezzi peseranno sul semaforo dello
     * strumento, ma NON si raggiungono da qui — servirà una relazione dedicata
     * che bypassi `GaranziaRicambioPrivacyScope`, perché il pallino è un
     * aggregato dovuto a tutti mentre il dettaglio no.
     */
    public function ricambiUtilizzati(): HasMany
    {
        return $this->hasMany(RicambioUtilizzo::class, 'strumento_id')
            // ⚠️ NULL = «non ancora montato», e va IN CIMA: è la riga che
            // aspetta qualcosa, non la più vecchia. Il CASE è esplicito perché
            // SQLite ordina i NULL per primi e Postgres per ultimi — senza,
            // l'ordine cambierebbe fra locale e produzione (trappola nota).
            ->orderByRaw('case when data is null then 0 else 1 end')
            ->orderByDesc('data')
            ->orderByDesc('id');
    }
}

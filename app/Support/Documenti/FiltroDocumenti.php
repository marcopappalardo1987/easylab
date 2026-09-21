<?php

namespace App\Support\Documenti;

use App\Enums\TipoDocumento;
use App\Models\Documento;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * «Quali documenti sta guardando l'utente» — l'unica definizione, condivisa
 * dallo schermo e dal foglio (🔗 ERD §8.1, ADR-026, ADR-031).
 *
 * **Esiste per non averne due.** L'elenco `/documenti` e l'indice PDF
 * `/documenti/export.pdf` applicano gli *stessi* cinque filtri agli *stessi*
 * dati: scritti due volte divergerebbero, e il giorno in cui divergono il foglio
 * direbbe una cosa e lo schermo un'altra — su un archivio documentale, dove il
 * PDF è la prova che si porta a una verifica ispettiva, è la peggiore forma di
 * bugia possibile, perché nessuno dei due sembra rotto.
 *
 * ⚠️ **Non è un confine di sicurezza e non deve diventarlo.** Riceve una query
 * `Documento::query()` **già scopata** (`TenantScope`, `DepartmentThroughStrumentoScope`
 * via la colonna denormalizzata `strumento_id`, `SoftDeletingScope`) e vi
 * aggiunge soltanto le restrizioni *chieste dall'utente*. Nessun
 * `where('tenant_id', …)` «per sicurezza»: sarebbe una seconda definizione del
 * confine, e la seconda definizione è quella che diverge. Per la stessa ragione
 * `strumentoId` non viene validato contro l'appartenenza — un id estraneo cade
 * sugli scope e produce zero righe, che è la risposta giusta; sono le *opzioni
 * del select* a doversi prendere da una query scopata, non questo filtro.
 *
 * ⚠️ **Ogni valore arriva dalla query string** (`#[Url]` sul componente,
 * `Request::query()` sul controller) e nessuno è fidato: il tipo si valida
 * contro `TipoDocumento`, l'id macchina deve essere un numero positivo, le date si
 * scartano se non si lasciano leggere, il testo si neutralizza dai jolly di
 * LIKE. Le due strade — costruttore e `daRichiesta()` — passano dalla
 * **stessa** validazione, o la pagina e il foglio tornerebbero a essere due
 * definizioni.
 *
 * 🔴 **Ed è già successo, su `strumentoId`.** `daRichiesta()` filtrava con
 * `ctype_digit()`; il componente riceveva invece il valore *già coercito* da
 * Livewire nella property tipizzata `?int`, che accetta `-5`, `+5` e `3.0`. Per
 * la stessa URL lo schermo diceva «Nessun documento con questi filtri» e il
 * foglio stampava tutto l'archivio annunciando «Nessun filtro». Da lì la
 * regola: **le porte passano il valore grezzo e non ne scartano nessuno**, il
 * costruttore normalizza, e la soglia è una sola — `idMacchina()`, scelta per
 * combaciare con la conversione che Livewire fa a monte.
 */
final class FiltroDocumenti
{
    /**
     * Un filtro ha davvero ristretto **questa** applicazione?
     *
     * Serve al messaggio del vuoto, e la distinzione non è cosmetica: «Nessun
     * documento con questi filtri» davanti a un archivio genuinamente vuoto
     * manda a cercare un filtro da togliere che non esiste. Lo alza `applica()`
     * dai **rami davvero applicati** — un valore rifiutato dalla whitelist non
     * restringe niente, e contarlo spiegherebbe il vuoto con una causa che non
     * c'è (lezione di `RegistroAudit::$filtriApplicati` e di
     * `ElencoStrumenti::haFiltriAttivi()`).
     */
    private bool $haRistretto = false;

    /**
     * L'id della macchina **già normalizzato**, o `null` se non era un id.
     *
     * 🔴 **Non è promossa dal costruttore, e la ragione è un difetto vero.** Le
     * due porte validavano `strumentoId` con regole diverse — `ctype_digit()`
     * nel controller, la coercizione di PHP sulla property tipizzata di Livewire
     * a schermo — e per `?strumentoId=-5` lo schermo mostrava «Nessun documento
     * con questi filtri» mentre il foglio stampava l'intero archivio dicendo
     * «Nessun filtro». Due definizioni dello stesso confine, che è esattamente
     * ciò che questa classe esiste per non avere. La normalizzazione vive quindi
     * **qui dentro** e le due porte le passano il valore **grezzo**.
     */
    public readonly ?int $strumentoId;

    public function __construct(
        public readonly ?string $tipo = null,
        int|string|null $strumentoId = null,
        public readonly ?string $dal = null,
        public readonly ?string $al = null,
        public readonly ?string $cerca = null,
    ) {
        $this->strumentoId = self::idMacchina($strumentoId);
    }

    /**
     * Un id di macchina, o `null`.
     *
     * ⚠️ **Filtro sul valore, non un cast nudo**: `(int) 'abc'` è `0`, cioè un
     * filtro attivo su un id che nessuno ha chiesto — e un elenco vuoto senza
     * spiegazione. Uno zero o un negativo non sono un id: nessuna riga può
     * averli come chiave, quindi «filtrare» su di essi può solo svuotare la
     * pagina senza poterlo spiegare.
     *
     * ⚠️ **`is_numeric()` e non `ctype_digit()`, ed è una scelta obbligata da
     * Livewire.** Un valore di query string che *sembra* un numero arriva alla
     * property del componente già convertito (`?strumentoId=3.0` → `3.0` →
     * `'3'`), mentre uno che non lo sembra resta la stringa che era. Con
     * `ctype_digit()` questa funzione avrebbe scartato `3.0` sul foglio e
     * accettato il `'3'` dello schermo: la stessa divergenza fra le due porte
     * che questa classe esiste per non avere, spostata di un passo invece che
     * chiusa. `is_numeric()` riproduce la stessa soglia da entrambi i lati, e
     * `EsportaElencoDocumentiTest` la congela su sette valori storti.
     */
    private static function idMacchina(int|string|null $grezzo): ?int
    {
        if ($grezzo === null) {
            return null;
        }

        $testo = trim((string) $grezzo);

        return is_numeric($testo) && (int) $testo > 0 ? (int) $testo : null;
    }

    /**
     * La stessa istanza, costruita dalla query string di una richiesta HTTP.
     *
     * È la porta del controller PDF, e legge **le stesse chiavi** che Livewire
     * mette in query string dalle proprie `#[Url]`: è ciò che rende vero
     * «l'export esporta quello che vedi», perché il link del foglio è costruito
     * dai parametri dello schermo (`parametri()`).
     */
    public static function daRichiesta(Request $richiesta): self
    {
        // `query()` e non `input()`: questa rotta è una GET, e leggere anche il
        // body accetterebbe filtri da una sorgente che l'interfaccia non usa.
        $testo = function (string $chiave) use ($richiesta): ?string {
            $valore = $richiesta->query($chiave);

            return is_string($valore) && trim($valore) !== '' ? trim($valore) : null;
        };

        $id = $richiesta->query('strumentoId');

        return new self(
            tipo: $testo('tipo'),
            // ⚠️ **Grezzo**, e non validato qui: la regola è una sola e vive nel
            // costruttore. Validarlo su questa porta significherebbe averne due,
            // e le due porte divergerebbero — è già successo. Il solo controllo
            // che resta è di *forma*: `?strumentoId[]=1` arriva come array, che
            // non è un valore scalare da normalizzare.
            strumentoId: is_string($id) ? $id : null,
            dal: $testo('dal'),
            al: $testo('al'),
            cerca: $testo('cerca'),
        );
    }

    /**
     * I filtri **superstiti alla validazione**, nell'unica forma da cui tutto il
     * resto discende.
     *
     * ⚠️ È qui che vive la regola, e non in tre copie: le clausole di
     * `applica()`, il flag `haRistretto()` e i parametri del link all'export
     * leggono tutti questo array. Una lista scritta a mano nel Blade o nel
     * componente diverge alla prima `#[Url]` nuova — è già successo su
     * `ElencoStrumenti`, dove il messaggio del vuoto nominava due filtri su
     * cinque.
     *
     * @return array{tipo?: string, strumentoId?: int, dal?: CarbonImmutable, al?: CarbonImmutable, cerca?: string}
     */
    private function rami(): array
    {
        $rami = [];

        // **Tipo.** Whitelist sull'enum: il valore finisce in una clausola e
        // viene dalla query string. Un `?tipo=qualunque` non filtra nulla, e
        // — per la riga sopra — non viene nemmeno annunciato come filtro.
        if ($this->tipo !== null && TipoDocumento::tryFrom($this->tipo) !== null) {
            $rami['tipo'] = $this->tipo;
        }

        // Già normalizzato dal costruttore — l'unico posto in cui la regola è
        // scritta, e quindi l'unico da cui schermo e foglio possono discendere.
        if ($this->strumentoId !== null) {
            $rami['strumentoId'] = $this->strumentoId;
        }

        if (($confine = $this->giorno($this->dal)) !== null) {
            $rami['dal'] = $confine;
        }

        if (($confine = $this->giorno($this->al)) !== null) {
            $rami['al'] = $confine;
        }

        if ($this->cerca !== null && trim($this->cerca) !== '') {
            $rami['cerca'] = trim($this->cerca);
        }

        return $rami;
    }

    /**
     * Applica i filtri a una query **già scopata**.
     *
     * @param  Builder<Documento>  $query
     * @return Builder<Documento>
     */
    public function applica(Builder $query): Builder
    {
        $rami = $this->rami();

        // Azzerato e ricalcolato a ogni applicazione: descrive **questa**
        // applicazione, non la storia dell'oggetto. Non è un secondo controllo
        // sulle property — è lo stesso array da cui nascono le clausole qui
        // sotto, quindi «filtro applicato» e «filtro annunciato» non possono
        // essere due cose diverse.
        $this->haRistretto = $rami !== [];

        if (isset($rami['tipo'])) {
            $query->where('tipo', $rami['tipo']);
        }

        // `strumento_id` e non il morph: è la colonna denormalizzata su cui
        // poggia già il livello 2 dello scope, quindi il filtro cade sullo
        // stesso indice del confine di sicurezza.
        if (isset($rami['strumentoId'])) {
            $query->where('strumento_id', $rami['strumentoId']);
        }

        // ⚠️ **Confine mezzo aperto, e mai `whereDate()`.** `documenti.created_at`
        // è un timestamp: `'2026-08-20'` si legge come `2026-08-20 00:00:00`,
        // quindi `<= $al` taglierebbe via tutta la giornata finale tranne la sua
        // mezzanotte. E `whereDate()` avvolge la colonna in una funzione
        // (`strftime` su SQLite, `::date` su Postgres): semantica diversa per
        // driver e indice inutilizzabile. Copiato alla lettera da
        // `RegistroAudit::filtrata()`, dove la stessa riga è già stata pagata.
        if (isset($rami['dal'])) {
            $query->where('created_at', '>=', $rami['dal']);
        }

        if (isset($rami['al'])) {
            $query->where('created_at', '<', $rami['al']->addDay());
        }

        // **Cerca.** L'unico predicato non indicizzato della pagina: un
        // `LIKE '%…%'` su `nome` forza una scansione. È accettato di proposito
        // — un archivio si cerca per nome file e la tabella cresce con gli
        // allegati di un laboratorio, non col traffico — ma chi un giorno la
        // trovasse lenta sappia che è questa riga.
        if (isset($rami['cerca'])) {
            $query->whereRaw("LOWER(nome) LIKE ? ESCAPE '\\'", ['%'.$this->jolly($rami['cerca']).'%']);
        }

        return $query;
    }

    /** Almeno un filtro è stato applicato dall'ultima `applica()`. */
    public function haRistretto(): bool
    {
        return $this->haRistretto;
    }

    /**
     * I soli filtri attivi, nella forma in cui tornano in query string.
     *
     * Serve alla vista per costruire il link dell'indice PDF **con gli stessi
     * valori dello schermo**: senza, il foglio parlerebbe di un insieme diverso
     * da quello che si sta guardando, e nessuno dei due direbbe quale.
     *
     * @return array<string, string|int>
     */
    public function parametri(): array
    {
        $rami = $this->rami();

        $parametri = [];

        foreach ($rami as $chiave => $valore) {
            $parametri[$chiave] = $valore instanceof CarbonImmutable ? $valore->format('Y-m-d') : $valore;
        }

        return $parametri;
    }

    /**
     * La descrizione testuale dei filtri attivi, per il piede del foglio.
     *
     * Un PDF ritrovato fra un anno deve dire **di quale sottoinsieme** parla: un
     * indice di venti righe senza questa frase si legge come «l'archivio ha
     * venti documenti», che è una conclusione sbagliata a partire da un dato
     * giusto.
     *
     * @param  array<int, string>  $nomiMacchina  id → etichetta, per non stampare «macchina #7»
     */
    public function descrizione(array $nomiMacchina = []): string
    {
        $rami = $this->rami();
        $pezzi = [];

        if (isset($rami['tipo'])) {
            $pezzi[] = 'tipo: '.TipoDocumento::from($rami['tipo'])->label();
        }

        if (isset($rami['strumentoId'])) {
            $pezzi[] = 'macchina: '.($nomiMacchina[$rami['strumentoId']] ?? '#'.$rami['strumentoId']);
        }

        if (isset($rami['dal'])) {
            $pezzi[] = 'dal '.$rami['dal']->format('d/m/Y');
        }

        if (isset($rami['al'])) {
            $pezzi[] = 'al '.$rami['al']->format('d/m/Y');
        }

        if (isset($rami['cerca'])) {
            $pezzi[] = 'nome contiene «'.$rami['cerca'].'»';
        }

        return $pezzi === [] ? 'Nessun filtro: tutto l\'archivio visibile.' : 'Filtri: '.implode(' · ', $pezzi).'.';
    }

    /**
     * Un termine di ricerca reso innocuo per LIKE.
     *
     * Senza, `%` da solo restituisce **ogni** riga e `_` diventa «un carattere
     * qualunque»: un filtro che si aggira digitando un carattere non è un
     * filtro. `ESCAPE` va dichiarato perché SQLite, a differenza di Postgres,
     * non ne ha uno di default.
     *
     * ⚠️ **La ricerca accentata trova su Postgres e non su SQLite**, ed è la
     * divergenza già documentata in `RegistroAudit::jolly()`: il termine passa
     * da `mb_strtolower()` (UTF-8-aware) e la colonna da `LOWER()` del driver,
     * che su SQLite converte **solo A–Z**. Cercare «società» trova in produzione
     * e in CI, **non in locale** — direzione insolita, e su un progetto in
     * italiano non è un caso di scuola. Chi vorrà chiuderla dovrà normalizzare
     * gli accenti a monte, non cambiare questa funzione.
     */
    private function jolly(string $termine): string
    {
        return addcslashes(mb_strtolower(trim($termine)), '%_\\');
    }

    /**
     * La mezzanotte di una data, o `null` se non si lascia leggere.
     *
     * **Si ignora in silenzio invece di lanciare**: il valore arriva dalla query
     * string, e un link con `?dal=ieri` deve mostrare l'archivio senza quel
     * filtro, non una pagina d'errore. Un input `type=date` non produce niente
     * di diverso da `Y-m-d`, quindi il caso non si presenta dall'interfaccia —
     * si presenta da un link incollato a mano, che è precisamente la strada da
     * cui non deve arrivare un 500.
     */
    private function giorno(?string $valore): ?CarbonImmutable
    {
        if ($valore === null || trim($valore) === '') {
            return null;
        }

        try {
            $giorno = CarbonImmutable::parse(trim($valore))->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }

        // Anni fuori da 1..9999 si ignorano come un valore illeggibile
        // (T1cB-7): Carbon legge «0000-01-01» e «+100000000 years», Postgres
        // no, e un link incollato darebbe un 500 invece dell'elenco.
        return $giorno->year >= 1 && $giorno->year <= 9999 ? $giorno : null;
    }
}

<?php

namespace App\Support\Piattaforma;

use App\Support\Piani;

/**
 * Su **quali clienti** si sta guardando il parco (🔗 ADR-037).
 *
 * Oggetto di valore, senza una query dentro: la lettura cross-tenant è materia
 * di `ParcoClienti`, che resta la porta unica di questa funzione. Qui vive solo
 * la **forma** del perimetro — «tutti», «quelli su un piano», «questi qui» — e
 * la normalizzazione di ciò che arriva dal browser.
 *
 * ## Due rivalidazioni, e stanno in due posti diversi apposta
 *
 * Il perimetro è tre proprietà pubbliche di un componente Livewire, quindi
 * arriva dal browser a ogni update e **non è un input fidato**. Si rivalida due
 * volte, e le due metà non sono ridondanti:
 *
 * 1. **Qui**: la forma. Un `modo` che non è uno dei tre, o un piano che non è a
 *    catalogo, non producono un perimetro «un po' sbagliato» — producono
 *    `nessuno()`. Fail-closed, come ovunque nel progetto.
 * 2. **In `ParcoClienti::clienti()`**: l'appartenenza. Gli id scelti si
 *    **intersecano** con `VistaPiattaforma::accounts()`, che è l'insieme
 *    legittimo. Un id forgiato non trova corrispondenza e sparisce; non c'è
 *    modo di scriverlo come unione, perché il `whereIn` si applica *sopra* la
 *    porta e non accanto.
 *
 * ⚠️ **`nessuno()` è `scelti([])`, e non un quarto modo.** Un `whereIn` su un
 * array vuoto compila `0 = 1` su entrambi i driver: l'insieme vuoto è già
 * esprimibile, e inventargli un modo a parte significherebbe un ramo in più nel
 * `match` di `ParcoClienti` — cioè un ramo in più in cui, un giorno, qualcuno
 * scriverebbe «e allora mostriamo tutto». Il difetto che si teme ha un
 * precedente in questo progetto: l'export che ignorava i filtri e portava via
 * l'intero elenco invece della pagina che l'utente stava guardando.
 *
 * ⛔ **`scelti([])` NON significa «tutti».** È la stessa trappola vista da
 * dentro: «nessuno selezionato» è la condizione più facile da leggere come
 * «nessun filtro», e su una vista cross-cliente la differenza fra le due
 * letture è fra zero righe e le righe di tutti. Un test negativo la congela.
 */
final readonly class Perimetro
{
    /** Tutti i clienti della piattaforma. */
    public const TUTTI = 'tutti';

    /** I clienti su un piano commerciale. */
    public const PER_PIANO = 'piano';

    /** Gli account scelti a mano, uno per uno. */
    public const SCELTI = 'scelti';

    /**
     * I clienti **preferiti** di chi sta guardando (🔗 ADR-037).
     *
     * Stessa forma di `SCELTI` — una lista di id — e sorgente opposta: quelli
     * arrivano dal browser a ogni richiesta, questi dal database. La differenza
     * non è cosmetica e non si poteva fondere in un modo solo, perché è ciò che
     * decide **quale controllo** la pagina disegna e quale messaggio dà quando
     * l'insieme è vuoto: «scegline almeno uno dal filtro qui sopra» manda a una
     * `<select>` che nel modo preferiti non esiste.
     */
    public const PREFERITI = 'preferiti';

    /**
     * @param  list<int>  $accountIds
     */
    private function __construct(
        public string $modo,
        public array $accountIds,
        public ?string $piano,
    ) {}

    public static function tutti(): self
    {
        return new self(self::TUTTI, [], null);
    }

    /**
     * I clienti di un piano — **se quel piano esiste**.
     *
     * Un codice che non è a catalogo non allarga a «tutti» e non lancia: dà
     * l'insieme vuoto. Lanciare significherebbe una pagina rotta per un valore
     * arrivato da una querystring, allargare significherebbe che un filtro
     * scritto male mostra *più* di quanto chiesto — e fra i due errori il
     * secondo è quello che non si vede.
     */
    public static function perPiano(string $piano): self
    {
        return Piani::esiste($piano)
            ? new self(self::PER_PIANO, [], $piano)
            : self::nessuno();
    }

    /**
     * Gli account scelti a mano.
     *
     * Gli id si normalizzano a interi e si deduplicano: ciò che arriva da una
     * `<select multiple>` è un array di stringhe, e un id ripetuto duplicherebbe
     * le righe di un `whereIn` senza che nessuno capisca perché.
     *
     * @param  array<int|string>  $accountIds
     */
    public static function scelti(array $accountIds): self
    {
        return new self(self::SCELTI, self::idValidi($accountIds), null);
    }

    /**
     * Gli id normalizzati: interi, deduplicati, positivi.
     *
     * Una sola copia per i due modi che portano una lista: `scelti()` e
     * `preferiti()` devono normalizzare **allo stesso modo**, o la stessa lista
     * darebbe due perimetri diversi a seconda di come ci è arrivata.
     *
     * @param  array<int|string>  $accountIds
     * @return list<int>
     */
    private static function idValidi(array $accountIds): array
    {
        $id = array_values(array_unique(array_map('intval', $accountIds)));

        return array_values(array_filter($id, fn (int $i) => $i > 0));
    }

    /**
     * I clienti preferiti, **già letti dal database**.
     *
     * 🔴 Non si costruisce da `daRichiesta()` di proposito: gli id non arrivano
     * dal browser, quindi il posto in cui questo perimetro nasce è uno solo —
     * `App\Support\Piattaforma\Preferiti::perimetro()`, che è gata. Passare
     * qui `$this->accountIds` di un componente Livewire significherebbe
     * riaprire, sotto un nome nuovo, esattamente la selezione arbitraria che
     * questo modo sostituisce.
     *
     * ⚠️ La normalizzazione resta identica a `scelti()` — interi, deduplicati,
     * positivi — perché la difesa vera non è la provenienza: è l'intersezione
     * con `VistaPiattaforma::accounts()` che `ParcoClienti::clienti()` applica
     * sopra questo `whereIn`. Un preferito verso un account cestinato dopo la
     * segnatura sparisce **là**, non qui.
     *
     * @param  array<int|string>  $accountIds
     */
    public static function preferiti(array $accountIds): self
    {
        return new self(self::PREFERITI, self::idValidi($accountIds), null);
    }

    /** L'insieme vuoto, che è dove cade ogni input che non si è capito. */
    public static function nessuno(): self
    {
        return new self(self::SCELTI, [], null);
    }

    /**
     * Il perimetro come arriva dal browser, normalizzato.
     *
     * ⚠️ Il `default` del `match` **non è** `tutti()`: un `modo` sconosciuto è
     * un input che non si è capito, e la risposta a un input che non si è capito
     * su una vista cross-cliente è «niente», non «tutto».
     *
     * @param  array<int|string>  $accountIds
     */
    public static function daRichiesta(?string $modo, array $accountIds = [], ?string $piano = null): self
    {
        return match ($modo) {
            self::TUTTI => self::tutti(),
            self::PER_PIANO => $piano === null || $piano === '' ? self::nessuno() : self::perPiano($piano),
            self::SCELTI => self::scelti($accountIds),
            // ⛔ `PREFERITI` **non** si costruisce da qui, e cade quindi nel
            // `default`: i suoi id vivono nel database, e prenderli da questo
            // argomento — che è l'array pubblico di un componente Livewire —
            // significherebbe lasciare al browser la lista che il modo esiste
            // per sottrargli. Un chiamante che se ne dimenticasse ottiene zero
            // righe, non le righe di tutti: l'errore cade dalla parte giusta.
            default => self::nessuno(),
        };
    }

    /**
     * Vero quando il perimetro non può contenere nessun cliente.
     *
     * Serve alla UI per dire «non hai selezionato nessun cliente» invece di
     * mostrare una tabella vuota che si legge come «non hai clienti» — la stessa
     * distinzione fra 403 e builder vuoto che `VistaPiattaforma` fa sul permesso.
     */
    public function eNessuno(): bool
    {
        return in_array($this->modo, [self::SCELTI, self::PREFERITI], true)
            && $this->accountIds === [];
    }
}

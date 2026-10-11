<?php

namespace App\Support;

use App\Enums\ConteggioStrumenti;
use App\Models\Piano;
use App\Support\Listino\CatalogoPiani;
use InvalidArgumentException;

/**
 * Accesso tipizzato al listino dei piani.
 *
 * ⚠️ **Dal 27 Ago 2026 la fonte è il DATABASE, non `config/easylab.php`**
 * (🔗 ADR-035). La chiave `easylab.piani.catalogo` è rimasta, ma è solo il
 * **bootstrap**: la legge una volta sola la migration di backfill, e dopo di
 * quella la verità è la tabella `piani`, governata da `/piattaforma/piani`.
 * È lo stesso patto di `config/rbac.php` ↔ la matrice a database (ADR-016 §7),
 * con una differenza voluta: là esiste un seeder rilanciabile — e proprio
 * quello rende distruttivo il gesto corretto — qui **non esiste**, quindi la
 * trappola non va documentata perché non è esprimibile.
 *
 * L'unica chiave di config che resta **viva** è `easylab.piani.predefinito`:
 * non è il listino, è «con che piano nasce un account» — un parametro di
 * prodotto come `giorni_imminente`, e nessuna schermata lo crea. La coerenza
 * col database è tenuta da un test (`ListinoBootstrapTest`), non da una guardia
 * a runtime: `predefinito()` è chiamato da un **webhook** (disdetta →
 * decadimento a free), e lì un'eccezione farebbe ritentare Stripe per giorni.
 *
 * Questa classe resta **l'unica porta**: chi vuole sapere quanti Enti può avere
 * un account non interroga `Piano` a mano, che fra sei mesi sarebbe scritto in
 * quattro posti con quattro default diversi. Le viscere sono cambiate; l'API
 * pubblica no.
 *
 * 🔗 ADR-032 (il limite di Enti è un attributo del piano, fatto rispettare al
 * provisioning e non nello scope), ADR-002 (Free omaggiato vs SaaS a pagamento),
 * ADR-035 (il listino a database).
 *
 * **Perché i getter lanciano invece di ripiegare su un default.** Un piano che
 * non esiste a listino è un dato corrotto — `accounts.piano` è fuori
 * `$fillable` e lo scrive solo `Account::cambiaPiano()`, che valida. Un `?? 1`
 * silenzioso trasformerebbe la corruzione in un limite sbagliato applicato a un
 * cliente vero, cioè nel genere di errore che si scopre dal reclamo. Chi lo
 * consuma in una vista aggregata non propaga però l'eccezione:
 * `MetrichePiattaforma` itera su `codici()` e raccoglie il resto in «piano
 * sconosciuto», perché è l'unica schermata da cui quel dato si ripara.
 */
class Piani
{
    /**
     * I codici a listino, nell'ordine `ordine, id`.
     *
     * 🔴 **Gli archiviati ci sono**, ed è la riga più facile da sbagliare:
     * archiviare un piano ≠ toglierlo dal catalogo. Se `codici()` filtrasse su
     * `attivo`, ogni account rimasto sul piano ritirato diventerebbe «fuori
     * catalogo» e varrebbe **0 €** nell'MRR di `MetrichePiattaforma`. `attivo`
     * governa solo l'**offribilità** — vedi `offribili()`.
     *
     * @return list<string>
     */
    public static function codici(): array
    {
        return array_keys(self::catalogo()->tutti());
    }

    /** I soli piani che si possono ancora vendere. */
    public static function offribili(): array
    {
        return array_keys(array_filter(self::catalogo()->tutti(), fn (Piano $p) => $p->attivo));
    }

    /**
     * I piani che si possono **comprare** adesso: offribili, a pagamento e con
     * un price di Stripe su cui aprire la subscription (🔗 ADR-045).
     *
     * È la domanda comune a ogni superficie che vende — il modulo pubblico, la
     * proposta della cabina, il cambio di piano del cliente — e sta qui perché
     * sia una sola: tre copie divergerebbero al primo piano «a pagamento ma non
     * ancora sincronizzato», e una delle tre aprirebbe un checkout su niente.
     *
     * ⚠️ **`filled()` e non `!== null`**: `phpunit.xml` azzera
     * `STRIPE_PRICE_SAAS`, quindi in suite `stripePrice()` può tornare la
     * stringa vuota. Un confronto con `null` la lascerebbe passare fino alla
     * chiamata di rete.
     *
     * @return list<string>
     */
    public static function vendibili(): array
    {
        return array_values(array_filter(
            self::offribili(),
            fn (string $codice) => ! self::eGratuito($codice) && filled(self::stripePrice($codice)),
        ));
    }

    public static function esiste(string $piano): bool
    {
        return self::catalogo()->perCodice($piano) !== null;
    }

    /**
     * Il piano con cui nasce un account che non ne ha scelto uno.
     *
     * Resta in config: vedi il docblock di classe.
     */
    public static function predefinito(): string
    {
        return config('easylab.piani.predefinito', 'free');
    }

    /** Il model, per chi deve scriverlo. Lancia come gli altri getter. */
    public static function modello(string $piano): Piano
    {
        return self::risolvi($piano);
    }

    public static function etichetta(string $piano): string
    {
        return self::risolvi($piano)->etichetta;
    }

    /**
     * Quanti Enti può avere un account su questo piano.
     *
     * `null` significa **illimitato**, non «non lo so»: è la forma che un piano
     * Enterprise avrebbe, e dichiararla evita che domani la si esprima con un
     * numero grande a caso.
     */
    public static function maxEnti(string $piano): ?int
    {
        $max = self::risolvi($piano)->max_enti;

        return $max === null ? null : (int) $max;
    }

    /**
     * Quanti strumenti consente questo piano (🔗 ADR-049).
     *
     * `null` significa **illimitato**, come per `maxEnti()`. A che cosa si
     * applichi il numero lo dice `conteggioStrumenti()`; chi deve decidere se
     * uno strumento in più ci sta non compone le due cose a mano, passa da
     * `App\Support\Billing\TettoStrumenti`.
     */
    public static function maxStrumenti(string $piano): ?int
    {
        $max = self::risolvi($piano)->max_strumenti;

        return $max === null ? null : (int) $max;
    }

    /** Se il tetto di strumenti vale per ogni sede o per il cliente in tutto. */
    public static function conteggioStrumenti(string $piano): ConteggioStrumenti
    {
        return self::risolvi($piano)->conteggio_strumenti;
    }

    /**
     * Il price id **corrente** del piano, o `null`.
     *
     * È quello su cui si aprono le **nuove** subscription. Chi è già abbonato
     * resta al suo, che vive in `prezzi_piano` e si risolve da `perPrice()`.
     */
    public static function stripePrice(string $piano): ?string
    {
        return self::risolvi($piano)->prezzi->firstWhere('corrente', true)?->stripe_price_id;
    }

    /**
     * L'importo del price **corrente** del piano, in centesimi, o `null` se il
     * piano non ne ha uno (gratuito, o a pagamento non ancora sincronizzato).
     *
     * ⚠️ **Non è `prezzoMensileCent()`**, e la differenza è ciò che Stripe
     * addebita davvero: quello è il listino, questo è l'importo con cui il
     * Price è stato creato — che su Stripe è immutabile. I due coincidono
     * quando il piano è sincronizzato, e divergono per il tempo che passa fra un
     * cambio di prezzo e la sua sincronizzazione. Chi mostra una cifra accanto
     * a un bottone che apre un pagamento legge **questo** (🔗 ADR-045).
     */
    public static function importoCorrenteCent(string $piano): ?int
    {
        $importo = self::risolvi($piano)->prezzi->firstWhere('corrente', true)?->importo_cent;

        return $importo === null ? null : (int) $importo;
    }

    /**
     * Quanto addebita un price id, **anche se storico**, o `null` se quel price
     * non è a listino. Vedi `CatalogoPiani::importoPerPrice()`.
     */
    public static function importoDelPrice(?string $priceId): ?int
    {
        return self::catalogo()->importoPerPrice($priceId);
    }

    /**
     * Il prezzo di **listino** del piano, in centesimi interi.
     *
     * Alimenta l'MRR della dashboard di piattaforma. Centesimi e non euro-float:
     * un totale su decine di clienti in virgola mobile accumula errore, e
     * nessuno rilegge la riga che lo fa.
     *
     * ⚠️ È il listino, non l'incassato: lo stesso prezzo vive su Stripe e i due
     * possono divergere. Dal 27 Ago 2026 la divergenza **si vede**:
     * `/piattaforma/piani` la mostra coi due valori affiancati.
     */
    public static function prezzoMensileCent(string $piano): int
    {
        return (int) self::risolvi($piano)->prezzo_mensile_cent;
    }

    /**
     * Un piano **omaggiato**: nessun customer Stripe, nessuna subscription
     * (ADR-002 — il Free è a fronte di un contratto di manutenzione fisico,
     * fatturato fuori dal software).
     *
     * ⚠️ È una **dichiarazione** del listino e non l'assenza di un price, e la
     * differenza si vede in `easylab:abbona`: un piano a pagamento col price
     * non ancora creato è un errore di configurazione da segnalare per nome,
     * non un piano gratuito. Dedurre la gratuità dal prezzo mancante li
     * confonderebbe, e il piano da sincronizzare resterebbe invisibile dietro
     * un messaggio rassicurante.
     */
    public static function eGratuito(string $piano): bool
    {
        return (bool) self::risolvi($piano)->gratuito;
    }

    /** L'inverso di `eGratuito()`: questo piano passa da Stripe. */
    public static function richiedeStripe(string $piano): bool
    {
        return ! self::eGratuito($piano);
    }

    /**
     * Il piano che corrisponde a un price id di Stripe, o `null` se quel price
     * non è a listino.
     *
     * Serve al webhook per riallineare `accounts.piano` a ciò che il cliente ha
     * davvero su Stripe. `null` è un esito legittimo e non un errore: un price
     * creato a mano in dashboard non deve far esplodere un handler di webhook —
     * chi chiama decide cosa farne.
     *
     * 🔴 **Risolve anche i price STORICI**, ed è la ragione per cui
     * `prezzi_piano` è una tabella e non una colonna: su Stripe un Price è
     * immutabile, quindi al primo cambio di listino ogni cliente già abbonato
     * fattura su un price che non è più «quello del piano». Guardando il solo
     * corrente, il loro piano smetterebbe di riallinearsi **per sempre**.
     */
    public static function perPrice(?string $priceId): ?string
    {
        return self::catalogo()->codicePerPrice($priceId);
    }

    private static function risolvi(string $piano): Piano
    {
        $modello = self::catalogo()->perCodice($piano);

        if ($modello === null) {
            throw new InvalidArgumentException(
                "Piano «{$piano}» non a listino. Piani validi: ".implode(', ', self::codici()).'.'
            );
        }

        return $modello;
    }

    /**
     * Il memo per-richiesta. `app()` e non una property statica: una statica
     * sopravviverebbe fra un test e l'altro, mentre il container si ricostruisce
     * — ed è precisamente questa proprietà che rende inutile un reset globale.
     */
    private static function catalogo(): CatalogoPiani
    {
        return app(CatalogoPiani::class);
    }
}

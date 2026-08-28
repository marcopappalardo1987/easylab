<?php

namespace App\Support\Listino;

use App\Models\Piano;
use App\Models\PrezzoPiano;
use App\Support\Listino\Stripe\PortaListinoStripe;
use App\Support\Piani;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 🔴 Le regole del listino, in un posto solo (🔗 ADR-035).
 *
 * Sul modello di `App\Support\Rbac\MatriceRuoli`: **le guardie sono la
 * feature**, e rifiutano **prima** di toccare il database — così una richiesta
 * forgiata a mano non lascia né una riga di piano né una riga di audit.
 *
 * `ValidationException` e non un 403: chi arriva qui ha già `billing.manage_global`
 * (il gate di rotta e quello in testa a ogni azione). Il gesto è vietato **per
 * chiunque**, non per lui — cambiare il codice di un piano non è un privilegio
 * che qualcuno possa avere.
 *
 * ## Le due immutabilità, e perché non sono pedanteria
 *
 * - **`codice`**: `accounts.piano` lo conserva come **stringa senza FK e senza
 *   CHECK** (è la forma già scelta, perché il webhook la scrive per codice).
 *   Rinominarlo non aggiornerebbe nessun account: in un colpo solo tutti i
 *   clienti su quel piano diventerebbero «fuori catalogo», cioè varrebbero
 *   **0 €** nell'MRR di `MetrichePiattaforma`.
 * - **`gratuito`**: ribaltarlo su un piano già venduto marcherebbe come paganti
 *   dei clienti che non hanno alcuna subscription — o il contrario. Chi vuole
 *   l'altro comportamento crea un piano nuovo e migra a mano.
 *
 * ## L'ordine dei passi, che è obbligato
 *
 * ⚠️ **Le chiamate di rete stanno FUORI dalla transazione.** Un `DB::transaction`
 * che contiene una chiamata a Stripe tiene un lock aperto per la durata del
 * round-trip, e soprattutto **il rollback non annulla ciò che Stripe ha già
 * creato**. L'ordine è quindi: guardie → scrittura locale in transazione (con
 * la riga di audit dentro, prodotta dal trait) → chiamata a Stripe fuori →
 * seconda scrittura breve che registra gli id e `stripe_sincronizzato_at`,
 * oppure `stripe_ultimo_errore`. È la stessa disciplina di `AbbonaAccount`,
 * dove «tutte le guardie stanno prima di qualunque chiamata di rete».
 *
 * ## L'idempotenza è a due livelli, e uno solo non basta
 *
 * La `idempotency_key` di Stripe **scade dopo 24 ore**, quindi non protegge il
 * retry di domani. Prima di creare un Product si guarda `piani.stripe_product_id`;
 * prima di creare un Price si confrontano importo e valuta con la riga
 * `corrente` di `prezzi_piano`. Se non è cambiato nulla **non si chiama Stripe
 * affatto**: il gesto è un no-op che non lascia gemelli.
 *
 * ## L'audit
 *
 * Lo produce il trait `AuditsDomainWrites` su `Piano`, quindi qui **non c'è
 * nessuna `activity()`** — la regola «o il trait o le esplicite, mai entrambi»
 * (ADR-027) è resa meccanica da `AuditCoverageGuardrailTest`, che legge il
 * sorgente del model.
 */
final class GovernoListino
{
    /**
     * ⚠️ Il leading underscore è **escluso apposta**: la sentinella del filtro
     * della cabina (`ElencaClienti::FUORI_CATALOGO`) vale `__fuori_catalogo`, e
     * un piano che si chiamasse così renderebbe indistinguibili «il piano X» e
     * «nessun piano riconosciuto».
     */
    private const CODICE_VALIDO = '/^[a-z][a-z0-9_]{1,29}$/';

    /**
     * Crea un piano.
     *
     * ⚠️ **`gratuito ⇒ prezzo = 0`, e NON l'inverso.** Un piano a pagamento a
     * 0 € è legittimo — è una promozione: ha una subscription vera e deve
     * restare `gratuito = false`. L'invariante inversa («chi costa 0 è
     * gratuito») vietava quel caso, e `PianiTest` la rifiuta per nome dal
     * blocco Cashier. Questa direzione invece protegge l'MRR da denaro mai
     * fatturato.
     */
    public static function crea(array $dati): Piano
    {
        $codice = trim((string) ($dati['codice'] ?? ''));
        $etichetta = trim((string) ($dati['etichetta'] ?? ''));
        $gratuito = (bool) ($dati['gratuito'] ?? false);
        $prezzo = (int) ($dati['prezzo_mensile_cent'] ?? 0);
        $maxEnti = self::maxEntiValidato($dati);

        if (preg_match(self::CODICE_VALIDO, $codice) !== 1) {
            throw ValidationException::withMessages([
                'codice' => "«{$codice}» non è un codice valido: minuscole, cifre e underscore, con un'iniziale alfabetica, da 2 a 30 caratteri. È la stringa che finisce in `accounts.piano`, e non si può più cambiare dopo.",
            ]);
        }

        if (Piano::query()->where('codice', $codice)->exists()) {
            throw ValidationException::withMessages([
                'codice' => "Esiste già un piano «{$codice}». I codici sono unici perché `accounts.piano` li conserva per stringa.",
            ]);
        }

        self::verificaEtichetta($etichetta);
        self::verificaPrezzo($prezzo);

        if ($gratuito && $prezzo !== 0) {
            throw ValidationException::withMessages([
                'prezzo_mensile_cent' => 'Un piano gratuito non può avere un prezzo: «gratuito» significa nessun customer e nessuna subscription (ADR-002). Un piano a pagamento a 0 € — una promozione — è invece ammesso: togliere la spunta.',
            ]);
        }

        $piano = DB::transaction(function () use ($codice, $etichetta, $gratuito, $prezzo, $maxEnti, $dati) {
            $piano = new Piano;

            // `forceFill()` per le colonne fuori-`$fillable`: `codice`,
            // `gratuito` e `valuta` non sono campi di un form, sono la
            // definizione del piano. La valuta viene da `cashier.currency`, cioè
            // dall'unico posto in cui viveva prima di questa feature — ed è
            // registrarla sulla riga che rende possibile il confronto con Stripe
            // senza indovinare.
            $piano->forceFill([
                'codice' => $codice,
                'etichetta' => $etichetta,
                'gratuito' => $gratuito,
                'prezzo_mensile_cent' => $prezzo,
                'max_enti' => $maxEnti,
                'valuta' => (string) config('cashier.currency', 'eur'),
                'attivo' => true,
                'ordine' => (int) ($dati['ordine'] ?? 0),
            ])->save();

            return $piano;
        });

        app(CatalogoPiani::class)->dimentica();

        return $piano;
    }

    /**
     * L'anagrafica: etichetta, tetto di Enti, ordine.
     *
     * Rifiuta ogni tentativo di cambiare `codice` o `gratuito`, **anche quando
     * il valore passato è uguale a quello attuale**? No: un valore identico è
     * un no-op e passa. A essere rifiutato è il **cambiamento**, che è la cosa
     * che fa danno — così un form che rimanda tutti i suoi campi non è costretto
     * a sapere quali omettere.
     *
     * ⚠️ **Abbassare `max_enti` non è vietato e non cestina niente.** Chi scende
     * sotto il proprio numero di sedi le tiene tutte — nessuna viene chiusa,
     * nessuno viene bloccato — e semplicemente non ne apre altre
     * (grandfathering, ADR-032: `slotEntiResidui()` diventa negativo, ed è uno
     * stato legittimo e documentato). La conseguenza va **mostrata prima del
     * click**, con il conteggio degli account che finirebbero sopra il limite:
     * è una decisione da far prendere a un umano, non una guardia.
     */
    public static function aggiornaAnagrafica(Piano $piano, array $dati): Piano
    {
        if (array_key_exists('codice', $dati) && (string) $dati['codice'] !== $piano->codice) {
            throw ValidationException::withMessages([
                'codice' => "Il codice di un piano non si cambia: «{$piano->codice}» è la stringa conservata in `accounts.piano` di ogni cliente su questo piano, e rinominarla li renderebbe tutti fuori catalogo — cioè 0 € nell'MRR. Si crea un piano nuovo e si migra a mano.",
            ]);
        }

        if (array_key_exists('gratuito', $dati) && (bool) $dati['gratuito'] !== (bool) $piano->gratuito) {
            throw ValidationException::withMessages([
                'gratuito' => 'La gratuità di un piano non si cambia dopo la creazione: ribaltarla su un piano già venduto marcherebbe come paganti dei clienti senza subscription, o il contrario (ADR-002). Si crea un piano nuovo.',
            ]);
        }

        $etichetta = trim((string) ($dati['etichetta'] ?? $piano->etichetta));
        self::verificaEtichetta($etichetta);

        $maxEnti = array_key_exists('max_enti', $dati) ? self::maxEntiValidato($dati) : $piano->max_enti;

        DB::transaction(function () use ($piano, $etichetta, $maxEnti, $dati) {
            $piano->forceFill([
                'etichetta' => $etichetta,
                'max_enti' => $maxEnti,
                'ordine' => (int) ($dati['ordine'] ?? $piano->ordine),
            ])->save();
        });

        app(CatalogoPiani::class)->dimentica();

        return $piano;
    }

    /**
     * Cambia il prezzo di listino e marca il piano da risincronizzare.
     *
     * ⚠️ **Non migra nessuno.** Su Stripe un Price è immutabile: la
     * sincronizzazione ne creerà uno nuovo e archivierà il vecchio, e le
     * subscription in essere continueranno a fatturare su quello vecchio. È la
     * decisione di prodotto — chi è già abbonato resta al suo — ed è la ragione
     * per cui `prezzi_piano` conserva lo storico.
     */
    public static function cambiaPrezzo(Piano $piano, int $importoCent): Piano
    {
        self::verificaPrezzo($importoCent);

        if ($piano->gratuito && $importoCent !== 0) {
            throw ValidationException::withMessages([
                'prezzo_mensile_cent' => 'Un piano gratuito non può avere un prezzo (ADR-002). Per venderlo si crea un piano nuovo: la gratuità non si ribalta.',
            ]);
        }

        DB::transaction(function () use ($piano, $importoCent) {
            $piano->forceFill([
                'prezzo_mensile_cent' => $importoCent,
                // Il piano torna «da sincronizzare»: finché non lo è, la pagina
                // mostra il locale e Stripe divergenti invece di far finta.
                'stripe_sincronizzato_at' => null,
            ])->save();
        });

        app(CatalogoPiani::class)->dimentica();

        return $piano;
    }

    /**
     * Archivia un piano: lo toglie dalle **offerte future** e basta.
     *
     * ⚠️ Non lo cancella e non lo toglie dal catalogo: `Piani::codici()` e
     * `Piani::esiste()` continuano a includerlo, o ogni account rimasto su
     * questo piano varrebbe 0 € nell'MRR.
     *
     * Il piano **predefinito** non si archivia: è quello con cui nasce ogni
     * account nuovo e quello a cui si torna dopo una disdetta
     * (`StripeWebhookController` → `Piani::predefinito()`).
     */
    public static function archivia(Piano $piano): Piano
    {
        if ($piano->codice === Piani::predefinito()) {
            throw ValidationException::withMessages([
                'attivo' => "«{$piano->codice}» è il piano predefinito (easylab.piani.predefinito): è quello con cui nasce ogni account nuovo e quello a cui torna chi disdice. Archiviarlo lascerebbe il webhook di disdetta a scrivere un piano non offribile.",
            ]);
        }

        DB::transaction(fn () => $piano->forceFill(['attivo' => false])->save());

        app(CatalogoPiani::class)->dimentica();

        return $piano;
    }

    public static function riattiva(Piano $piano): Piano
    {
        DB::transaction(fn () => $piano->forceFill(['attivo' => true])->save());

        app(CatalogoPiani::class)->dimentica();

        return $piano;
    }

    /**
     * Allinea il piano a Stripe. **Fuori** da ogni transazione: vedi il docblock
     * di classe.
     *
     * ⚠️ **Il fallimento non è mai silenzioso e non fa mai rollback della riga
     * locale.** Il dominio — tetto di Enti, etichetta, ordine — deve valere
     * anche se Stripe è irraggiungibile: quei numeri governano il provisioning,
     * non la fatturazione. Ciò che si scrive in caso di errore è
     * `stripe_ultimo_errore`, che la pagina mostra per esteso.
     */
    public static function sincronizza(Piano $piano): Piano
    {
        // Un piano gratuito non ha nulla su Stripe **per definizione** (ADR-002:
        // nessun customer, nessuna subscription). Non è un no-op di comodo: è la
        // definizione del Free, e crearne il Product produrrebbe un oggetto di
        // fatturazione che nessuno fatturerà mai.
        if ($piano->gratuito) {
            return $piano;
        }

        $porta = app(PortaListinoStripe::class);

        try {
            $productId = $porta->creaOAggiornaProdotto($piano);

            $piano->forceFill(['stripe_product_id' => $productId])->save();

            $corrente = $piano->prezzi()->where('corrente', true)->first();

            // 🔴 **Il secondo livello di idempotenza**, quello che sopravvive
            // alle 24 ore della chiave di Stripe: se importo e valuta non sono
            // cambiati, non si chiama Stripe affatto. Senza, un doppio invio a
            // distanza di un giorno creerebbe due Price gemelli.
            $daCreare = $corrente === null
                || $corrente->importo_cent !== (int) $piano->prezzo_mensile_cent
                || $corrente->valuta !== $piano->valuta;

            if ($daCreare) {
                $nuovo = $porta->creaPrezzo(
                    $piano,
                    (int) $piano->prezzo_mensile_cent,
                    (string) $piano->valuta,
                    'easylab_price_'.sha1($piano->codice.'|'.$piano->prezzo_mensile_cent.'|'.$piano->valuta),
                );

                DB::transaction(function () use ($piano, $corrente, $nuovo) {
                    $corrente?->forceFill(['corrente' => false])->save();

                    // La riga vecchia **resta**: è ciò su cui i clienti già
                    // abbonati continuano a fatturare, e la sola strada con cui
                    // `Piani::perPrice()` riconosce ancora il loro piano.
                    PrezzoPiano::query()->updateOrCreate(
                        ['stripe_price_id' => $nuovo->id],
                        [
                            'piano_id' => $piano->id,
                            'importo_cent' => $nuovo->importoCent,
                            'valuta' => $nuovo->valuta,
                            'corrente' => true,
                        ]
                    );
                });

                // L'archiviazione del price vecchio viene **dopo** la scrittura
                // locale, e fuori dalla transazione: se fallisse, il listino
                // locale è comunque corretto e su Stripe resta un price attivo
                // di troppo — molto meno grave di uno storico perso.
                if ($corrente !== null) {
                    $porta->archiviaPrezzo($corrente->stripe_price_id);
                }
            }

            $piano->forceFill([
                'stripe_sincronizzato_at' => now(),
                'stripe_ultimo_errore' => null,
            ])->save();
        } catch (Throwable $e) {
            $piano->forceFill([
                'stripe_sincronizzato_at' => null,
                'stripe_ultimo_errore' => $e->getMessage(),
            ])->save();
        }

        app(CatalogoPiani::class)->dimentica();

        return $piano->refresh();
    }

    /**
     * Aggancia un price **già esistente** su Stripe.
     *
     * È la via d'uscita per il caso che la migration di backfill mette in
     * conto: su Laravel Cloud la config è cachata in build e la migration gira
     * dopo, quindi se `STRIPE_PRICE_SAAS` mancasse al momento del deploy il
     * piano `saas` nascerebbe **senza** price id. Ricreare un price sarebbe la
     * risposta sbagliata: duplicherebbe il prodotto su Stripe e lascerebbe due
     * price attivi per lo stesso piano.
     *
     * ⚠️ **Si rifiuta se importo o valuta non combaciano.** Agganciare un price
     * da 99 € a un piano che il listino dichiara da 49 € significherebbe
     * fatturare una cifra che nessuna schermata mostra.
     */
    public static function agganciaPrezzo(Piano $piano, string $priceId): Piano
    {
        $priceId = trim($priceId);

        if ($priceId === '') {
            throw ValidationException::withMessages(['price_id' => 'Nessun price id da agganciare.']);
        }

        if ($piano->gratuito) {
            throw ValidationException::withMessages([
                'price_id' => "«{$piano->codice}» è un piano gratuito: per definizione non ha né customer né subscription su Stripe (ADR-002).",
            ]);
        }

        $altrove = PrezzoPiano::query()->where('stripe_price_id', $priceId)->first();

        if ($altrove !== null && $altrove->piano_id !== $piano->id) {
            throw ValidationException::withMessages([
                'price_id' => "Il price «{$priceId}» è già agganciato a un altro piano: due piani sullo stesso price renderebbero arbitrario il riallineamento del webhook.",
            ]);
        }

        $remoto = null;

        try {
            $remoto = app(PortaListinoStripe::class)->leggiPrezzo($priceId);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'price_id' => "Stripe non ha restituito il price «{$priceId}»: {$e->getMessage()}",
            ]);
        }

        if ($remoto === null) {
            throw ValidationException::withMessages([
                'price_id' => "Su Stripe non esiste nessun price «{$priceId}».",
            ]);
        }

        if ($remoto->importoCent !== (int) $piano->prezzo_mensile_cent || $remoto->valuta !== (string) $piano->valuta) {
            throw ValidationException::withMessages([
                'price_id' => sprintf(
                    'Il price «%s» vale %d %s, il piano dichiara %d %s: agganciarlo fatturerebbe una cifra che nessuna schermata mostra. Correggere prima il prezzo di listino, o agganciare un altro price.',
                    $priceId,
                    $remoto->importoCent,
                    mb_strtoupper($remoto->valuta),
                    (int) $piano->prezzo_mensile_cent,
                    mb_strtoupper((string) $piano->valuta),
                ),
            ]);
        }

        DB::transaction(function () use ($piano, $remoto) {
            $piano->prezzi()->where('corrente', true)->update(['corrente' => false]);

            PrezzoPiano::query()->updateOrCreate(
                ['stripe_price_id' => $remoto->id],
                [
                    'piano_id' => $piano->id,
                    'importo_cent' => $remoto->importoCent,
                    'valuta' => $remoto->valuta,
                    'corrente' => true,
                ]
            );
        });

        app(CatalogoPiani::class)->dimentica();

        return $piano->refresh();
    }

    private static function verificaEtichetta(string $etichetta): void
    {
        if ($etichetta === '') {
            throw ValidationException::withMessages([
                'etichetta' => "L'etichetta è ciò che il cliente legge in pagina: non può essere vuota.",
            ]);
        }
    }

    private static function verificaPrezzo(int $prezzo): void
    {
        if ($prezzo < 0) {
            throw ValidationException::withMessages([
                'prezzo_mensile_cent' => 'Il prezzo è in centesimi interi e non può essere negativo.',
            ]);
        }
    }

    /**
     * `null` = illimitato, e va distinto da «zero». Una stringa vuota che
     * arriva da un form è `null`: è così che si scrive «nessun tetto» in un
     * campo numerico.
     */
    private static function maxEntiValidato(array $dati): ?int
    {
        $grezzo = $dati['max_enti'] ?? null;

        if ($grezzo === null || $grezzo === '') {
            return null;
        }

        $max = (int) $grezzo;

        if ($max < 1) {
            throw ValidationException::withMessages([
                'max_enti' => 'Il tetto di Enti è almeno 1, oppure vuoto per «illimitato». Un piano da zero sedi non permetterebbe nemmeno il primo Ente.',
            ]);
        }

        return $max;
    }
}

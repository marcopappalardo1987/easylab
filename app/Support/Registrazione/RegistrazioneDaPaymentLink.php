<?php

namespace App\Support\Registrazione;

use App\Models\PrezzoPiano;
use App\Models\Registrazione;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 La riga `registrazioni` che nasce da un **pagamento su Payment Link**
 * (🔗 ADR-039, ADR-012 il provisioning, ADR-002 il rapporto commerciale).
 *
 * Il modulo pubblico raccoglie i dati **prima** di mandare a Stripe, e la riga
 * esiste già quando il pagamento arriva. Da un plink no: c'è solo una sessione
 * completata, e i dati sono dentro di essa. Questa classe li estrae, li valida e
 * scrive la riga — poi tutto il resto è la strada di sempre
 * (`CompletaRegistrazione`), col suo lock, la sua idempotenza, il suo audit.
 *
 * ## ⛔ Il piano NON viene mai dal payload
 *
 * Si risolve da **`session.payment_link`** — un `plink_...` che scrive Stripe —
 * cercandolo su `prezzi_piano`, cioè nel **nostro** database. È la stessa forma
 * di `Piani::perPrice()`, e la ragione è quella che `EsitoCheckout` porta già
 * scritta: i metadata viaggiano su un dominio di terzi, e chiunque abbia accesso
 * alla dashboard di Stripe li riscrive. Un piano letto dai metadata sarebbe un
 * Enterprise comprabile a 49 €.
 *
 * Un plink che non conosciamo è un caso **legittimo**, non un guasto: qualcuno
 * ne ha creato uno a mano dalla dashboard. Si risponde `null` e il chiamante
 * tace, esattamente come fa già col `registrazione_id` assente.
 *
 * ## ⛔ `forceFill()`, mai `create()` né `firstOrCreate()`
 *
 * `Registrazione` ha `$fillable = []` (vedi il suo docblock), quindi è
 * `totallyGuarded()`: un `create()` lancerebbe `MassAssignmentException` invece
 * di scrivere. Su questa strada un'eccezione non è un test rosso, è un **500 al
 * webhook** → Stripe ritenta per giorni → endpoint disabilitato → si perdono
 * anche i lockout per insoluto.
 *
 * ## La verifica della casella, e perché qui non serve
 *
 * L'Admin nasce **invitato**: `ProvisionaEnte` senza password manda un link
 * firmato alla casella raccolta da Stripe, e solo accettarlo valorizza
 * `email_verified_at`. La casella si conferma quindi *dopo*, ed è quel click a
 * confermarla. La guardia in `CompletaRegistrazione` è scritta apposta su
 * `password_hash !== null`, che qui è `null`: vedi il commento là, dove sta la
 * ragione per esteso.
 */
final class RegistrazioneDaPaymentLink
{
    /**
     * ⚠️ **Il tetto è NOSTRO e non quello di Stripe.** `registrazioni.email` è
     * `varchar(255)`, e Stripe accetta indirizzi fino a 512 caratteri: una email
     * più lunga passerebbe su SQLite — che le lunghezze non le impone — e
     * farebbe **esplodere Postgres**, cioè un 500 al webhook che in suite non si
     * vede. È la divergenza fra i due motori che il progetto ha già pagato.
     */
    private const LUNGHEZZA_MASSIMA = 255;

    /**
     * La riga per questa sessione, o `null` se il plink non è nostro.
     *
     * @param  array<string, mixed>  $sessione  L'oggetto `checkout.session` come arriva da Stripe
     *
     * @throws RegistrazioneRifiutata quando i dati raccolti non bastano a far nascere un account
     */
    public function sintetizza(array $sessione): ?Registrazione
    {
        $piano = self::pianoDelLink($sessione['payment_link'] ?? null);

        if ($piano === null) {
            return null;
        }

        $sessionId = is_string($sessione['id'] ?? null) ? $sessione['id'] : null;

        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        // L'idempotenza della **sintesi**, distinta da quella del
        // completamento: due consegne dello stesso evento non devono produrre
        // due righe. Quella del completamento (`completata_at`) sta un livello
        // più in là e protegge dal secondo *account*; questa protegge dalla
        // seconda *riga*, che l'indice unique su `stripe_session_id` rifiuterebbe
        // comunque — ma con un'eccezione, cioè con un 500.
        $gia = Registrazione::query()->where('stripe_session_id', $sessionId)->first();

        if ($gia !== null) {
            return $gia;
        }

        $dettagli = is_array($sessione['customer_details'] ?? null) ? $sessione['customer_details'] : [];

        // ⚠️ **Due posti per gli stessi due campi**, e si leggono entrambi:
        // Stripe li mette sia in `collected_information` sia in
        // `customer_details` (doc «Collect customer names»). Non è ridondanza
        // difensiva a caso — quei campi esistono solo dalla versione API
        // `2025-09-30.clover`, e **ogni endpoint webhook ha la propria versione,
        // fissata quando lo si è creato**: un endpoint più vecchio consegna un
        // payload che non li ha in nessuno dei due posti.
        $raccolti = is_array($sessione['collected_information'] ?? null) ? $sessione['collected_information'] : [];

        // ⚠️ `name` in coda anche qui, e non è una svista: su un payload di
        // versione vecchia i due campi nuovi non ci sono, e Stripe valorizza
        // comunque `name` con la ragione sociale (imposta `Customer.name` =
        // `business_name`). È l'unico posto in cui quel dato sopravvive.
        $ragioneSociale = self::testo($raccolti['business_name'] ?? null)
            ?? self::testo($dettagli['business_name'] ?? null)
            ?? self::testo($dettagli['name'] ?? null);

        // ⛔ **`customer_details.name` NON è il referente**, e leggerlo come tale
        // è stato il difetto del 5 Set 2026: con la raccolta business attiva
        // Stripe lo valorizza con la **ragione sociale** («set to the
        // `business_name` or `individual_name`, in that order»). Un tenant
        // sarebbe nato col referente chiamato come l'ente — sbagliato in modo
        // silenzioso, cioè il modo peggiore.
        //
        // Resta come ultima scelta perché su un payload di versione vecchia è
        // l'unico nome che arriva, e un referente approssimativo è meno grave di
        // un pagamento rifiutato dopo l'incasso.
        // ⛔ E **`name` non entra qui**: sarebbe la ragione sociale un'altra
        // volta, cioè un tenant col referente chiamato come l'ente.
        $referente = self::testo($raccolti['individual_name'] ?? null)
            ?? self::testo($dettagli['individual_name'] ?? null);

        $email = self::testo($dettagli['email'] ?? null);

        // ⚠️ Un solo messaggio per tutti e tre: dice **cosa** manca senza
        // ripetere i valori raccolti, che sono dati personali e finirebbero nel
        // registro di audit. Il testo per esteso resta nel log.
        // 🔴 **Solo due campi sono indispensabili**, e il referente non è fra
        // loro. Un account ha bisogno di un intestatario (la ragione sociale) e
        // di una casella a cui mandare l'invito: senza quelli non c'è niente da
        // creare e nessuno da avvisare. Il nome della persona è un'**etichetta**,
        // che l'Admin corregge dall'anagrafica al primo accesso.
        //
        // ⚠️ La distinzione conta perché il rifiuto avviene **dopo l'incasso**:
        // esigere un campo che si può ricavare significherebbe tenere i soldi e
        // non consegnare, per una ragione che il cliente non può né vedere né
        // correggere. Su un payload di versione API vecchia `individual_name`
        // non esiste affatto, e sarebbe stato il caso normale.
        $mancanti = array_keys(array_filter([
            'ragione sociale' => $ragioneSociale === null,
            'email' => $email === null,
        ]));

        if ($mancanti !== []) {
            throw new RegistrazioneRifiutata(
                "La sessione {$sessionId} è stata pagata ma non porta ".implode(', ', $mancanti).
                ': nessun account può nascere da essa. Il cliente va contattato e il tenant creato a mano '.
                '(easylab:provision-tenant), oppure il pagamento rimborsato.',
                RegistrazioneRifiutata::DATI_INSUFFICIENTI,
            );
        }

        // In transazione con l'unique su `stripe_session_id` a fare da rete: due
        // consegne simultanee arrivano entrambe qui dopo un `first()` a vuoto, e
        // la seconda perde sull'indice.
        // Il referente mancante vale la ragione sociale: è il nome che compare
        // sull'utente Admin, e «Laboratorio Aurora» è un'etichetta onesta finché
        // la persona non scrive la propria.
        $referente ??= $ragioneSociale;

        return DB::transaction(function () use ($sessionId, $piano, $ragioneSociale, $referente, $email) {
            $riga = new Registrazione;

            $riga->forceFill([
                'nome_ente' => $ragioneSociale,
                'nome_referente' => $referente,
                'email' => $email,
                // ⛔ Nessuna password: l'Admin nasce invitato. È anche ciò che
                // tiene fail-closed la guardia di `CompletaRegistrazione`.
                'password_hash' => null,
                'piano' => $piano,
                // La casella la conferma l'accettazione dell'invito, non noi.
                'email_verificata_at' => null,
                'stripe_session_id' => $sessionId,
            ])->save();

            return $riga;
        });
    }

    /**
     * Il codice del piano che quel Payment Link vende, letto dal **nostro**
     * database.
     *
     * ⚠️ Cerca su **tutte** le righe di `prezzi_piano`, correnti e storiche, per
     * la stessa ragione di `CatalogoPiani::codicePerPrice()`: un plink si spegne
     * quando il prezzo cambia, ma una sessione aperta un minuto prima si
     * completa dopo — e guardando la sola riga corrente quel pagamento
     * risulterebbe di nessun piano.
     */
    private static function pianoDelLink(mixed $plinkId): ?string
    {
        if (! is_string($plinkId) || $plinkId === '') {
            return null;
        }

        return PrezzoPiano::query()
            ->where('stripe_payment_link_id', $plinkId)
            ->with('piano')
            ->first()?->piano?->codice;
    }

    /** Il valore ripulito, o `null` se non è un testo utilizzabile. */
    private static function testo(mixed $valore): ?string
    {
        if (! is_string($valore)) {
            return null;
        }

        $valore = trim($valore);

        return $valore === '' || mb_strlen($valore) > self::LUNGHEZZA_MASSIMA ? null : $valore;
    }
}

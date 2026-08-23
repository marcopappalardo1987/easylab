<?php

namespace App\Support;

/**
 * 🔴 La **denylist unica** dei nomi di chiave il cui valore non esce mai dal
 * database (S6 — 🔗 `docs/Architettura/Error Tracker Interno (piano).md`,
 * Privacy §3 «Minimizzazione»).
 *
 * Nasce **estratta** da `App\Support\Audit\DettaglioAttivita`, che la teneva
 * come costante privata per la sola riga espansa del registro di audit, e nasce
 * **estesa**: dal blocco 4 dell'error tracker la stessa lista deve filtrare
 * anche `$request->all()`, cioè il corpo di una richiesta HTTP vera, dove
 * passano cose che il registro di audit non ha mai visto.
 *
 * ## Perché una classe e non una costante condivisa
 *
 * Perché i due consumatori non condividono soltanto l'elenco: condividono il
 * **modo di applicarlo** — match su porzione di chiave, senza distinzione di
 * maiuscole, e ricorsivo sugli annidamenti. Un elenco copiato in due posti
 * diverge alla prima aggiunta; due implementazioni della ricorsione divergono
 * anche prima.
 *
 * ## Le tre voci che l'estensione ha dovuto aggiungere, e perché
 *
 * 🔴 **`code` e `recovery_code`.** `resources/views/auth/two-factor-challenge.blade.php`
 * spedisce due campi che si chiamano esattamente così: il codice TOTP e — quello
 * che conta — il **codice di recupero 2FA**, che è una credenziale permanente e
 * monouso, non un numero che scade in trenta secondi. Con la lista di prima
 * (`password`, `token`, `secret`) un errore su quella POST avrebbe scritto il
 * codice di recupero **in chiaro** in `occorrenze_errore.input`, dietro un gate
 * che nessuno tranne il Developer può ispezionare. `recovery_code` è già coperta
 * da `code` per costruzione: resta elencata perché è la voce che dà la ragione
 * dell'altra, e un elenco che non nomina il caso che l'ha motivato invita a
 * potarlo.
 *
 * 🔴 **`signature` ed `expires`.** Verificato: `$request->all()` è **input +
 * query string**, quindi su una rotta firmata (i download da Backblaze, 🔗
 * ADR-026) porta dentro proprio la firma che rende la URL spendibile. Salvare il
 * solo `path()` invece del `fullUrl()` chiude la porta e lascia aperta la
 * finestra: le due chiavi rientrano dai parametri. Da cui la voce qui, e il test
 * che asserisce **sul valore** e non sulla presenza della chiave.
 *
 * **`two_factor` e `_token`.** La prima copre `two_factor_secret` e
 * `two_factor_recovery_codes` per nome proprio (le prende già `secret` e
 * `token`, ma non per la ragione giusta: le prenderebbe anche se un domani si
 * chiamassero altrimenti). `_token` è il CSRF, che sta su **ogni** POST del
 * progetto: non è un segreto di lunga vita, ma è un valore di sessione che non
 * ha nessuna ragione di essere conservato per novanta giorni.
 *
 * ## ⚠️ Il limite, che si dichiara e non si finge di chiudere
 *
 * **Questa lista guarda la CHIAVE, mai il valore.** Un dato personale — o un
 * segreto — battuto dentro un campo che si chiama `note`, `descrizione` o
 * `email` resta in chiaro, e nessuna aggiunta a questo elenco lo prenderebbe. È
 * lo stesso limite già dichiarato in `DettaglioAttivita` per `Login fallito` e
 * la sua `properties.email` (chi sbaglia campo ci scrive la password), ed è
 * dichiarato lì con la stessa conclusione: oscurare `email` svuoterebbe la riga
 * più utile del registro di sicurezza.
 *
 * La risposta non è un filtro più furbo — un riconoscitore di dati personali su
 * testo libero sbaglia in entrambi i versi — ma la **retention** e il
 * **perimetro**: la riga T8 del registro dei trattamenti dichiara le categorie
 * di dati che finiscono qui, i novanta giorni, e gli autorizzati (il solo
 * Developer, più la casella dell'alert). Si sceglie e si mette agli atti; non si
 * nasconde.
 */
final class ChiaviSensibili
{
    /**
     * Le **porzioni** di nome che rendono un valore non salvabile.
     *
     * Match su porzione e senza distinzione di maiuscole, che è ciò che rende
     * corta questa lista: `password_confirmation`, `api_token`,
     * `remember_token`, `client_secret`, `two_factor_recovery_codes`,
     * `X-Signature` cadono tutte qui senza doverle elencare.
     *
     * ⚠️ **E il match su porzione ha un rovescio da tenere d'occhio**: `code`
     * prende anche `barcode`, `postcode`, `qrcode`. Nel dominio di questo
     * progetto non c'è collisione — «codice» in italiano non contiene «code»
     * (c-o-d-i-c-e), quindi `codice_fiscale`, `codice_interno` e i loro simili
     * passano indenni — ma chi aggiungesse un domani un campo `barcode` allo
     * strumento lo vedrebbe sparire dal registro di audit senza spiegazione.
     * Sta scritto qui perché è l'unico posto in cui si andrebbe a cercare.
     *
     * @var list<string>
     */
    public const CHIAVI = [
        'password',
        'token',
        'secret',
        'code',
        'recovery_code',
        'two_factor',
        'signature',
        'expires',
        '_token',
    ];

    /**
     * La chiave nomina un segreto?
     *
     * ⚠️ **Chi la usa toglie la voce del tutto, chiave compresa**, e non la
     * sostituisce con un «(oscurato)»: il nome della chiave è già un indizio su
     * cosa quella riga custodisce, e una scatola i cui unici dati sono oscurati
     * dev'essere **vuota** — altrimenti si offre di aprirsi su «c'era qualcosa
     * che non ti mostro». È la regola che `DettaglioAttivita::vuoto()` esiste per
     * far rispettare, e vale identica sull'input di un'occorrenza d'errore.
     */
    public static function nomina(string $chiave): bool
    {
        $normalizzata = mb_strtolower($chiave);

        foreach (self::CHIAVI as $ago) {
            if (str_contains($normalizzata, $ago)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Toglie le chiavi sensibili a **ogni livello** di annidamento.
     *
     * ⚠️ La ricorsione non è prudenza generica: filtrare il solo primo livello
     * lascerebbe passare `payload[api_token]` e `utente[password]`, cioè la forma
     * normale di un corpo di richiesta un po' strutturato. Il valore di un
     * segreto non deve uscire dal database per **nessuna** strada — né in una
     * cella, né in un attributo del DOM, né dentro un JSON serializzato.
     *
     * Gli oggetti si attraversano come array: `properties` e `input` arrivano
     * dal JSON già decodificato, ma un `stdClass` ci finisce appena qualcuno
     * decodifica con `json_decode($x)` senza il secondo parametro.
     *
     * ⚠️ **Le chiavi numeriche non si guardano**: `$chiave` viene testata solo
     * se è una stringa. Un array di liste (`[0 => [...], 1 => [...]]`) va
     * attraversato, non filtrato — e `is_string()` è anche ciò che impedisce a
     * un indice `0` di essere confrontato con la denylist come `'0'`.
     */
    public static function ripulisci(mixed $valore): mixed
    {
        if (is_object($valore)) {
            $valore = (array) $valore;
        }

        if (! is_array($valore)) {
            return $valore;
        }

        $pulito = [];

        foreach ($valore as $chiave => $interno) {
            if (is_string($chiave) && self::nomina($chiave)) {
                continue;
            }

            $pulito[$chiave] = self::ripulisci($interno);
        }

        return $pulito;
    }
}

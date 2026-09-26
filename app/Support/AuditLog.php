<?php

namespace App\Support;

/**
 * Convenzione per l'audit di sicurezza (spatie/laravel-activitylog).
 * Tutte le azioni sensibili (impersonation, login/logout, 2FA e — dagli sprint
 * successivi — semaforo.force, lockout, accessi tecnici) vanno nel log dedicato
 * `audit`, così la vista Audit (S6) può filtrarle in modo netto dal log default.
 */
class AuditLog
{
    /** Nome del canale di log dedicato alle azioni di sicurezza. */
    public const NAME = 'audit';

    /**
     * Da quando l'attribuzione delle righe è affidabile durante un'impersonazione.
     *
     * Prima di questa data `causedBy()` scriveva l'**impersonato**: lab404
     * sostituisce l'utente della guard e `CauserResolver` legge quello, quindi un
     * gesto di EasyLab compiuto dentro l'impersonazione risulta del cliente. Da
     * qui in poi ogni riga scritta **dentro una richiesta** porta anche
     * `properties.impersonato_da`.
     *
     * ⚠️ È una **costante e non un dato derivato**, e non per pigrizia:
     * `min(created_at)` sulle righe che hanno la chiave darebbe la prima
     * scrittura *in impersonazione*, che può arrivare settimane dopo o non
     * arrivare mai — cioè una data più recente del vero, nella direzione che fa
     * diffidare di righe affidabili.
     *
     * Vale per **staging e sviluppo**, che hanno storico precedente. La
     * produzione non esiste ancora: quando nascerà, il suo registro sarà
     * interamente successivo a questa data.
     */
    public const ATTRIBUZIONE_AFFIDABILE_DA = '22 Ago 2026';

    /**
     * Verbi degli eventi CRUD tracciati dal trait `AuditsDomainWrites` (ADR-027).
     */
    public const VERBI = [
        'created' => 'Creazione',
        'updated' => 'Modifica',
        'deleted' => 'Eliminazione',
        'restored' => 'Ripristino',
    ];

    /**
     * Descrizione di un evento CRUD, in **forma nome-primo**: «Creazione
     * garanzia», non «Garanzia creata».
     *
     * Non è una preferenza stilistica. L'italiano concorda il participio col
     * genere — «garanzia creata» ma «intervento creato» — e un trait condiviso
     * o inventa un campo `genere` (rumore su ogni model) o prima o poi scrive
     * «Intervento creata». La forma nome-primo è invariante, ordinabile e
     * mappabile all'inverso dalla vista Audit di S6.
     *
     * Le `activity()` esplicite restano com'è («Semaforo forzato»): descrivono
     * un ATTO, non un evento CRUD, ed è giusto che si distinguano a colpo
     * d'occhio in una lista.
     */
    public static function descrizione(string $evento, string $entita): string
    {
        return (self::VERBI[$evento] ?? ucfirst($evento)).' '.$entita;
    }
}

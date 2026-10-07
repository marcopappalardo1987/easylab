<?php

namespace App\Support\Utenti;

use App\Models\User;
use App\Support\Rbac;

/**
 * Chi può conferire quale ruolo, in **un posto solo** (🔗 ADR-038).
 *
 * 🔴 È l'idioma già pagato da `SchedaStrumento::assegnabili()`: la stessa
 * definizione serve la **tendina** e la **validazione**, così le due non possono
 * divergere. Una whitelist scritta in Blade e un `in_array` scritto nell'azione
 * sono due whitelist, e a divergere ci mettono un bugfix.
 *
 * ## ⛔ Superadmin e Developer non sono conferibili da nessuna interfaccia
 *
 * Non «non compaiono nella tendina»: **sono rifiutati dal codice**. Un ruolo
 * assente da una `<select>` è a un `$wire.set()` di distanza, e questi due
 * portano `tenants.view_all` — cioè la lettura sulle righe di *tutti* i clienti
 * (🔗 ADR-037). Il Developer in più è la **chiave di riserva** della piattaforma
 * (🔗 ADR-016): non esiste alcun `Gate::before` da super-admin, quindi quel
 * ruolo è davvero l'ultima via di rientro. Si creano da console, che è dove
 * stanno le chiavi.
 *
 * ## Il secondo fattore è una conseguenza del ruolo, e va detta prima
 *
 * `Admin` è in `two_factor_required_roles`: chi lo riceve, al primo accesso,
 * finisce su `/settings/security` e **non può andare altrove** finché non
 * configura il secondo fattore. Non è un difetto — è 🔗 ADR-016 — ma è una cosa
 * che chi conferisce il ruolo deve sapere **mentre lo conferisce**, non
 * scoprirla dalla telefonata di chi non riesce a entrare. Per questo la regola
 * la legge da `Rbac` invece di ribatterla: `config/rbac.php` resta l'unica
 * fonte, e il giorno in cui quella lista cambiasse l'avviso cambierebbe con lei.
 */
final class RuoliAssegnabili
{
    /**
     * I ruoli che un'interfaccia del **cliente** può conferire (🔗 ADR-038).
     *
     * Sono le quattro figure del perimetro di un Ente: chi amministra, chi
     * risponde di un reparto (🔗 ADR-006), chi guarda e basta, e il tecnico
     * **interno** — la forma di 🔗 ADR-030 che è dipendente del laboratorio e
     * non di EasyLab.
     *
     * @return list<string>
     */
    public static function perCliente(): array
    {
        return ['Admin', User::DEPARTMENT_SCOPED_ROLE, User::TENANT_ROLE, User::TECNICO_ROLE];
    }

    /**
     * I ruoli che l'interfaccia di **piattaforma** può conferire.
     *
     * Le due figure che lavorano **per portafoglio** (`tecnico_cliente`), cioè
     * su più clienti senza appartenere a nessuno: il Tecnico (🔗 ADR-007/030) e,
     * dal 6 Ott 2026, il Gestore (🔗 ADR-046), che sulle stesse sedi può anche
     * scrivere. Superadmin e Developer restano di console.
     *
     * @return list<string>
     */
    public static function perPiattaforma(): array
    {
        return [User::TECNICO_ROLE, User::GESTORE_ROLE];
    }

    /**
     * Il ruolo è conferibile in quel contesto?
     *
     * 🔴 Da chiamare **nell'azione che scrive**, non solo dove si disegna la
     * tendina: le property di un componente Livewire arrivano dal browser, e
     * `set` + `call` è a un `$wire` di distanza.
     */
    public static function ammesso(string $ruolo, bool $piattaforma = false): bool
    {
        return in_array($ruolo, $piattaforma ? self::perPiattaforma() : self::perCliente(), true);
    }

    /**
     * Quella persona è **amministrabile** da quell'interfaccia?
     *
     * 🔴 È il rovescio di `ammesso()`, e senza di lui la guardia copriva metà
     * del problema. `ammesso()` guarda il ruolo di **destinazione**: impedisce
     * di *conferire* Superadmin o Developer. Ma cambiare ruolo e cestinare sono
     * anche gesti che **tolgono**, e lì il ruolo di destinazione è innocuo — un
     * `syncRoles(['Tenant'])` sul Developer passa `ammesso()` senza un
     * sussulto e gli porta via la riga di `model_has_roles`.
     *
     * ⛔ E il Developer è la **chiave di riserva** della piattaforma (🔗
     * ADR-016): non esiste alcun `Gate::before` da super-admin, quindi quel
     * ruolo dipende davvero dalla matrice a database. È la ragione per cui
     * `config/rbac.php` mette la sua **riga** in sola lettura nell'editor dei
     * permessi — ma la riga di `/piattaforma/ruoli` protegge le celle, non
     * l'assegnazione: da `/utenti` il ruolo si sfilava tutto insieme.
     *
     * *Raggiungibile davvero, e non in teoria: il Superadmin ha `tenant_id`
     * sull'Ente di piattaforma (🔗 ADR-018, `SuperadminSeeder`) e ha tutti e
     * quattro gli `utenti.*` — cioè si trova il Developer in elenco sulla
     * propria `/utenti`, con i bottoni accanto.*
     *
     * La regola è simmetrica e si legge in una riga: **ciò che questa
     * interfaccia non sa conferire, non lo sa nemmeno togliere.** Una persona
     * senza alcun ruolo resta amministrabile — è uno stato da correggere, non
     * un perimetro da proteggere.
     */
    public static function amministrabile(User $persona, bool $piattaforma = false): bool
    {
        $conferibili = $piattaforma ? self::perPiattaforma() : self::perCliente();

        return $persona->getRoleNames()->every(fn (string $ruolo) => in_array($ruolo, $conferibili, true));
    }

    /**
     * Conferire questo ruolo impone il secondo fattore?
     *
     * Si legge da `config/rbac.php` e non si ribatte qui: due copie della stessa
     * lista sono due liste, e questa serve a **dire il vero a chi conferisce**.
     */
    public static function imponeSecondoFattore(string $ruolo): bool
    {
        return in_array($ruolo, Rbac::twoFactorRequiredRoles(), true);
    }
}

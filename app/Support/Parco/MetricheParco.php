<?php

namespace App\Support\Parco;

use App\Enums\StatoSemaforo;
use App\Models\Strumento;

/**
 * I quattro numeri della dashboard per ruolo, in **sei statement costanti**
 * (S6 — dashboard Admin/Tenant).
 *
 * Esiste come classe e non come metodo del componente per la stessa ragione di
 * `MetrichePiattaforma`: l'unica strada per contare il parco dev'essere una, o
 * la seconda nascerà il giorno in cui qualcuno vorrà lo stesso numero altrove.
 *
 * ## Perché NON somiglia alla sua gemella di piattaforma
 *
 * 🔴 **Qui non c'è nessuna porta e nessun bypass, ed è la differenza che conta.**
 * `MetrichePiattaforma` passa da `VistaPiattaforma`, che toglie gli scope per
 * nome e chiede `tenants.view_all`. Questa classe parte da `Strumento::query()`
 * **con tutti i global scope addosso**, e quindi è già corretta per ciascuno dei
 * ruoli che atterrano sulla dashboard, senza che nessuno debba ricordarsene:
 *
 *   - Admin e Tenant vedono il proprio Ente (`TenantScope`);
 *   - il Responsabile Reparto il proprio sotto-albero (`DepartmentScope`), e
 *     senza nodi assegnati non vede nulla — fail-safe, non zero per caso;
 *   - il Tecnico portafoglio ∪ assegnazione (`AccessoTecnico`, ADR-007/030);
 *   - un utente autenticato **senza tenant** conta zero e non tutto, che è il
 *     fail-closed di ADR-018.
 *
 * ⚠️ Chi un giorno vorrà gli stessi conteggi **cross-tenant** non deve
 * «uniformare» le due classi passando questa per `VistaPiattaforma`: le
 * sottoquery di `Strumento::fontiArancione()` restano scopate, quindi le
 * macchine degli altri Enti uscirebbero verdi — e la somma tornerebbe lo stesso.
 * Un meta-test lo vieta per nome (`VistaPiattaformaTest`).
 *
 * ## Perché sei statement e non uno
 *
 * La versione a statement singolo — un `SUM(CASE WHEN …)` con le tre condizioni
 * — richiederebbe di **renderizzare l'SQL a mano** con `toSql()` e ordinare i
 * binding a mano, cioè una **terza forma** della stessa regola dopo il filtro e
 * l'ordinamento: esattamente ciò che il blocco precedente esisteva per
 * eliminare. Quattro `count()` che passano letteralmente per gli scope
 * dell'elenco costano tre round-trip in più e non possono divergere da esso.
 *
 * La proprietà da difendere non è «un solo statement» ma **«costante rispetto al
 * numero di righe»**, e sei lo è: le soglie si leggono una volta e si passano al
 * conteggio degli obsoleti invece di essere rilette da lui.
 *
 * ⚠️ **«Sei» vale per l'Admin, il Tenant e il Tecnico — non per il Responsabile
 * Reparto, e va detto invece di lasciarlo scoprire in produzione.** Per lui sono
 * **ventotto**, misurate: ogni query scopata passa da `DepartmentScope`, che
 * chiama `AccessibleNodes::forCurrentUser()` — **non memoizzata** — e quella
 * rilegge il pivot `responsabile_unita` più **l'intero albero dell'Ente**. Undici
 * volte, sulla pagina che quel ruolo apre a ogni login.
 *
 * Non è un difetto di questa classe: è il costo del livello 2 del Global Scope,
 * e vale già su ogni pagina scopata. Qui si somma perché i conteggi sono sei.
 * Il rimedio è memoizzare `AccessibleNodes::forUser()` per id-utente, ma un
 * risolutore di **autorizzazioni** vuole il proprio invalidamento — login,
 * `SwitcherEnte::passa()`, impersonazione — e non entra di straforo in un
 * commit di dashboard: è una riga del passo performance di S7. Nel frattempo il
 * numero è congelato da un test, perché una regressione si veda.
 */
final class MetricheParco
{
    public static function riepilogo(): RiepilogoParco
    {
        // Due statement, una volta sola: gli Enti presenti fra le righe
        // visibili e la loro soglia di obsolescenza.
        $soglie = Strumento::soglieDegliEntiVisibili();

        $conta = fn (StatoSemaforo $stato): int => Strumento::query()->conStato($stato)->count();

        return new RiepilogoParco(
            verdi: $conta(StatoSemaforo::Verde),
            arancioni: $conta(StatoSemaforo::Arancione),
            rossi: $conta(StatoSemaforo::Rosso),
            obsoleti: Strumento::query()->obsoleti($soglie)->count(),
            // Le soglie DISTINTE, ordinate: alla vista serve sapere se ce n'è
            // una sola — cioè se la frase «oltre N anni» è vera per tutti.
            soglie: collect($soglie)->unique()->sort()->values()->all(),
        );
    }
}

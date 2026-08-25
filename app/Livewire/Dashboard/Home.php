<?php

namespace App\Livewire\Dashboard;

use App\Support\Parco\MetricheParco;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * La pagina di atterraggio, per **tutti** i ruoli (S6 — Wireframe §1,
 * 🔗 Funzionalità per Ruolo §3 e §4).
 *
 * ## Una sola pagina, e non una per ruolo
 *
 * La roadmap ha due caselle — «Dashboard Admin» e «Dashboard Tenant» — ma qui
 * atterrano **sei** ruoli, non due: `config/fortify.php` manda qui dopo il
 * login, e ci mandano anche `SwitcherEnte::passa()` e `FugaDaLockout`. Due
 * componenti richiederebbero un instradamento **per nome di ruolo**, che questo
 * progetto rifiuta ovunque (`/campo` è gatata `interventi.view` e non
 * «Tecnico», `/piattaforma` è `tenants.view_all` e non «Superadmin») e che
 * diventerebbe falso al primo click sull'editor permessi di S6.
 *
 * Ciò che distingue §3 da §4 è già espresso altrove e non va ri-deciso qui:
 * `TenantScope` e `DepartmentScope` dicono *quali* macchine, `AccessoTecnico`
 * l'unione portafoglio/assegnazione, `GaranziaRicambioPolicy` il dettaglio che
 * il Tenant non vede. Una seconda vista dovrebbe rispondere di nuovo a domande
 * a cui il dominio risponde già — ed è la forma della duplicazione che
 * `Campo\Home` ha rifiutato («due rese della stessa macchina sono due occasioni
 * di sbagliare i permessi») e che `/q/{token}` evita reindirizzando tutti su
 * `strumenti.show` senza diramare per ruolo.
 *
 * ## Che cosa questa pagina AGGIUNGE, e cosa si limita a indirizzare
 *
 * Aggiunge **una cosa sola**: i quattro conteggi del parco. `ElencoStrumenti`
 * non mostra mai una ripartizione — ha il totale del paginatore, non le fette —
 * quindi «quante macchine sono messe male» oggi non ha risposta da nessuna
 * parte.
 *
 * Tutto il resto lo **indirizza**: ogni riquadro è un link a `/strumenti` con
 * il proprio filtro in query string, e cliccando «Azione richiesta 18» si deve
 * atterrare su **esattamente 18 righe**. È ciò che rende «conta» ed «elenca» la
 * stessa regola *per chi guarda*, e non solo nel codice: un test segue il link
 * e confronta il numero del riquadro col totale del paginatore.
 *
 * Restano fuori, e ciascuna per la sua ragione: la tabella del Wireframe §1
 * (*è* `/strumenti`, costruito in S2/S3 **dopo** quel disegno di S0); una lista
 * «le più urgenti» (terza resa della riga strumento, e quarto posto in cui la
 * degradazione privacy della colonna scadenza può divergere); un blocco
 * notifiche (la campanella è in top bar su ogni pagina); «i miei interventi»
 * (c'è `/campo`); e le card che ripetono una voce di sidebar senza portare un
 * dato — quella è decorazione, non indirizzamento, ed è la stessa forma della
 * card «Prossimamente» tolta il 21 Ago 2026.
 *
 * ## La rotta non ha `can:`, e il permesso si chiede blocco per blocco
 *
 * È l'unica pagina che ogni utente autenticato deve poter aprire. Il gate vive
 * quindi **dentro**, area per area, come impone la Policy di Code Review per le
 * «viste che compongono più aree (Panoramica, dashboard S6)» e come ripete
 * Schema Ruoli §6: un solo `@can` in testa mostrerebbe a chi entra tutto ciò
 * che la vista sa.
 *
 * ⚠️ **Vincolo da conoscere prima di aggiungere la prima azione.** Questa
 * postura regge finché il componente **non ha azioni**: un'azione con
 * `skipRender()` non arriva mai a `render()`, quindi la guardia che sta lì
 * dentro non la vedrebbe, e non c'è un `can:` di rotta a raccoglierla. Chi
 * aggiunge un pulsante qui deve portarsi dietro il proprio `Gate::authorize()`
 * in testa all'azione — la lezione già pagata dalle leve della cabina.
 *
 * ## Perché la guardia è DOPPIA
 *
 * `Gate::allows()` qui decide se **calcolare**, `@can` nel template se
 * **mostrare**. Con il solo `@can`, chi non ha `strumenti.view` pagherebbe
 * comunque quattro aggregati e i numeri esisterebbero in memoria: la differenza
 * si misura in statement, e un test la misura. Il caso non è teorico —
 * `strumenti.view` **non è nel set bloccato**, quindi `/piattaforma/ruoli` può
 * revocarlo a runtime.
 */
#[Layout('components.layouts.app')]
#[Title('Dashboard — Easy Lab')]
class Home extends Component
{
    public function render(): View
    {
        $utente = auth()->user();

        return view('livewire.dashboard.home', [
            // `null` e non un riepilogo vuoto: la vista deve poter distinguere
            // «non ti compete» da «non hai macchine», che sono due frasi diverse.
            'parco' => Gate::allows('strumenti.view') ? MetricheParco::riepilogo() : null,

            // ⚠️ **Il perimetro si NOMINA.** Senza, il Superadmin legge un totale
            // qui e un altro su `/piattaforma` — entrambi corretti, uno per il
            // proprio Ente e uno per tutti i clienti — e il primo screenshot in
            // riunione fa il danno che `x-ui.stat-tile` descrive nel proprio
            // docblock. Chi non ha un Ente (Tecnico esterno) legge l'altra
            // frase, perché «le macchine di —» non sarebbe una frase.
            'perimetro' => $utente?->ente?->nome !== null
                ? 'Lo stato delle macchine di '.$utente->ente->nome.'.'
                : 'Lo stato delle macchine che segui.',
        ]);
    }
}

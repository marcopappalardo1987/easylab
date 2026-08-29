<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Piattaforma\ParcoClienti;
use App\Support\Piattaforma\Perimetro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Lab404\Impersonate\Services\ImpersonateManager;

/**
 * Impersona un membro e atterra **sulla scheda della macchina** che si stava
 * guardando nel Parco clienti (🔗 ADR-037, ADR-018).
 *
 * ## Perché esiste, invece della rotta del pacchetto
 *
 * `Route::impersonate()` di lab404 rimanda a una destinazione **fissa**, presa
 * dalla config: si finiva in dashboard, e da lì bisognava ritrovare a mano la
 * macchina che si era appena vista in elenco. Chiesto da Marco il 29 Ago 2026:
 * il tasto del parco serve a intervenire in fretta, e un rimbalzo in dashboard
 * gli toglie proprio quello.
 *
 * ## Le quattro guardie sono le STESSE del pacchetto, riscritte qui
 *
 * Non è duplicazione per pigrizia: questa rotta è un secondo ingresso
 * all'impersonazione, e un secondo ingresso con guardie diverse è il modo in
 * cui una regola si aggira senza accorgersene. Sono, nell'ordine del
 * pacchetto: non sé stessi, non già impersonando, `canImpersonate()` su chi
 * chiede, `canBeImpersonated()` sul bersaglio — che è ciò che protegge il
 * Developer (ADR-018).
 *
 * In più, e non nel pacchetto: `tenants.view_all`, perché questa porta si apre
 * **dal Parco** e non deve esistere per chi il parco non può vederlo.
 *
 * ## Perché la macchina si verifica PRIMA di impersonare
 *
 * Dopo l'impersonazione lo scope è quello dell'impersonato: una macchina di
 * un'altra sede sarebbe semplicemente invisibile, e si atterrerebbe su un 404
 * dopo aver già cambiato identità — il modo peggiore di negare, perché lascia
 * l'operatore dentro un contesto che non ha chiesto e senza dirgli perché.
 *
 * Quindi si controlla prima, **dalla porta del parco** (che ha il proprio gate
 * e i propri scope tolti per nome), e se la macchina non sarà visibile si
 * impersona lo stesso ma si atterra in dashboard, dicendolo. La ragione più
 * comune è legittima e va spiegata invece che nascosta: un account con più
 * sedi, e il membro scelto sta in una sede diversa da quella della macchina.
 */
class ImpersonaVersoStrumento extends Controller
{
    public function __invoke(int $utente, int $strumento): RedirectResponse
    {
        // ⛔ **Id nudi e NON route-model binding**, ed è la trappola in cui sono
        // caduto scrivendo questa classe: il binding risolve col `TenantScope`
        // addosso, cioè col tenant di CHI GUARDA — che nel parco non è mai
        // quello della macchina. Ogni riga dava 404 prima ancora di entrare
        // qui, e il 404 sarebbe stato indistinguibile da «macchina inesistente».
        // Le due letture si fanno quindi a mano, ciascuna dalla porta giusta.
        Gate::authorize(ParcoClienti::PERMESSO);

        $manager = app(ImpersonateManager::class);
        $io = Auth::user();

        abort_if($io === null, 403);
        abort_if($manager->isImpersonating(), 403);

        // `User` non porta il TenantScope (è l'identità di auth, esente per
        // dichiarazione in `NON_TENANT_MODELS`), quindi qui la query nuda è la
        // forma corretta e non un bypass.
        $bersaglio = User::query()->whereKey($utente)->first();

        abort_if($bersaglio === null, 404);
        abort_if($bersaglio->getKey() === $io->getKey(), 403);
        abort_unless($io->canImpersonate(), 403);
        abort_unless($bersaglio->canBeImpersonated(), 403);

        // ⚠️ La macchina si rilegge dalla PORTA e non dal binding: il binding
        // arriva scopato al tenant di chi guarda, che nel parco non è quello
        // della macchina. La porta è l'unico posto in cui questa lettura è
        // legittima, e ha il proprio gate.
        $visibile = ParcoClienti::strumenti(Perimetro::tutti())
            ->whereKey($strumento)
            ->where('strumenti.tenant_id', $bersaglio->tenant_id)
            ->exists();

        $manager->take($io, $bersaglio);

        return $visibile
            ? redirect()->route('strumenti.show', $strumento)
            : redirect()->route('dashboard')->with(
                'status',
                'Sei entrato come '.$bersaglio->name.', ma quella macchina è di un\'altra sede: '
                .'raggiungila dallo switcher in alto.'
            );
    }
}

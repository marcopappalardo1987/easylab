<?php

use App\Livewire\Strumenti\SchedaStrumento;
use App\Models\Strumento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Monta la scheda strumento come utente dato. Vive qui e non in un singolo file
 * di test perché la usano più suite (azioni interventi, forzatura semaforo).
 */
function scheda(User $user, Strumento $strumento): Testable
{
    return Livewire\Livewire::actingAs($user)->test(SchedaStrumento::class, ['strumento' => $strumento]);
}

/**
 * Estrae il primo `wire:snapshot` dall'HTML, come fa il vendor stesso: serve a
 * costruire un POST **reale** a `/livewire/update`, che è l'unica strada per
 * provare i middleware persistenti — `Livewire::test()` li disabilita.
 *
 * Vive qui e non nel file che l'ha introdotta (`LockoutEnforcementTest`) perché
 * la usa anche `AccessoPiattaformaTest`: finché stava là, quel file eseguito da
 * solo con `--filter` andava in **fatal per funzione non definita**. I file di
 * test si caricano solo se selezionati, quindi una funzione condivisa fra due
 * suite non può abitare in una delle due.
 */
function snapshotDa(string $html, ?string $componente = null): string
{
    // ⚠️ **Senza `$componente` si prende il PRIMO snapshot della pagina, che
    // quasi mai è quello che interessa.** Misurato il 23 Ago 2026 su
    // `/piattaforma/errori`: l'ordine è `tenancy.switcher-ente`,
    // `notifiche.campanella`, `piattaforma.errori` — cioè i test che credevano
    // di rinfrescare la pagina gatata stavano rinfrescando lo **switcher di
    // ente**, montato dal layout su ogni schermata.
    //
    // Il test non era del tutto vuoto — Livewire rimatcha sul `memo.path` e
    // riapplica i middleware persistenti, che è il meccanismo vero — ma non
    // toccava il componente, quindi sarebbe rimasto verde qualunque cosa fosse
    // successa alle sue azioni. Chi prova il gate di una pagina passi il nome.
    if ($componente !== null) {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $trovati);

        foreach ($trovati[1] as $grezzo) {
            $decodificato = html_entity_decode($grezzo, ENT_QUOTES);

            if ((json_decode($decodificato, true)['memo']['name'] ?? null) === $componente) {
                return $decodificato;
            }
        }

        return '';
    }

    $grezzo = str($html)->betweenFirst('wire:snapshot="', '"')->toString();

    return html_entity_decode($grezzo, ENT_QUOTES);
}

/**
 * Chi entra nelle pagine di piattaforma e chi no, come **dataset condivisi**.
 *
 * ⚠️ Vivono qui e non in `AccessoPiattaformaTest` per la stessa ragione — e con
 * la stessa cicatrice — di `snapshotDa()` qui sopra, un gradino più in là: là
 * era una **funzione** condivisa fra due suite, qui sono **costanti**. I file di
 * test si caricano solo se selezionati, e comunque in ordine alfabetico *prima*
 * che qualunque test giri: una costante definita in `AccessoPiattaformaTest` non
 * esiste ancora quando Pest risolve i `->with()` di `AccessoEditorRuoliTest`, e
 * non esiste affatto se si lancia il solo `AccessoRegistroAuditTest`.
 *
 * Il sintomo non è un errore leggibile: è `Pest\Exceptions\DatasetMissing`,
 * cioè la stessa classe di guasto della trappola dei closure nei dataset —
 * **test che non girano**. Verificato il 23 Ago 2026:
 * `php artisan test tests/Feature/Piattaforma/AccessoRegistroAuditTest.php`
 * falliva così, e nessuno se n'era accorto perché la suite si lancia intera.
 *
 * Gli elenchi restano **costanti e non closure** (i closure si risolvono prima
 * del boot di Laravel, quindi `config('rbac.roles')` sarebbe vuoto e il dataset
 * nascerebbe vuoto), e un test per suite li tiene onesti contro la matrice RBAC:
 * elencare a mano senza quella rete lascia scoperto il Developer per omissione.
 */
const RUOLI_SENZA_PIATTAFORMA = ['Admin', 'Responsabile Reparto', 'Tenant', 'Tecnico'];
const RUOLI_CON_PIATTAFORMA = ['Developer', 'Superadmin'];

/** Un utente del ruolo dato, con 2FA già confermata (Admin e i due di piattaforma la richiedono). */
function utenteConRuolo(string $ruolo): User
{
    $utente = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $utente->assignRole($ruolo);

    return $utente->fresh();
}

/**
 * Il `<tr>` di una riga-permesso dell'editor ruoli, estratto per la sua `wire:key`.
 *
 * ⚠️ Si estrae il **blocco** invece di fare `assertSee` sull'HTML intero, e la
 * ragione ha una cicatrice recente: in questo stesso lavoro un
 * `assertSee('Clienti')` sulla pagina intera era verde *anche con la voce di
 * menù rimossa*, perché quella parola compare altrove. Un'asserzione che non può
 * fallire è peggio di nessuna asserzione, perché occupa il posto di quella vera.
 *
 * ⚠️ **Vive qui e non in `GrigliaRuoliTest`, che l'ha introdotta**, per la
 * ragione già scritta sopra per `snapshotDa()`: i file di test si caricano solo
 * se selezionati, quindi una funzione condivisa fra due suite non può abitare in
 * una delle due — `RiconciliazioneRuoliTest` eseguito da solo andrebbe in fatal
 * per funzione non definita.
 */
function rigaDelPermesso(string $html, string $permesso): string
{
    preg_match('/<tr wire:key="permesso-'.preg_quote($permesso, '/').'".*?<\/tr>/s', $html, $blocco);

    return $blocco[0] ?? '';
}

/** I permessi che un ruolo ha **a database**, letti senza passare dal registrar. */
function permessiDiRuolo(string $ruolo): array
{
    return Role::findByName($ruolo, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

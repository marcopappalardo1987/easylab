<?php

namespace Database\Seeders;

use App\Support\AuditLog;
use App\Support\Rbac;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default RBAC (bootstrap/reset) da docs/Architettura/Schema Ruoli e Permessi.md.
 * Idempotente: crea i permessi del catalogo, i 6 ruoli e applica la matrice §5.
 * Dopo il primo seeding la fonte di verità è il DB (ADR-016 §7); questo seeder
 * resta come reset ai default.
 *
 * ## 🔴 Da S6 questo comando è un'**arma carica**, e lo dice
 *
 * `syncPermissions()` **detacha tutto e riattacca** dalla config. Finché la
 * matrice si modificava solo in codice, rilanciarlo era innocuo — config e
 * database non potevano divergere se non per una dimenticanza. Da quando esiste
 * `/piattaforma/ruoli` la matrice si modifica **a runtime**, e allora questo
 * comando non fa deriva: **distrugge in silenzio ogni personalizzazione** e
 * riporta i sei ruoli ai default.
 *
 * ⚠️ E la fonte del guaio è un'istruzione **corretta**: `CLAUDE.md` ordina di
 * riseminare dopo ogni modifica a `config/rbac.php`. Eseguita alla lettera,
 * quell'istruzione cancella la matrice di runtime. La semantica **non cambia** —
 * un reset che non resetta sarebbe peggio, ed è ciò che ADR-016 §7 gli chiede di
 * essere — quindi la mossa è un'altra: **rendere impossibile eseguirlo alla
 * cieca**. Il diff si stampa *prima* di sincronizzare, e ciò che il reset
 * distrugge finisce nel registro di audit.
 *
 * ## Perché stampa e non chiede
 *
 * 🔴 **Nessun `confirm()`, e non è una scelta di comodità.** Un prompt qui
 * romperebbe gli **91 classi di test** che seminano: su un database appena creato
 * i ruoli non esistono, quindi `Role::firstOrCreate()` li crea vuoti e il diff
 * rispetto alla config non è vuoto — è **massimo** (219 concessioni). E il
 * guasto non sarebbe nemmeno un appendimento su cui si arriva col timeout:
 * `$this->seed()` passa da `$this->artisan()`, che aggiunge `--no-interaction`
 * e **mocka** `OutputStyle`, quindi la suite esplode con un errore di Mockery.
 * `warn()` invece passa. Se un giorno lo si volesse davvero, la guardia che
 * regge **non è il diff vuoto** ma `$this->command->getOutput()->isInteractive()`,
 * cioè «c'è un umano davanti» — e resterebbe comunque un prompt su un percorso
 * che 91 classi di test attraversano, per aggiungere poco a un diff già leggibile.
 *
 * ## Cosa conta come diff, e perché il bootstrap non conta
 *
 * ⚠️ **Un ruolo appena creato non ha personalizzazioni da distruggere**, e senza
 * questa distinzione l'intera meccanica si ritorcerebbe: il diff di un database
 * vuoto è *massimo*, quindi «stampa e traccia quando il diff non è vuoto»
 * significherebbe stampare 219 righe e scrivere una riga di audit **a ogni
 * `RefreshDatabase` della suite** — cioè inquinare `activity_log` in ogni test e
 * far cadere gli `assertSee` di chi conta le righe del registro.
 *
 * La condizione giusta non è «il diff è non vuoto» ma «**c'era qualcosa da
 * distruggere**»: si guardano solo i ruoli che esistevano *prima* di questa
 * esecuzione (`wasRecentlyCreated === false`). Sul bootstrap il seeder resta
 * muto, come è sempre stato; su un reset dice esattamente cosa sta portando via.
 *
 * 🔗 ADR-016 §7 (il seeder come reset ai default), `App\Support\Rbac\MatriceRuoli`
 * (l'altra metà: la matrice modificata a runtime), `/piattaforma/ruoli` (dove il
 * diff si legge **prima** di lanciare questo comando).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Rbac::permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        /** @var array<string, array{concessi: list<string>, revocati: list<string>}> */
        $diff = [];
        $daSincronizzare = [];

        foreach (Rbac::roleNames() as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $default = Rbac::permissionsForRole($roleName);

            // ⚠️ Il diff si calcola **prima** del `syncPermissions()` di questo
            // stesso giro: dopo, ciò che il reset ha portato via non esiste più
            // da nessuna parte se non in questa variabile.
            //
            // Lo stato attuale si legge dal **pivot** e non da
            // `$role->permissions` né da `hasPermissionTo()`: quelli passano dal
            // registrar, cioè da una cache che questo comando esiste anche per
            // riparare.
            $cambi = $role->wasRecentlyCreated
                ? null
                : self::cambiamenti($role->permissions()->pluck('name')->all(), $default);

            if ($cambi !== null) {
                $diff[$roleName] = $cambi;
            }

            $daSincronizzare[] = [$role, $default];
        }

        // ⚠️ **Due passate, e la prima non tocca niente.** La prima stesura
        // sincronizzava dentro il ciclo e stampava dopo: il diff era *calcolato*
        // prima, ma l'operatore lo leggeva a distruzione già avvenuta, e un
        // guasto a metà strada (una FK, una connessione che cade) lasciava
        // metà matrice riportata ai default e **zero righe di audit** — cioè il
        // danno fatto e nessuna traccia di cosa fosse stato portato via.
        // Verificato forzando un'eccezione dopo il terzo ruolo.
        //
        // Con due passate, «il comando stampa ciò che *sta per* portare via»
        // torna a essere vero alla lettera — che è ciò che CLAUDE.md, l'ADR e
        // la pagina promettono tutti e tre.
        $this->stampa($diff);
        $this->traccia($diff);

        foreach ($daSincronizzare as [$role, $default]) {
            $role->syncPermissions($default);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Cosa il reset farebbe a un ruolo, o `null` se non lo tocca.
     *
     * Il verso è quello del **gesto del reset**, non quello del confronto: `+`
     * sono i permessi che il reset **concede** (la config li vuole, il database
     * non li aveva) e `-` quelli che **revoca** (il database li aveva, la config
     * non li vuole). Detto dall'altro lato sarebbe la lettura opposta di ogni
     * riga, e chi legge un diff prima di lanciare un comando distruttivo non
     * deve dover indovinare da che parte si guarda.
     *
     * @param  list<string>  $adesso
     * @param  list<string>  $default
     * @return array{concessi: list<string>, revocati: list<string>}|null
     */
    private static function cambiamenti(array $adesso, array $default): ?array
    {
        $concessi = array_values(array_diff($default, $adesso));
        $revocati = array_values(array_diff($adesso, $default));

        if ($concessi === [] && $revocati === []) {
            return null;
        }

        return ['concessi' => $concessi, 'revocati' => $revocati];
    }

    /**
     * Il diff, a schermo, prima che la sincronizzazione lo renda irrecuperabile.
     *
     * `warn()` e non `info()`: non è il resoconto di un'operazione riuscita, è
     * l'elenco di ciò che è stato **portato via** a una piattaforma viva.
     *
     * `$this->command` è `null` quando il seeder viene istanziato a mano invece
     * che da `db:seed` — non c'è nessun output a cui parlare, e non è un errore.
     *
     * @param  array<string, array{concessi: list<string>, revocati: list<string>}>  $diff
     */
    private function stampa(array $diff): void
    {
        if ($diff === []) {
            return;
        }

        $this->command?->warn('⚠️  La matrice a database era diversa da config/rbac.php: il reset ha riportato i ruoli ai default.');
        $this->command?->warn('   Le personalizzazioni fatte da /piattaforma/ruoli sono state cancellate. Il registro di audit ne conserva la traccia.');

        foreach ($diff as $ruolo => $cambi) {
            $this->command?->warn(sprintf(
                '   %s — %d concessi dal reset, %d revocati dal reset:',
                $ruolo,
                count($cambi['concessi']),
                count($cambi['revocati'])
            ));

            foreach ($cambi['concessi'] as $permesso) {
                $this->command?->warn("     + {$permesso}");
            }

            foreach ($cambi['revocati'] as $permesso) {
                $this->command?->warn("     - {$permesso}");
            }
        }
    }

    /**
     * La riga nel registro, che chiude l'unico punto cieco di questa feature.
     *
     * Il recupero d'emergenza da una matrice rotta è `db:seed --class=…` seguito
     * da `permission:cache-reset`: senza questa riga sarebbe **l'unico gesto
     * della feature invisibile nel registro** — cioè si potrebbe riportare
     * indietro chi-può-cosa per tutti i clienti senza che nessuno ricostruisca
     * chi l'ha deciso, che è precisamente ciò che ADR-016 chiede di impedire.
     *
     * ⚠️ **Canale `audit` e non `default`**: `VistaPiattaforma::audit()` ha il
     * pavimento `where('log_name', AuditLog::NAME)` **dentro la porta**, quindi
     * una riga scritta altrove esisterebbe a database e non comparirebbe mai nel
     * registro.
     *
     * ⚠️ **Senza causer, esplicitamente.** `causedByAnonymous()` e non
     * l'omissione: `ActivityLogger` risolve il causer dalla guard alla prima
     * chiamata del builder, quindi un seeder lanciato da dentro una richiesta
     * autenticata (o da un test che ha già fatto `actingAs`) attribuirebbe a una
     * persona un gesto che è del **comando**. La riga nasce così nella
     * scorciatoia che il registro ha già — «solo azioni senza utente
     * autenticato», dove vivono console, coda e webhook — che è esattamente dove
     * un operatore la cercherebbe.
     *
     * ⚠️ Niente `->event()`: la riga è un **atto** e non il diff delle colonne di
     * un model, quindi `event` resta NULL e la riga è raggiungibile dal filtro
     * «atti». E niente soggetto: il gesto non è su *un* ruolo, è sulla matrice
     * intera.
     *
     * Le `properties` sono **stringhe piatte** e non un array annidato per ruolo:
     * `DettaglioAttivita` rende gli array come JSON pretty-printed, cioè «JSON
     * grezzo al posto dell'unica informazione che l'espansione esiste per dare»
     * — il difetto che quella classe esiste per evitare.
     *
     * @param  array<string, array{concessi: list<string>, revocati: list<string>}>  $diff
     */
    private function traccia(array $diff): void
    {
        if ($diff === []) {
            return;
        }

        $elenco = function (string $verso) use ($diff): string {
            $righe = [];

            foreach ($diff as $ruolo => $cambi) {
                if ($cambi[$verso] !== []) {
                    $righe[] = $ruolo.': '.implode(', ', $cambi[$verso]);
                }
            }

            return $righe === [] ? 'nessuno' : implode(' · ', $righe);
        };

        activity(AuditLog::NAME)
            ->causedByAnonymous()
            ->withProperties([
                'ruoli' => implode(', ', array_keys($diff)),
                'concessi dal reset' => $elenco('concessi'),
                'revocati dal reset' => $elenco('revocati'),
            ])
            ->log('Matrice ruoli riportata ai default');
    }
}

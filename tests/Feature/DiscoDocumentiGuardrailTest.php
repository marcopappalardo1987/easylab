<?php

use App\Providers\AppServiceProvider;

/**
 * 🔴 Meta-test del disco `documenti` (🔗 ADR-042).
 *
 * ## Le due metà, e falliscono in silenzio tutte e due
 *
 * - **Senza credenziali** → su Laravel Cloud le chiavi di un bucket non
 *   arrivano come `AWS_*`: la piattaforma inietta `LARAVEL_CLOUD_DISK_CONFIG` e
 *   registra un disco col nome del bucket. Un `documenti` che legge
 *   `env('AWS_ACCESS_KEY_ID')` funziona in locale e non in cloud, cioè il caso
 *   che nessuno prova prima del deploy.
 * - **Senza `throw`** → la configurazione iniettata dalla piattaforma porta
 *   `'throw' => false` fisso. È esattamente il flag per cui questo disco esiste
 *   separato dagli altri tre (`config/filesystems.php` lo spiega): con `false`
 *   un upload fallito torna `false` in silenzio, e un download di un file
 *   mancante produce un **200 troncato**, perché `readStream()` viene chiamato
 *   dentro la closure dello StreamedResponse, ad header già inviati.
 *
 * Il secondo è il motivo per cui non basta *nominare* il disco della
 * piattaforma, come si fa per le guide video (`config/guide.php`): lì un disco
 * senza `throw` va bene, qui no.
 */
it('keeps throw enabled on the documenti disk, whatever the environment', function () {
    // Vale in entrambi i rami: in locale è la definizione di
    // config/filesystems.php, in cloud è il flag che il provider rimette sopra
    // la configurazione iniettata.
    expect(config('filesystems.disks.documenti.throw'))->toBeTrue();
});

it('inherits the credentials of the platform disk, and re-asserts throw', function () {
    config([
        'filesystems.documenti_sorgente' => 'bucket-della-piattaforma',
        'filesystems.disks.bucket-della-piattaforma' => [
            'driver' => 's3',
            'key' => 'chiave-iniettata',
            'secret' => 'segreto-iniettato',
            'bucket' => 'easylab-staging',
            'url' => 'https://esempio.invalid',
            'endpoint' => 'https://esempio.r2.cloudflarestorage.com',
            'region' => 'auto',
            'use_path_style_endpoint' => false,
            // Come lo scrive Illuminate\Foundation\Cloud::configureDisks().
            'throw' => false,
            'report' => false,
        ],
    ]);

    ereditaIlDisco();

    $documenti = config('filesystems.disks.documenti');

    expect($documenti['driver'])->toBe('s3')
        ->and($documenti['key'])->toBe('chiave-iniettata')
        ->and($documenti['bucket'])->toBe('easylab-staging')
        ->and($documenti['endpoint'])->toBe('https://esempio.r2.cloudflarestorage.com')
        ->and($documenti['visibility'])->toBe('private')
        // La riga che regge tutto il test: la sorgente diceva false.
        ->and($documenti['throw'])->toBeTrue();
});

it('leaves a hand-configured disk alone when no platform bucket is attached', function () {
    // In sviluppo la sorgente è `local`: senza l'uscita anticipata il disco
    // documenti diventerebbe una copia di `local`, perdendo il proprio `root` e
    // scrivendo i documenti in mezzo al resto di storage/app/private.
    //
    // ⚠️ La definizione va riscritta qui e non letta com'è: `boot()` ha già
    // girato quando il test comincia, quindi confrontare con «il valore di
    // prima» confronterebbe il danno con sé stesso, e resterebbe verde.
    config([
        'filesystems.documenti_sorgente' => 'local',
        'filesystems.disks.documenti' => $atteso = [
            'driver' => 'local',
            'root' => storage_path('app/private/documenti'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],
    ]);

    ereditaIlDisco();

    expect(config('filesystems.disks.documenti'))->toBe($atteso);
});

it('wires the inheritance into boot, not just into a method nobody calls', function () {
    // I tre test qui sopra invocano il metodo per conto proprio: senza questa
    // verifica resterebbero verdi anche cancellando la chiamata da boot(),
    // cioè proprio nel modo in cui il difetto arriverebbe in produzione.
    $sorgente = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    $boot = substr(
        $sorgente,
        $inizio = strpos($sorgente, 'public function boot(): void'),
        strpos($sorgente, "\n    }\n", $inizio) - $inizio,
    );

    expect($boot)->toContain('$this->ereditaIlDiscoDocumentiDallAmbiente();');
});

function ereditaIlDisco(): void
{
    $provider = new AppServiceProvider(app());

    $metodo = new ReflectionMethod($provider, 'ereditaIlDiscoDocumentiDallAmbiente');
    $metodo->setAccessible(true);
    $metodo->invoke($provider);
}

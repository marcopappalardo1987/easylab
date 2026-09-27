<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Da quale disco eredita il disco `documenti`
    |--------------------------------------------------------------------------
    |
    | ⚠️ **Su Laravel Cloud le credenziali di un bucket NON arrivano come
    | `AWS_*`** (🔗 ADR-042, e la stessa nota in `config/guide.php`): la
    | piattaforma inietta `LARAVEL_CLOUD_DISK_CONFIG` e
    | `Illuminate\Foundation\Cloud::configureDisks()` registra al boot un disco
    | col nome del bucket. Un disco scritto a mano con `env('AWS_ACCESS_KEY_ID')`
    | nascerebbe quindi **senza credenziali** in cloud.
    |
    | Qui si nomina quel disco, e `AppServiceProvider` ne copia la
    | configurazione dentro `documenti` rimettendoci `throw => true`. Il default
    | segue il disco dell'ambiente: `local` in sviluppo, il bucket attaccato in
    | cloud. `DOCUMENTI_DISK` serve solo per puntare altrove.
    |
    */

    'documenti_sorgente' => env('DOCUMENTI_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Documenti allegati a strumenti e interventi (ADR-009/025/026).
         *
         * ⚠️ **`throw => true`, ed è la ragione per cui questo disco esiste
         * separato** invece di riusare `s3`. Con `false` — il default di
         * Laravel, che gli altri tre dischi portano — un upload fallito
         * restituisce `false` **in silenzio**: l'utente vedrebbe la riga
         * comparire in elenco e il file non ci sarebbe. Peggio in lettura:
         * `get()` e `readStream()` tornano `null`, e `response()` chiama
         * `readStream()` DENTRO la closure dello StreamedResponse — cioè a
         * header già inviati — quindi un file mancante produrrebbe un **200
         * troncato** invece di un errore.
         *
         * Cambiare il flag sui dischi esistenti sarebbe stato più semplice e
         * più rischioso: `local` e `public` servono anche ad altro, e un
         * `throw` globale trasformerebbe in eccezione ogni lettura mancante
         * già tollerata altrove. Qui il perimetro è uno solo.
         *
         * `root` distinto perché in locale (`FILESYSTEM_DISK=local`) i
         * documenti non finiscano mescolati al resto di `storage/app/private`.
         *
         * ⚠️ **In cloud questa definizione viene sostituita** da
         * `AppServiceProvider::ereditaIlDiscoDocumentiDallAmbiente()`, che copia
         * qui la configurazione del disco iniettato da Laravel Cloud e ci
         * rimette `throw => true` — che la piattaforma impone a `false`. Le
         * chiavi `AWS_*` qui sotto servono quindi al solo caso in cui si punti
         * un bucket **a mano** (🔗 ADR-042).
         */
        'documenti' => [
            'driver' => env('DOCUMENTI_DISK_DRIVER', 'local'),
            'root' => storage_path('app/private/documenti'),
            'serve' => false,
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
         * Il bucket di un ambiente Laravel Cloud, raggiunto DA FUORI.
         *
         * Serve solo a `easylab:pubblica-guide`, che gira sulla macchina dove
         * stanno gli mp4 — in cloud non ci sono, perché non sono in git.
         *
         * ⚠️ **Non si può riusare il meccanismo di Laravel Cloud da qui.** Il
         * framework registra i dischi iniettati solo se `laravel_cloud()` è
         * vero, cioè con `LARAVEL_CLOUD=1` in `$_ENV`/`$_SERVER`; e accenderlo
         * su una macchina di sviluppo attiverebbe anche code gestite, logging
         * su socket e connessione Postgres non poolata. Le quattro credenziali
         * si copiano a mano da `LARAVEL_CLOUD_DISK_CONFIG` del pannello.
         *
         * ⛔ **Un disco per ambiente, col suo nome.** Fino al 27 Set 2026 ce
         * n'era uno solo, `guide_remoto`, e il nome non diceva DOVE porta: due
         * guide sono finite sul bucket di staging credendo di pubblicarle in
         * produzione, e il difetto si è visto solo dall'indice vuoto del sito
         * vero. Un bucket sbagliato non dà errore — accetta i file e tace.
         *
         * Uso: `GUIDE_DISK=guide_produzione php artisan easylab:pubblica-guide`
         */
        'guide_staging' => [
            'driver' => 's3',
            'key' => env('GUIDE_STAGING_KEY'),
            'secret' => env('GUIDE_STAGING_SECRET'),
            'bucket' => env('GUIDE_STAGING_BUCKET'),
            'endpoint' => env('GUIDE_STAGING_ENDPOINT'),
            'region' => env('GUIDE_STAGING_REGION', 'auto'),
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'guide_produzione' => [
            'driver' => 's3',
            'key' => env('GUIDE_PRODUZIONE_KEY'),
            'secret' => env('GUIDE_PRODUZIONE_SECRET'),
            'bucket' => env('GUIDE_PRODUZIONE_BUCKET'),
            'endpoint' => env('GUIDE_PRODUZIONE_ENDPOINT'),
            'region' => env('GUIDE_PRODUZIONE_REGION', 'auto'),
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

];

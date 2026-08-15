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

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

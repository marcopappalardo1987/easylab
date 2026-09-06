<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    /*
    | 🔗 ADR-041. `Europe/Rome` e non `UTC`, con il default nel file e non solo
    | in `.env` — stessa disciplina di `locale` qui sotto: una dimenticanza al
    | deploy non deve riportare l'app due ore indietro.
    |
    | Non è una scelta di presentazione. I confini di questo dominio sono
    | **giorni civili italiani**: `before_or_equal:today` sulla data di
    | esecuzione di un intervento, la soglia a trenta giorni del semaforo,
    | l'obsolescenza in anni. Con l'app a UTC, fra mezzanotte e le 02:00
    | italiane `today()` è ancora ieri, e un tecnico che chiude un intervento a
    | tarda sera non può registrarlo con la data di oggi.
    |
    | ⚠️ Le colonne Postgres sono `timestamp without time zone` e non portano il
    | fuso: cambiare questa riga non sposta i dati, cambia come vengono letti.
    | Lo storico è stato riallineato una volta sola dalla migration
    | `2026_09_06_120000_riallinea_timestamp_a_europe_rome`, e chi ripristina un
    | dump anteriore a quella data deve rieseguirla.
    |
    | ⛔ Il fuso NON entra mai nella logica di dominio: le nove colonne castate
    | `date` (`data_scadenza`, `data_installazione`, …) sono giorni, non
    | istanti, e convertirle le sposterebbe di un giorno.
    | `FusoOrarioGuardrailTest` tiene entrambe le regole.
    */
    'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    /*
    | Il default è `it` e NON solo una riga di `.env`: l'app è italiana, e
    | affidare la lingua a una variabile d'ambiente significa che basta una
    | dimenticanza in CI o al deploy per far tornare i messaggi in inglese.
    |
    | ⚠️ Il fallback resta `en` di proposito: se manca una chiave in
    | `lang/it/`, l'utente legge il messaggio inglese del framework — degradato
    | ma comprensibile — invece della chiave grezza `validation.foo`.
    */
    'locale' => env('APP_LOCALE', 'it'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    // Le fixture restano in inglese: i nomi finti non sono UI.
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];

<?php

/*
|--------------------------------------------------------------------------
| Easy Lab — parametri di dominio
|--------------------------------------------------------------------------
| Consumato via helper tipizzati (App\Support\Semaforo), mai con config()
| sparsi nel codice — stesso patto di config/rbac.php ↔ App\Support\Rbac.
*/

return [

    'semaforo' => [
        // Soglia "imminente" (ADR-005): una scadenza (intervento o, dal
        // punto 7, garanzia) entro questi giorni accende l'arancione.
        // Confine INCLUSIVO: oggi+30 è imminente, oggi+31 no.
        // Decisione S3: 30 giorni uguali per tutti gli Enti; la soglia
        // per-tenant è rinviata (post-V1) — quando arriverà cambierà solo
        // Semaforo::giorniImminente(), non i chiamanti.
        'giorni_imminente' => 30,
    ],

    /*
    | Account di piattaforma creati dai seeder (DatabaseSeeder,
    | SuperadminSeeder). Le credenziali stanno QUI e non in `env()` dentro il
    | seeder: in produzione la config è cachata (`php artisan optimize` in
    | build), e con la config in cache Laravel non carica più il file .env —
    | ogni `env()` fuori da config/ torna null. È così che su staging il
    | Superadmin non veniva creato e il Developer nasceva con la password di
    | default: non un problema di variabili mancanti, ma di dove le si legge.
    */
    'piattaforma' => [
        'developer' => [
            'email' => env('DEVELOPER_EMAIL', 'info@advisionplus.com'),
            // Nessun default: senza variabile l'utente nasce con un tappo
            // random che nessuno conosce. Un default noto su un ambiente
            // raggiungibile da internet è una porta aperta, non una comodità.
            'password' => env('DEVELOPER_PASSWORD'),
        ],

        'superadmin' => [
            'email' => env('SUPERADMIN_EMAIL'),
            'password' => env('SUPERADMIN_PASSWORD'),
            'nome' => env('SUPERADMIN_NAME', 'Direzione EasyLab'),
            // Ragione sociale dell'Account e nome dell'Ente di piattaforma.
            'ente' => env('SUPERADMIN_ENTE', 'EasyLab'),
        ],
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    /*
     * Posta in uscita su Amazon SES (🔗 ADR-063, dal 23 Nov 2026).
     *
     * ⚠️ Variabili SES_* proprie, NON le AWS_* di Laravel: quelle in sviluppo
     * aprono il bucket dei documenti (`.env.example`, 🔗 ADR-042). Con le
     * stesse chiavi, un accesso perso per la posta aprirebbe anche i
     * documenti, e una chiave del bucket messa a mano in cloud diventerebbe la
     * chiave di invio. Qui ogni chiave apre una cosa sola.
     *
     * Regione di default Francoforte: è quella dell'identità del dominio e
     * dell'applicazione su Laravel Cloud. Un'altra regione non vede
     * l'identità verificata e rifiuta il mittente.
     */
    'ses' => [
        'key' => env('SES_ACCESS_KEY_ID'),
        'secret' => env('SES_SECRET_ACCESS_KEY'),
        'region' => env('SES_REGION', 'eu-central-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

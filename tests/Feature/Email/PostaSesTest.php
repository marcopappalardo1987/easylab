<?php

use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\Facades\Mail;

/**
 * La posta in uscita su Amazon SES (🔗 ADR-063).
 *
 * 🔴 Il negativo che conta: le chiavi del bucket dei documenti (AWS_*) non
 * devono mai diventare le chiavi di invio. Si rilegge `config/services.php`
 * con le variabili d'ambiente impostate a mano, perché la configurazione già
 * caricata non direbbe da quali variabili è nata.
 */
const VARIABILI_POSTA_SES = [
    'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION',
    'SES_ACCESS_KEY_ID', 'SES_SECRET_ACCESS_KEY', 'SES_REGION',
];

function servizi_con_ambiente_posta_ses(array $ambiente): array
{
    foreach ($ambiente as $nome => $valore) {
        $_SERVER[$nome] = $_ENV[$nome] = $valore;
    }

    return require base_path('config/services.php');
}

afterEach(function () {
    foreach (VARIABILI_POSTA_SES as $nome) {
        unset($_SERVER[$nome], $_ENV[$nome]);
    }
});

test('le chiavi del bucket non diventano le chiavi di invio', function () {
    $servizi = servizi_con_ambiente_posta_ses([
        'AWS_ACCESS_KEY_ID' => 'chiave-del-bucket',
        'AWS_SECRET_ACCESS_KEY' => 'segreto-del-bucket',
        'AWS_DEFAULT_REGION' => 'us-east-1',
    ]);

    expect($servizi['ses']['key'])->toBeNull()
        ->and($servizi['ses']['secret'])->toBeNull()
        ->and($servizi['ses']['region'])->toBe('eu-central-1');
});

test('le chiavi di invio arrivano dalle variabili SES', function () {
    $servizi = servizi_con_ambiente_posta_ses([
        'AWS_ACCESS_KEY_ID' => 'chiave-del-bucket',
        'AWS_SECRET_ACCESS_KEY' => 'segreto-del-bucket',
        'SES_ACCESS_KEY_ID' => 'chiave-di-invio',
        'SES_SECRET_ACCESS_KEY' => 'segreto-di-invio',
        'SES_REGION' => 'eu-west-1',
    ]);

    expect($servizi['ses'])->toBe([
        'key' => 'chiave-di-invio',
        'secret' => 'segreto-di-invio',
        'region' => 'eu-west-1',
    ]);
});

test('il mailer ses parla con SES a Francoforte con le chiavi di invio', function () {
    $servizi = servizi_con_ambiente_posta_ses([
        'SES_ACCESS_KEY_ID' => 'chiave-di-invio',
        'SES_SECRET_ACCESS_KEY' => 'segreto-di-invio',
    ]);
    config(['services.ses' => $servizi['ses'], 'mail.default' => 'ses']);

    $trasporto = Mail::mailer()->getSymfonyTransport();

    expect($trasporto)->toBeInstanceOf(SesTransport::class)
        ->and($trasporto->ses()->getRegion())->toBe('eu-central-1')
        ->and($trasporto->ses()->getCredentials()->wait()->getAccessKeyId())->toBe('chiave-di-invio');
});

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Infrastruttura neutralizzata per OGNI test, qualunque cosa dica
     * l'ambiente in cui la suite gira.
     *
     * `phpunit.xml` dichiara già `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array` e
     * `CACHE_STORE=array`, ma quelle righe valgono solo se la variabile non è
     * **già** nell'ambiente: la CI esporta `QUEUE_CONNECTION=redis` e
     * `CACHE_STORE=redis` per i servizi del job, e vincono loro. (Nemmeno
     * `force="true"` basta: Laravel non legge le env dal repository che PHPUnit
     * modifica.)
     *
     * La conseguenza si è vista il 18 Ago 2026 mergiando S5: le notifiche
     * `ShouldQueue` finivano in coda su Redis, dove nessun worker le eseguiva,
     * e due test della campanella erano **verdi in locale e rossi in CI**. Una
     * suite che dice cose diverse secondo dove gira non è una rete di sicurezza.
     *
     * Qui si agisce sulla config dell'applicazione già avviata, che è ciò che
     * il codice legge davvero. ⚠️ Il database NON si tocca di proposito: deve
     * restare sovrascrivibile per girare su Postgres — in CI e con
     * `DB_DATABASE=easylab_test` in locale, che è la difesa contro il tranello
     * delle date fra SQLite e Postgres (CLAUDE.md).
     */
    protected function setUp(): void
    {
        // PRIMA del bootstrap, non dopo: alcuni servizi si agganciano allo
        // store al momento in cui nascono e non rileggono più la config. Il
        // `RateLimiter` è uno di questi — col throttle di `/invito/{user}` su
        // una cache Redis condivisa il contatore sopravviveva DA UN TEST
        // ALL'ALTRO, e al settimo POST arrivava un 429 al posto della risposta
        // attesa. In CI ne cadeva uno solo (la suite è lenta e la finestra di
        // un minuto si riapriva), in locale sei: un test che fallisce a
        // seconda di quanto va veloce la macchina è peggio di un test rotto.
        //
        // Si scrivono tutte e tre le sedi perché Laravel le legge da adapter
        // diversi a seconda della versione.
        foreach (self::AMBIENTE_NEUTRO as $chiave => $valore) {
            putenv("{$chiave}={$valore}");
            $_ENV[$chiave] = $valore;
            $_SERVER[$chiave] = $valore;
        }

        parent::setUp();
    }

    /**
     * L'infrastruttura che ogni test deve trovare, qualunque cosa dica
     * l'ambiente: niente code (le notifiche `ShouldQueue` devono arrivare
     * subito, non aspettare un worker che in test non esiste), niente mail in
     * uscita, niente cache né sessione condivise fra un test e il successivo.
     *
     * ⚠️ Il **database non è qui** di proposito: deve restare sovrascrivibile
     * per girare su Postgres — in CI e con `DB_DATABASE=easylab_test` in
     * locale, che è la difesa contro il tranello delle date fra i due driver
     * (CLAUDE.md).
     */
    private const AMBIENTE_NEUTRO = [
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ];
}

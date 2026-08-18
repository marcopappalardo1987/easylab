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
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
    }
}

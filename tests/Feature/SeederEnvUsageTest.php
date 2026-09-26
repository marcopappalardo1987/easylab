<?php

/**
 * I seeder non devono leggere `env()`.
 *
 * In produzione la config è cachata (`php artisan optimize` in fase di build) e
 * Laravel, trovando la cache, **non carica più il file .env**: ogni `env()`
 * fuori da `config/` torna null. Un seeder che legge l'ambiente direttamente
 * funziona in locale e fallisce in silenzio dove conta — è così che su staging
 * il Superadmin non veniva creato e il Developer nasceva con la password di
 * default. La lettura sta in `config/easylab.php`; qui si legge `config()`.
 */
it('reads credentials from config, never from env', function () {
    $colpevoli = [];

    foreach (glob(database_path('seeders/*.php')) as $file) {
        // Sui token e non con una regex: `env()` compare anche nei commenti che
        // spiegano perché NON si usa, e un meta-test che si fa ingannare da un
        // commento è un meta-test che verrà disattivato al primo falso positivo.
        $token = array_values(array_filter(
            token_get_all(file_get_contents($file)),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($token as $i => $corrente) {
            $chiamaEnv = is_array($corrente)
                && $corrente[0] === T_STRING
                && $corrente[1] === 'env'
                && ($token[$i + 1] ?? null) === '('
                && ! in_array($token[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

            if ($chiamaEnv) {
                $colpevoli[] = basename($file);
                break;
            }
        }
    }

    expect($colpevoli)->toBeEmpty(
        'Questi seeder leggono env(): '.implode(', ', $colpevoli).
        '. Spostare la lettura in config/easylab.php.'
    );
});

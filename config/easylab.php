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
    | I piani commerciali (ADR-002, ADR-032). Consumati via App\Support\Piani.
    |
    | Il «limite di Enti» che ADR-032 chiama attributo del piano vive qui e
    | non a DB: è un parametro di prodotto, cambia con un commit e non con una
    | migrazione, ed è la stessa forma di config/rbac.php — il catalogo sta in
    | config, ciò che il singolo cliente ha comprato sta a DB (accounts.piano).
    |
    | ⚠️ Il piano FREE non ha `stripe_price`, e non è una casella da riempire
    | più avanti: un cliente Free non ha customer Stripe né subscription (è
    | omaggiato a fronte di un contratto di manutenzione fisico, fatturato
    | fuori dal software — ADR-002). Da qui la regola che regge tutto il
    | blocco: `accounts.piano` è l'UNICA fonte del piano, e nessun percorso
    | legge lo stato della subscription per saperlo — altrimenti metà dei
    | clienti non avrebbe nulla da leggere.
    |
    | `env()` DENTRO config/ è legittimo ed è il posto giusto: la lezione del
    | 19 Ago (qui sotto) vieta di leggerla FUORI da config/, non qui.
    |
    | 💶 `prezzo_mensile_cent` è il LISTINO, in centesimi interi e mai float
    | (un MRR su decine di clienti in float accumula errore, ed è la riga che
    | nessuno rilegge). Il Free dichiara 0 invece di ometterlo: `null`
    | significherebbe «non lo so», e in un totale la differenza conta.
    |
    | ⚠️ Questo prezzo vive ANCHE su Stripe (`stripe_price`) e i due possono
    | divergere — una promozione, un prezzo storico rimasto su un cliente, una
    | modifica fatta in dashboard. Il numero qui è quello di listino: l'MRR che
    | ne esce è «contratti in essere a listino», NON «incassato». La verità
    | contabile resta Stripe; la dashboard Superadmin è la cabina di regia, non
    | il bilancio — e lo dice anche in pagina, per non farsi citare in una
    | riunione al posto di un estratto conto.
    |
    | Non `env()` come il price id: il prezzo è un parametro di PRODOTTO e deve
    | cambiare con un commit visibile, come `max_enti`.
    |
    | 🚧 **I 4900 sono un segnaposto**, scelto il 21 Ago 2026 per far girare il
    | flusso su staging, e vanno sostituiti col listino vero. Su Stripe i Price
    | sono **immutabili**: cambiare cifra significa crearne uno nuovo e
    | aggiornare `STRIPE_PRICE_SAAS`, non modificare quello esistente.
    |
    | ⚠️ **Nulla in questo repository può accorgersi se questo numero e il Price
    | su Stripe divergono**: il price id vive nell'ambiente, l'importo qui, e i
    | due non si incontrano mai. Un test non può parlare con Stripe. L'unico
    | posto dove il confronto sarebbe possibile è un comando che legga il Price
    | e confronti `unit_amount` e `currency` — oggi non esiste, ed è la ragione
    | per cui questa riga dice «a listino» e non «incassato».
    |
    | La **valuta** non è qui: è `cashier.currency` (EUR). Le due devono restare
    | d'accordo, e nessun meccanismo lo garantisce.
    */
    'piani' => [
        'predefinito' => 'free',

        'catalogo' => [
            'free' => [
                'etichetta' => 'Free',
                'max_enti' => 1,
                // La gratuità si DICHIARA, non si deduce dall'assenza del
                // prezzo: «piano omaggiato» e «price id non ancora configurato»
                // sono due situazioni diverse, e confonderle nasconde un errore
                // di configurazione dietro un messaggio rassicurante.
                'gratuito' => true,
                'stripe_price' => null,
                'prezzo_mensile_cent' => 0,
            ],
            'saas' => [
                'etichetta' => 'SaaS',
                'max_enti' => 5,
                'gratuito' => false,
                'stripe_price' => env('STRIPE_PRICE_SAAS'),
                'prezzo_mensile_cent' => 4900,
            ],
        ],
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

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
    | I piani commerciali (ADR-002, ADR-032, ADR-035). Consumati via
    | App\Support\Piani.
    |
    | ⛔ **DAL 27 AGO 2026 `catalogo` NON È PIÙ IL LISTINO.** ADR-035 lo ha
    | spostato a database (tabella `piani`), e da lì lo governa la schermata
    | /piattaforma/piani. Quello che resta qui sotto è **solo il bootstrap**: lo
    | legge una volta sola la migration di backfill
    | `2026_08_27_140200_backfill_listino_dal_catalogo`, e nessun altro codice
    | dell'applicazione lo apre più. Modificare questi numeri **non cambia il
    | prezzo di nessuno** — cambia solo ciò con cui nascerebbe un database nuovo,
    | e `ListinoBootstrapTest` diventa rosso apposta per dirlo a chi ci prova.
    |
    | È lo stesso patto di config/rbac.php ↔ la matrice a database (ADR-016 §7),
    | con una differenza deliberata: là esiste un seeder rilanciabile, ed è
    | proprio quello che rende distruttivo il gesto corretto (`syncPermissions()`
    | detacha tutto e riattacca dai default). Qui **non esiste nessun seeder del
    | listino**: la trappola non si documenta, si rende inesprimibile.
    |
    | `predefinito` invece è **vivo** e resta qui: non è il listino, è «con che
    | piano nasce un account» — un parametro di prodotto come `giorni_imminente`,
    | e nessuna schermata lo crea. Lo legge `Piani::predefinito()`, che è chiamato
    | da un webhook (disdetta → decadimento a free): lì un'eccezione farebbe
    | ritentare Stripe per giorni, quindi la coerenza col DB la tiene un test
    | (`ListinoBootstrapTest`) e non una guardia a runtime.
    |
    | Il «limite di Enti» che ADR-032 chiama attributo del piano nasce quindi da
    | qui e vive a DB: cambia con un click su /piattaforma/piani, e ciò che il
    | singolo cliente ha comprato sta su accounts.piano.
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
    | ⚠️ *La riga che seguiva diceva: «nulla in questo repository può accorgersi
    | se questo numero e il Price su Stripe divergono». **Ha smesso di essere
    | vera il 27 Ago 2026**: /piattaforma/piani ha l'azione «Confronta con
    | Stripe», che legge `unit_amount` e `currency` e mostra i due valori
    | affiancati. La conciliazione non ripara nulla da sola — il DB è la verità
    | per il dominio, Stripe per il denaro — ma la divergenza si vede.*
    |
    | ⚠️ La **valuta** non era qui ed era `cashier.currency` (EUR) e basta: la
    | riga «nessun meccanismo lo garantisce» si è chiusa registrandola sulla riga
    | di `prezzi_piano` al momento in cui il price viene creato, che è ciò che
    | rende il confronto possibile senza indovinare.
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

    /*
    | Error tracker interno (S6 — `docs/Architettura/Error Tracker Interno
    | (piano).md`, ADR-017). Su Laravel Cloud `laravel.log` vive su un disco
    | effimero e per-replica: il database è l'unico posto in cui un errore visto
    | da un cliente lascia una traccia consultabile.
    |
    | ⚠️ Questa config **non è** `config/rbac.php`: non è il bootstrap di
    | qualcosa che poi vive a DB, quindi cambiarla NON richiede alcun riseeding.
    |
    | Due chiavi leggono l'ambiente e tre no, e la linea che le separa è la
    | stessa di `piani` qui sopra: ciò che cambia **da ambiente a ambiente**
    | (l'interruttore, la casella che riceve gli alert) sta in `env()`; ciò che è
    | un parametro di **prodotto** (quanti contesti bastano, ogni quanto, quante
    | email al giorno sono troppe) è un numero che deve cambiare con un commit
    | visibile, come `giorni_imminente` e `prezzo_mensile_cent`.
    |
    | ⚠️ In produzione la config è cachata in build (`php artisan optimize`),
    | quindi `ERRORI_ABILITATO=false` **richiede un redeploy** per avere effetto:
    | è un interruttore d'emergenza lento, e va saputo prima di averne bisogno.
    */
    'errori' => [
        // L'interruttore generale della cattura. `true` di default: un tracker
        // spento di default sarebbe un tracker che non c'è, e ci si accorgerebbe
        // dell'errore di configurazione solo cercando l'errore che non è stato
        // registrato.
        'abilitato' => env('ERRORI_ABILITATO', true),

        // Destinatario dell'alert. `null` = nessun alert, e il tracker resta
        // muto senza lamentarsi: registrare gli errori ha valore anche senza
        // email.
        //
        // ⚠️ È **un canale senza gate e senza registro di audit**: la pagina
        // degli errori è del solo Developer (`system.logs.view`), l'email arriva
        // a chiunque legga questa casella. Dev'essere una casella controllata.
        'alert_email' => env('ERRORI_ALERT_EMAIL'),

        // Quante occorrenze conservare **col contesto** per ogni errore. Oltre,
        // cresce solo il contatore: la ventesima copia dello stesso stack trace
        // non insegna nulla che non dicessero le prime, e ogni riga porta dati
        // personali.
        //
        // Il conteggio si azzera alla riapertura di una issue risolta: senza,
        // dopo un tentativo di correzione la issue sarebbe già al cap e non
        // catturerebbe **mai più** la prova che serve a rispondere a «l'ho
        // corretto, perché succede ancora?».
        'contesti_per_errore' => 20,

        // Distanza minima fra due contesti conservati dello stesso errore. È la
        // guardia contro il loop caldo: un errore che scatta mille volte al
        // minuto consumerebbe il budget di contesti in un secondo, e li
        // spenderebbe tutti sullo stesso istante.
        'finestra_contesto_secondi' => 60,

        // Tetto **globale** agli alert di una giornata, non per-errore: serve a
        // proteggere la casella dalla tempesta di issue nuove di un deploy
        // sbagliato, cioè proprio dal caso in cui gli errori sono tanti e
        // diversi. Per questo si conta sul database (`alert_inviato_at` di oggi)
        // e non in una colonna della singola issue, che delle altre non sa nulla.
        'alert_max_giornalieri' => 20,
    ],

];

<?php

/*
|--------------------------------------------------------------------------
| RBAC — fonte di verità di bootstrap (Sprint 1 · punto 5)
|--------------------------------------------------------------------------
|
| Catalogo permessi, matrice ruolo→permesso e set bloccato, tradotti da
| docs/Architettura/Schema Ruoli e Permessi.md (§4, §5, §7). Consumato dal
| RolesAndPermissionsSeeder, dall'helper App\Support\Rbac, dal middleware 2FA
| e (in futuro) dalla UI Superadmin (S6).
|
| NB: qui vive SOLO il livello 1 (il "cosa" — ruolo→permesso). Lo scope
| per-riga (tenant_id, sotto-albero, unione tecnico, privacy righe garanzie
| ricambio) è livello 2 e vive in Global Scope/Policy (S2), non qui.
| Dopo il primo seeding la fonte di verità diventa il DB (ADR-016 §7).
|
*/

return [

    // Catalogo canonico (§4) — 54 permessi. Erano 56 fino a S3-bis: ADR-019 ha
    // eliminato `letture_contaore.view`/`.create` insieme al contaore. Il numero
    // è ripetuto nei test (RbacSeederTest, DatabaseSeederTest): se cambia qui
    // deve cambiare anche là, o uno dei due sta mentendo.
    'permissions' => [
        // 4.1 Anagrafica & asset
        'unita_organizzativa.view', 'unita_organizzativa.create', 'unita_organizzativa.update', 'unita_organizzativa.delete',
        'strumenti.view', 'strumenti.create', 'strumenti.update', 'strumenti.delete',
        'strumenti.move', 'strumenti.qr_generate',
        'spostamenti.view',

        // 4.2 Interventi & semaforo
        'interventi.view', 'interventi.create', 'interventi.update', 'interventi.delete',
        'interventi.complete', 'interventi.assign',
        'semaforo.force',

        // 4.3 Garanzie
        'garanzie.macchina.view', 'garanzie.macchina.manage',
        'garanzie.ricambio.view', 'garanzie.ricambio.manage',

        // 4.4 Ricambi & fornitori
        'ricambi.view', 'ricambi.create', 'ricambi.update', 'ricambi.delete', 'ricambi.merge',
        'ricambio_utilizzo.view', 'ricambio_utilizzo.create', 'ricambio_utilizzo.update', 'ricambio_utilizzo.delete',
        'fornitori.view', 'fornitori.create', 'fornitori.update', 'fornitori.delete',

        // 4.5 Documenti & QR
        'documenti.view', 'documenti.upload', 'documenti.download', 'documenti.delete', 'documenti.export_pdf',
        'qr.scan',

        // 4.6 Billing & tenancy
        'billing.manage_own', 'billing.manage_global', 'billing.lockout',
        'tenants.view_all', 'tenants.provision',

        // 4.7 Utenti, audit, sistema
        'utenti.view', 'utenti.create', 'utenti.update', 'utenti.delete', 'utenti.impersonate',
        'audit.view',
        'system.logs.view',
        'roles.manage',
    ],

    /*
    | Matrice §5. Forme supportate per ogni ruolo:
    |   ['all' => true]                      → tutti i permessi
    |   ['all' => true, 'except' => [...]]   → tutti tranne questi
    |   ['only' => [...]]                    → esattamente questi
    */
    'roles' => [

        'Developer' => ['all' => true],

        'Superadmin' => ['all' => true, 'except' => [
            'system.logs.view',
        ]],

        'Admin' => ['all' => true, 'except' => [
            'billing.manage_global', 'billing.lockout',
            'tenants.view_all', 'tenants.provision',
            'utenti.impersonate', 'system.logs.view', 'roles.manage',
        ]],

        'Responsabile Reparto' => ['only' => [
            'unita_organizzativa.view',
            'strumenti.view', 'strumenti.create', 'strumenti.update', 'strumenti.delete', 'strumenti.move', 'strumenti.qr_generate',
            'spostamenti.view',
            'interventi.view', 'interventi.create', 'interventi.update', 'interventi.delete', 'interventi.complete', 'interventi.assign',
            'semaforo.force',
            'garanzie.macchina.view', 'garanzie.macchina.manage',
            'garanzie.ricambio.view', 'garanzie.ricambio.manage',
            'ricambi.view', 'ricambi.create', 'ricambi.update', 'ricambi.delete',
            'ricambio_utilizzo.view', 'ricambio_utilizzo.create', 'ricambio_utilizzo.update', 'ricambio_utilizzo.delete',
            'fornitori.view',
            'documenti.view', 'documenti.upload', 'documenti.download', 'documenti.delete', 'documenti.export_pdf',
            'qr.scan',
        ]],

        // Tenant: `garanzie.ricambio.*` è suo dal 15 Ago 2026 (ADR-029, deciso il 9), ed è
        // l'inversione della postura che il progetto portava da Fase 2. Il
        // divieto («mai al Tenant») non era mai stato deciso davvero: ADR-004 lo
        // ratificò dichiarandolo «vincolo di privacy già previsto», e da lì
        // arrivò al set 🔒 e ai test. Poggiava su una premessa mai scritta — che
        // i ricambi li fornisca EasyLab — che non regge quando il Tenant è
        // l'intestatario dell'abbonamento e il pezzo sta sulla sua macchina.
        //
        // ⚠️ Qui c'è il DEFAULT della piattaforma, non l'ultima parola: con
        // `teams = false` i permessi di un ruolo sono globali, quindi
        // l'eccezione del singolo Ente vive altrove — nella colonna
        // `visibilita_garanzie_ricambio` e in `GaranziaRicambioPolicy`, che
        // RESTRINGE questo default e non lo allarga mai.
        'Tenant' => ['only' => [
            'unita_organizzativa.view',
            'strumenti.view',
            'interventi.view',
            'garanzie.macchina.view',
            'garanzie.ricambio.view', 'garanzie.ricambio.manage',
            'ricambi.view',
            'ricambio_utilizzo.view',
            // `fornitori.view` in sola lettura: ADR-023 e Schema Ruoli nota ⁵
            // lo davano per approvato il 3 Ago 2026, ma il bootstrap non lo
            // produceva — il documento affermava un default che la config non
            // creava, e il riseeding non l'avrebbe aggiunto da solo. Applicato
            // il 15 Ago 2026 col blocco Fornitore. Il fornitore è un campo
            // della scheda della SUA macchina, non un dato commerciale di
            // EasyLab.
            'fornitori.view',
            'documenti.view', 'documenti.upload', 'documenti.download', 'documenti.export_pdf',
            // `qr.scan` al Tenant dal 15 Ago 2026: Elenco Funzionalità §4 dice
            // «il tecnico **o il cliente** inquadra il QR», la matrice diceva
            // ❌ — e la contraddizione si vedeva nell'uso, perché il cliente
            // inquadrava l'adesivo sulla propria macchina e prendeva 403 pur
            // potendo aprire la stessa scheda dal menù. Il QR è una
            // scorciatoia verso una pagina già autorizzata, non un accesso in
            // più: `AccessoQr` reindirizza a `strumenti.show`, che resta
            // gatata e scopata come prima.
            'qr.scan',
        ]],

        // Tecnico: catalogo ricambi solo 'create' (nota ⁴ del doc), niente update/delete.
        //
        // `garanzie.ricambio.*` è suo dall'8 Ago 2026 (ADR-027): è la persona che
        // monta fisicamente il pezzo, quindi la fonte del dato sulla garanzia, e
        // ogni sua scrittura è tracciata sul canale `audit`. Il divieto che c'era
        // qui prima non era mai stato deciso — ADR-004 dice «mai al Tenant» e
        // nomina il solo Tenant; il Tecnico è personale EasyLab. La citazione si
        // era allargata oltre la fonte passando per Schema Ruoli §4.3 e ADR-016,
        // ed era finita difesa da un test: da lì in poi la svista era
        // indistinguibile da una decisione. Resta ristretto agli strumenti che
        // già vede (portafoglio ∪ assegnazione, ADR-007) — quello è livello 2.
        'Tecnico' => ['only' => [
            'unita_organizzativa.view',
            'strumenti.view',
            'interventi.view', 'interventi.complete',
            'garanzie.macchina.view',
            'garanzie.ricambio.view', 'garanzie.ricambio.manage',
            'ricambi.view', 'ricambi.create',
            'ricambio_utilizzo.view', 'ricambio_utilizzo.create', 'ricambio_utilizzo.update', 'ricambio_utilizzo.delete',
            'documenti.view', 'documenti.upload', 'documenti.download',
            'qr.scan',
        ]],

        // Gestore (🔗 ADR-046, 6 Ott 2026): personale EasyLab che **gestisce la
        // manutenzione** dei clienti che gli sono assegnati. Senza Ente
        // (`tenant_id` NULL) e con lo stesso portafoglio del Tecnico
        // (`tecnico_cliente`): vede e scrive solo le sedi che il Superadmin gli
        // ha dato — quello è livello 2, e sta in `AccessoTecnico`.
        //
        // Cosa NON ha, ed è deciso:
        // - `unita_organizzativa.delete`: reparti e sotto-laboratori li crea e
        //   li rinomina, non li elimina (Marco, 6 Ott 2026). Il nodo Ente non lo
        //   tocca affatto: lo rifiuta `Albero`, non la matrice.
        // - `strumenti.delete`, `ricambi.delete`, `ricambi.merge`,
        //   `fornitori.delete`: i gesti che tolgono dati al cliente restano a
        //   chi del cliente risponde.
        // - utenti, billing, audit e tutta la piattaforma: lavora sui dati, non
        //   sul rapporto.
        'Gestore' => ['only' => [
            'unita_organizzativa.view', 'unita_organizzativa.create', 'unita_organizzativa.update',
            'strumenti.view', 'strumenti.create', 'strumenti.update', 'strumenti.move', 'strumenti.qr_generate',
            'spostamenti.view',
            'interventi.view', 'interventi.create', 'interventi.update', 'interventi.delete', 'interventi.complete', 'interventi.assign',
            'semaforo.force',
            'garanzie.macchina.view', 'garanzie.macchina.manage',
            'garanzie.ricambio.view', 'garanzie.ricambio.manage',
            'ricambi.view', 'ricambi.create', 'ricambi.update',
            'ricambio_utilizzo.view', 'ricambio_utilizzo.create', 'ricambio_utilizzo.update', 'ricambio_utilizzo.delete',
            'fornitori.view', 'fornitori.create', 'fornitori.update',
            'documenti.view', 'documenti.upload', 'documenti.download', 'documenti.delete', 'documenti.export_pdf',
            'qr.scan',
        ]],
    ],

    // Set bloccato 🔒 (§7) — non modificabile dalla UI Superadmin.
    //
    // "Bloccato" dice che la UI non può ridistribuirlo, NON a chi è negato: le
    // due cose si erano confuse proprio su `garanzie.ricambio.*` (ADR-027).
    //
    // Quelle due voci sono USCITE dal set il 15 Ago 2026 (ADR-029): non essendoci
    // più un divieto assoluto da difendere, tenerle qui avrebbe impedito alla UI
    // di S6 di cambiare un default che ora È cambiabile per decisione. Il set
    // torna così a contenere solo ciò che è bloccato per legge, sicurezza o
    // struttura — e da 9 voci passa a 7.
    //
    // Conseguenza da conoscere: l'editor di S6 potrà revocare quei permessi al
    // ruolo Tenant per TUTTI gli Enti insieme, scavalcando le impostazioni
    // per-Ente (che restringono, non allargano). È coerente — chi governa la
    // piattaforma governa il default — ma non è ovvio leggendo il codice.
    'locked' => [
        'utenti.impersonate',
        'system.logs.view',
        'billing.manage_global', 'billing.lockout',
        'tenants.view_all', 'tenants.provision',
        'roles.manage',
    ],

    // Ruoli la cui RIGA è in sola lettura nell'editor della matrice (S6).
    //
    // È una cosa DIVERSA dal set bloccato qui sopra: `locked` è una colonna
    // (un permesso che nessun ruolo può guadagnare o perdere dalla UI), questa
    // è una riga (un ruolo di cui non si tocca nessuna cella). Le due guardie
    // si incrociano e non si sostituiscono.
    //
    // Il Developer c'è perché `['all' => true]` è la CHIAVE DI RISERVA della
    // piattaforma e non esiste alcun `Gate::before` da super-admin
    // (`AppServiceProvider` registra solo `Gate::policy()`): il Developer
    // dipende davvero dalla matrice a DB, quindi un editor che potesse
    // svuotarne la riga permesso per permesso toglierebbe l'ultima via di
    // rientro. Il set bloccato non basta a coprirlo — protegge sette permessi,
    // non gli altri quarantasette.
    //
    // ⚠️ NON è la stessa lista di `User::canBeImpersonated()`, che oggi contiene
    // anch'essa il solo Developer. «Chi non si impersona» e «di chi non si tocca
    // la riga» sono due decisioni indipendenti e possono divergere
    // legittimamente (si potrebbe voler rendere inerte anche la riga Superadmin
    // senza renderlo non impersonabile): legarle con un meta-test sarebbe una
    // falsa equivalenza, che diventerebbe rossa su una scelta corretta.
    //
    // ⚠️ Questa chiave NON è nella matrice e il seeder non la legge: aggiungerla
    // o cambiarla **non richiede** un riseeding (CLAUDE.md riga 47 non si
    // applica) — e riseminare per riflesso cancellerebbe le personalizzazioni
    // fatte dalla UI, perché `syncPermissions` detacha tutto.
    'protected_roles' => ['Developer'],

    // Ruoli per cui il 2FA è obbligatorio (roadmap kickoff §2FA).
    //
    // ⚠️ **Il Developer NON c'è più, dal 6 Ott 2026, ed è una decisione di
    // Marco** (🔗 ADR-046): è l'account con cui si fa debug, e deve poter
    // impersonare senza un secondo fattore. Resta facoltativo — chi lo attiva
    // se lo vede chiedere al login come tutti.
    //
    // 🔴 Il costo va tenuto presente, perché è il più alto della lista: il
    // Developer ha ogni permesso, impersona chiunque e non è impersonabile.
    // Senza secondo fattore la sua password è l'unica cosa fra un estraneo e i
    // dati di tutti i clienti: deve essere lunga, casuale e non riusata, e
    // `DEVELOPER_PASSWORD` non deve vivere fuori dall'ambiente.
    //
    // ⚠️ Come per `protected_roles`: questa chiave non è nella matrice e il
    // seeder non la legge. Cambiarla **non richiede** un riseeding.
    //
    // Il Gestore c'è (ADR-046): scrive sui dati di più clienti insieme, cioè più
    // di quanto possa un Admin, che il secondo fattore lo ha già obbligatorio.
    'two_factor_required_roles' => ['Superadmin', 'Admin', 'Gestore'],

];

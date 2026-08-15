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
            'documenti.view', 'documenti.upload', 'documenti.download', 'documenti.export_pdf',
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

    // Ruoli per cui il 2FA è obbligatorio (roadmap kickoff §2FA).
    'two_factor_required_roles' => ['Developer', 'Superadmin', 'Admin'],

];

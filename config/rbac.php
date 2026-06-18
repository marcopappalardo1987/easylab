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

    // Catalogo canonico (§4) — 56 permessi.
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

        // 4.3 Garanzie & contaore
        'garanzie.macchina.view', 'garanzie.macchina.manage',
        'garanzie.ricambio.view', 'garanzie.ricambio.manage',
        'letture_contaore.view', 'letture_contaore.create',

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
            'letture_contaore.view', 'letture_contaore.create',
            'ricambi.view', 'ricambi.create', 'ricambi.update', 'ricambi.delete',
            'ricambio_utilizzo.view', 'ricambio_utilizzo.create', 'ricambio_utilizzo.update', 'ricambio_utilizzo.delete',
            'fornitori.view',
            'documenti.view', 'documenti.upload', 'documenti.download', 'documenti.delete', 'documenti.export_pdf',
            'qr.scan',
        ]],

        'Tenant' => ['only' => [
            'unita_organizzativa.view',
            'strumenti.view',
            'interventi.view',
            'garanzie.macchina.view',
            'letture_contaore.view',
            'ricambi.view',
            'ricambio_utilizzo.view',
            'documenti.view', 'documenti.upload', 'documenti.download', 'documenti.export_pdf',
        ]],

        // Tecnico: catalogo ricambi solo 'create' (nota ⁴ del doc), niente update/delete.
        'Tecnico' => ['only' => [
            'unita_organizzativa.view',
            'strumenti.view',
            'interventi.view', 'interventi.complete',
            'garanzie.macchina.view',
            'letture_contaore.view', 'letture_contaore.create',
            'ricambi.view', 'ricambi.create',
            'ricambio_utilizzo.view', 'ricambio_utilizzo.create', 'ricambio_utilizzo.update', 'ricambio_utilizzo.delete',
            'documenti.view', 'documenti.upload', 'documenti.download',
            'qr.scan',
        ]],
    ],

    // Set bloccato 🔒 (§7) — non modificabile dalla UI Superadmin.
    'locked' => [
        'garanzie.ricambio.view', 'garanzie.ricambio.manage',
        'utenti.impersonate',
        'system.logs.view',
        'billing.manage_global', 'billing.lockout',
        'tenants.view_all', 'tenants.provision',
        'roles.manage',
    ],

    // Ruoli per cui il 2FA è obbligatorio (roadmap kickoff §2FA).
    'two_factor_required_roles' => ['Developer', 'Superadmin', 'Admin'],

];

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

];

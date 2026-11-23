<?php

/*
 * Le frasi del recupero password, in italiano (23 Nov 2026: mancavano, e
 * Laravel ripiegava sull'inglese — «We have emailed your password reset link.»
 * sotto una pagina tutta italiana).
 *
 * ⚠️ `sent` è la risposta UNIFORME: la vede anche chi scrive un indirizzo che
 * non esiste (🔗 FortifyServiceProvider, S7). Quindi non dice «ti abbiamo
 * mandato», ma «se l'indirizzo è registrato»: è vero in entrambi i casi.
 */
return [
    'reset' => 'La password è stata reimpostata.',
    'sent' => 'Se l\'indirizzo è registrato, riceverai a breve un\'email con il link per reimpostare la password. Il link vale 60 minuti.',
    'throttled' => 'Hai già chiesto un link poco fa: aspetta un minuto prima di riprovare.',
    'token' => 'Il link per reimpostare la password non è valido o è scaduto. Chiedine uno nuovo.',
    'user' => 'Se l\'indirizzo è registrato, riceverai a breve un\'email con il link per reimpostare la password.',
];

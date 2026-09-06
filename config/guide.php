<?php

/**
 * Il manuale in applicazione: quali guide esistono e come sono raggruppate.
 *
 * ⚠️ **Qui non stanno i testi.** Titolo, sottotitolo, passi e durata si leggono
 * dal `manifest.json` che lo studio delle guide (`guide/`) produce insieme al
 * video: le didascalie del video **sono** i passi scritti, e tenerne due copie
 * significa vederle divergere al primo ritocco. Questo file aggiunge solo ciò
 * che il manifest non sa: a quale argomento appartiene una guida, e con quali
 * parole la si cerca.
 *
 * L'elenco completo delle guide previste — comprese quelle non ancora girate —
 * sta in `guide/CATALOGO.md`. Una guida compare qui **quando il suo mp4 esiste**
 * in `public/guide/<slug>/`, cioè dopo `guide/bin/pubblica.sh`.
 */
return [

    // Le lettere sono quelle di `guide/CATALOGO.md`: restano allineate perché
    // chi produce una guida legge quel file e ritrova qui lo stesso argomento.
    'argomenti' => [
        'A' => [
            'titolo' => 'Primi passi',
            'sommario' => 'Entrare, orientarsi, e sistemare le proprie preferenze.',
        ],
        'D' => [
            'titolo' => 'Interventi',
            'sommario' => 'Annotare la manutenzione e tenere sotto controllo le scadenze.',
        ],
    ],

    /*
     * Le guide pubblicate, nell'ordine in cui vanno lette.
     *
     * `chiavi`: parole che il filtro di ricerca deve trovare anche quando non
     * compaiono nel testo — sinonimi e modi di dire di chi cerca («password»,
     * «buio», «notifiche»). Titolo, sottotitolo e passi sono già cercati per
     * conto loro.
     */
    'guide' => [
        ['slug' => 'primo-accesso', 'argomento' => 'A', 'chiavi' => ['invito', 'password', 'registrazione', 'email', 'accesso', 'login']],
        ['slug' => 'orientarsi', 'argomento' => 'A', 'chiavi' => ['dashboard', 'menù', 'semaforo', 'stati', 'obsoleti', 'campanella', 'notifiche']],
        ['slug' => 'tema-e-notifiche', 'argomento' => 'A', 'chiavi' => ['tema', 'scuro', 'chiaro', 'buio', 'preferenze', 'email', 'digest', 'avvisi']],
        ['slug' => 'cambiare-sede', 'argomento' => 'A', 'chiavi' => ['sede', 'sedi', 'switcher', 'multi-sede', 'contratto', 'account']],
        ['slug' => 'intervento', 'argomento' => 'D', 'chiavi' => ['intervento', 'manutenzione', 'taratura', 'scadenza', 'assegnatario', 'tecnico']],
    ],

];

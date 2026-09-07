<?php

/**
 * Il manuale in applicazione: quali guide esistono e come sono raggruppate.
 *
 * ⚠️ **Qui non stanno i testi.** Titolo, sottotitolo, sequenza dei passi e
 * durata si leggono dal `manifest.json` che lo studio delle guide (`guide/`)
 * produce insieme al video; la **prosa** dei passi scritti sta in
 * `guide/testi/{slug}.md` (🔗 `App\Support\Guide\TestiScritti`, che spiega
 * perché sono due testi e non uno). Questo file aggiunge solo ciò che né l'uno
 * né l'altro sanno: a quale argomento appartiene una guida, e con quali parole
 * la si cerca.
 *
 * L'elenco completo delle guide previste — comprese quelle non ancora girate —
 * sta in `guide/CATALOGO.md`. Una guida compare qui **quando il suo mp4 esiste**
 * in `public/guide/<slug>/`, cioè dopo `guide/bin/pubblica.sh`.
 */
return [

    /*
     * Dove stanno video, copertine e manifest.
     *
     * ⚠️ **Non `public/`**: staging e produzione girano su Laravel Cloud, che
     * costruisce l'immagine da git — ciò che non è nel repository non esiste, e
     * 64 MB di mp4 (600 a catalogo completo) in git non ci vanno: i binari non
     * si comprimono per differenze, e ogni rifacimento di una guida lascia la
     * sua copia nella storia per sempre.
     *
     * ⚠️ **Si nomina un disco già esistente, non se ne configura uno nuovo con
     * le chiavi.** Su Laravel Cloud le credenziali di un bucket NON arrivano
     * come `AWS_*`: la piattaforma inietta `LARAVEL_CLOUD_DISK_CONFIG`, e
     * `Illuminate\Foundation\Cloud::configureDisks()` al boot registra il
     * disco col nome del bucket e imposta `FILESYSTEM_DISK`. Un disco scritto a
     * mano con `env('AWS_ACCESS_KEY_ID')` nascerebbe quindi senza credenziali
     * in cloud, e funzionerebbe solo in locale.
     *
     * Il default segue il disco dell'ambiente: `local` in sviluppo, il bucket
     * attaccato in cloud. `GUIDE_DISK` serve solo per puntare altrove.
     *
     * (Un disco `scoped` sarebbe stato più elegante, ma vuole
     * `league/flysystem-path-prefixing`: una dipendenza di produzione in più
     * per risparmiare una concatenazione non vale il suo peso.)
     */
    'disco' => env('GUIDE_DISK', env('FILESYSTEM_DISK', 'local')),
    'prefisso' => env('GUIDE_DISK_PREFISSO', 'guide'),

    // Le lettere sono quelle di `guide/CATALOGO.md`: restano allineate perché
    // chi produce una guida legge quel file e ritrova qui lo stesso argomento.
    'argomenti' => [
        'A' => [
            'titolo' => 'Primi passi',
            'sommario' => 'Entrare, orientarsi, e sistemare le proprie preferenze.',
        ],
        'B' => [
            'titolo' => 'Anagrafica',
            'sommario' => 'La struttura della sede, e il marchio con cui escono le email.',
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
        ['slug' => 'due-fattori', 'argomento' => 'A', 'chiavi' => ['2fa', 'due fattori', 'sicurezza', 'codice', 'autenticazione', 'recupero', 'telefono', 'password']],
        ['slug' => 'albero-anagrafica', 'argomento' => 'B', 'chiavi' => ['albero', 'struttura', 'dipartimento', 'laboratorio', 'sede', 'briciole', 'dove', 'reparto']],
        ['slug' => 'creare-nodi', 'argomento' => 'B', 'chiavi' => ['creare', 'aggiungere', 'rinominare', 'dipartimento', 'laboratorio', 'reparto', 'eliminare', 'struttura']],
        ['slug' => 'marchio-ente', 'argomento' => 'B', 'chiavi' => ['marchio', 'logo', 'colore', 'email', 'testata', 'brand', 'personalizzare']],
        ['slug' => 'cambiare-sede', 'argomento' => 'A', 'chiavi' => ['sede', 'sedi', 'switcher', 'multi-sede', 'contratto', 'account']],
        ['slug' => 'intervento', 'argomento' => 'D', 'chiavi' => ['intervento', 'manutenzione', 'taratura', 'scadenza', 'assegnatario', 'tecnico']],
    ],

];

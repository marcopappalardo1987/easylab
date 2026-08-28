@php
    /**
     * La tabella nella parte TESTO — il secondo difetto che ADR-011 elenca.
     *
     * Il pacchetto rende `{{ $slot }}` e basta, quindi il corpo testuale del
     * digest arrivava con il markdown grezzo dentro: una riga `|:---------|:---|`
     * e ogni riga piena di pipe. Chi legge in solo testo — o chi ha un client
     * che preferisce il `text/plain` — vedeva la sorgente della tabella, non la
     * tabella.
     *
     * Tre passaggi, nell'ordine:
     *  1. i link markdown diventano «etichetta — url»: `strip_tags` non li
     *     tocca, perché non sono HTML, e senza questo passaggio il nome della
     *     macchina resterebbe fra parentesi quadre;
     *  2. le righe di separazione (`|:---|:---|`) si buttano: non dicono niente
     *     a chi legge testo;
     *  3. le pipe restanti diventano ` · `, e quelle esterne spariscono.
     *
     * Risultato: «Agitatore 2358 — https://… · Intervento · 12/09/2026».
     */
    $righe = [];

    foreach (preg_split('/\R/', (string) $slot) as $riga) {
        // 1. `[etichetta](url)` → `etichetta — url`
        $riga = preg_replace('/\[([^\]]*)\]\(([^)]*)\)/', '$1 — $2', $riga);

        if (trim($riga) === '') {
            continue;
        }

        // 2. la riga di separazione dell'intestazione.
        if (preg_match('/^\s*\|?[\s:|-]+\|?\s*$/', $riga) === 1) {
            continue;
        }

        // 3. le pipe esterne via, quelle interne diventano un separatore medio.
        $riga = trim($riga);
        $riga = trim($riga, '|');
        $riga = preg_replace('/\s*\|\s*/', ' · ', $riga);

        $righe[] = trim($riga);
    }
@endphp
{!! implode("\n", $righe) !!}

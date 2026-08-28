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
     * Due passaggi, nell'ordine:
     *  1. le righe di separazione (`|:---|:---|`) si buttano: non dicono niente
     *     a chi legge testo;
     *  2. le pipe restanti diventano ` · `, e quelle esterne spariscono.
     *
     * ⚠️ **I link markdown NON si convertono qui**: lo fa
     * `TestoEmail::senzaMarkdown()` dal layout, che è il solo punto da cui passa
     * tutto il corpo — la conversione che viveva in questo file copriva le righe
     * della tabella e lasciava grezzo il markdown del resto dell'email, che è
     * precisamente il difetto per cui è stata spostata.
     *
     * Risultato: «Agitatore 2358 — https://… · Intervento · 12/09/2026».
     */
    $righe = [];

    foreach (preg_split('/\R/', (string) $slot) as $riga) {
        if (trim($riga) === '') {
            continue;
        }

        // 1. la riga di separazione dell'intestazione.
        if (preg_match('/^\s*\|?[\s:|-]+\|?\s*$/', $riga) === 1) {
            continue;
        }

        // 2. le pipe esterne via, quelle interne diventano un separatore medio.
        $riga = trim($riga);
        $riga = trim($riga, '|');
        $riga = preg_replace('/\s*\|\s*/', ' · ', $riga);

        $righe[] = trim($riga);
    }
@endphp
{!! implode("\n", $righe) !!}

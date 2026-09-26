<?php

namespace App\Support\Piattaforma;

/**
 * I quattro andamenti della cabina di regia, calcolati in una passata sola
 * (S6 — Wireframe §4; 🔗 ADR-018, ADR-034).
 *
 * DTO immutabile come `RiepilogoPiattaforma`, e per la stessa ragione: le serie
 * nascono dagli stessi tredici confini di mese, quindi restano d'accordo fra
 * loro — `nuoviClienti` è letteralmente la differenza prima di
 * `clientiCumulati`, e se le due si calcolassero a chiamate separate potrebbero
 * cadere ai due lati della mezzanotte del primo del mese.
 *
 * 🔴 **Il cumulato è «i clienti di OGGI per data d'ingresso», non «i clienti
 * attivi allora».** Un account cestinato sparisce retroattivamente da tutta la
 * serie, perché `VistaPiattaforma::accounts()` tiene il `SoftDeletingScope` — e
 * giustamente: senza, i KPI conterebbero righe che nessuno ha più. La curva
 * quindi non sale e scende: **sale e basta**, e il churn è invisibile. Non è un
 * difetto da correggere qui (servirebbe una tabella di snapshot giornalieri,
 * uno scheduler e un backfill): è un limite da **dire in pagina**, come già si
 * dice «I quattro numeri sono totali di piattaforma: non cambiano con i filtri
 * qui sotto».
 *
 * **Il ricavo non ha un andamento, ed è una decisione.** `accounts.piano` è lo
 * stato di oggi: ricostruire l'MRR di sei mesi fa applicando il piano attuale
 * alle date di ingresso darebbe una curva plausibile e falsa — esattamente la
 * cifra che «nessuno verifica proprio perché è plausibile». Al suo posto la
 * pagina mostra la **composizione per piano al presente**, che è vera. Farlo per
 * davvero vuole uno storico degli abbonamenti che non c'è.
 */
final class AndamentoPiattaforma
{
    /**
     * La mappa piano → colore e glifo delle serie, **per posizione nel catalogo**.
     *
     * ⛔ **Stringhe letterali e complete, mai composte.** `'bg-chart-'.$codice`
     * non genera nulla: il compilatore di Tailwind fa una scansione testuale del
     * sorgente e non valuta PHP. Il sintomo sarebbe un segmento di barra
     * invisibile su una pagina che risponde 200 — la forma di guasto che questo
     * progetto ha già pagato più volte sui colori.
     *
     * ⚠️ **Il glifo non è decorazione.** In tema scuro la coppia peggiore —
     * verde ↔ arancione — scende a ΔE 6,9 (DS §2.5), sotto la soglia di
     * sicurezza: una legenda a soli quadratini colorati sarebbe un **bug**, non
     * uno stile. È la stessa clausola con cui `x-ui.semaforo` accetta quel ΔE:
     * colore **+ forma + etichetta**, sempre tutti e tre.
     *
     * Se un giorno il catalogo superasse quattro piani la mappa **cicla** e due
     * piani condividono un colore. È accettabile solo perché ogni voce porta
     * glifo ed etichetta — cioè per la clausola qui sopra, non per distrazione.
     *
     * @var list<array{0: string, 1: string}> `[classe di fondo, glifo]`
     */
    public const COLORI_PIANO = [
        ['bg-chart-brand', '●'],
        ['bg-chart-verde', '◆'],
        ['bg-chart-arancione', '▲'],
        ['bg-chart-obsoleto', '★'],
    ];

    /**
     * Il colore riservato in modo fisso alla voce «fuori catalogo».
     *
     * Non entra nel ciclo: nella cabina un piano dismesso è già un'anomalia
     * segnalata da una card ambra, e dargli il rosso in ogni installazione — non
     * «il colore che gli tocca in questa» — è ciò che lo rende riconoscibile a
     * colpo d'occhio da chi passa da un cliente all'altro.
     */
    public const COLORE_FUORI_CATALOGO = ['bg-chart-rosso', '■'];

    public function __construct(
        public readonly SerieMensile $nuoviClienti,
        public readonly SerieMensile $clientiCumulati,
        public readonly SerieMensile $sediCumulate,
        public readonly SerieMensile $strumentiCumulati,
    ) {}

    /**
     * Il colore e il glifo dell'i-esimo piano a catalogo.
     *
     * @return array{0: string, 1: string}
     */
    public static function coloreDelPiano(int $posizione): array
    {
        return self::COLORI_PIANO[$posizione % count(self::COLORI_PIANO)];
    }
}

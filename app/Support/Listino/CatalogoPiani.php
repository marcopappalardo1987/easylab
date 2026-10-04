<?php

namespace App\Support\Listino;

use App\Models\Piano;

/**
 * Il listino letto **una volta per richiesta** (🔗 ADR-035).
 *
 * ⛔ **Nessun `Cache::`, e non è un'ottimizzazione mancata: è la trappola più
 * costosa di questa feature.** Redis è **condiviso** fra il database di
 * sviluppo `easylab` e quello di test `easylab_test` (CLAUDE.md), ed è già
 * costato un'app di sviluppo che negava tutto a tutti per via della chiave
 * `spatie.permission.cache`. Un `Cache::rememberForever('listino')` rifarebbe
 * lo stesso incidente **su una somma di denaro**: gli id e i prezzi di un
 * database finirebbero letti dall'altro.
 *
 * Il memo è quindi **per-richiesta**, ottenuto registrando questa classe come
 * `singleton` in `AppServiceProvider::register()`. Il container si ricostruisce
 * a ogni richiesta e a ogni test, quindi non serve nessun reset globale in
 * `tests/Pest.php` e non esiste nessuna chiave condivisa da invalidare.
 *
 * ⚠️ **Perché il memo serve davvero.** `App\Support\Piani` è consumato dentro
 * un ciclo Blade in `cabina.blade.php` (`esiste()`, `maxEnti()`, `etichetta()`
 * per ogni cliente in pagina) e in `RiepilogoPiattaforma`: senza memo sarebbero
 * N query per pagina, con memo è **una**. I test di costo esistenti filtrano
 * per nome di tabella e non vedrebbero una query su `piani`, quindi non sono
 * una rete: il conteggio è congelato a mano da un test proprio.
 *
 * ⚠️ **Il memo va dimenticato dopo ogni scrittura, nella stessa richiesta.**
 * Senza, il click che cambia il prezzo rende una pagina che mostra ancora il
 * vecchio — e la prossima persona penserà che il salvataggio non abbia
 * funzionato e cliccherà di nuovo, cioè fabbricherà a mano il doppio invio
 * contro cui esiste l'idempotenza.
 */
final class CatalogoPiani
{
    /** @var array<string, Piano>|null codice → piano, nell'ordine del listino */
    private ?array $perCodice = null;

    /** @var array<string, string>|null price id (anche STORICO) → codice del piano */
    private ?array $perPrice = null;

    /** @var array<string, int>|null price id (anche STORICO) → importo in centesimi */
    private ?array $importoPerPrice = null;

    /**
     * Tutti i piani, **archiviati compresi**, nell'ordine `ordine, id`.
     *
     * ⚠️ Il tie-break sull'`id` non è pignoleria: a parità di chiave di
     * ordinamento l'ordine fra due righe è una **proprietà del motore**, e
     * SQLite e Postgres non concordano (CLAUDE.md, 25 Ago 2026). Senza,
     * l'ordine delle colonne dell'MRR cambierebbe fra locale e CI.
     *
     * @return array<string, Piano>
     */
    public function tutti(): array
    {
        $this->carica();

        return $this->perCodice;
    }

    public function perCodice(string $codice): ?Piano
    {
        $this->carica();

        return $this->perCodice[$codice] ?? null;
    }

    /**
     * Il piano a cui appartiene un price id, **anche se quel price è storico**.
     *
     * 🔴 È la ragione per cui `prezzi_piano` esiste. Guardando il solo price
     * corrente, al primo cambio di listino ogni cliente già abbonato tornerebbe
     * `null` e il webhook smetterebbe di riallineare il suo piano — in
     * silenzio, perché `null` è un esito legittimo che il controller tratta
     * come tale.
     */
    public function codicePerPrice(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $this->carica();

        return $this->perPrice[$priceId] ?? null;
    }

    /**
     * Quanto addebita un price id, **anche se storico**, o `null` se non è a
     * listino (🔗 ADR-045).
     *
     * Serve a sapere quanto paga chi è rimasto su un prezzo vecchio: è la
     * soglia sotto la quale un cambio di piano sarebbe un passo indietro. Come
     * per `codicePerPrice()`, `null` è un esito legittimo — un price creato a
     * mano in dashboard — e chi chiama decide cosa farne.
     */
    public function importoPerPrice(?string $priceId): ?int
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $this->carica();

        return $this->importoPerPrice[$priceId] ?? null;
    }

    /** Svuota il memo. Da chiamare dopo ogni scrittura, nella stessa richiesta. */
    public function dimentica(): void
    {
        $this->perCodice = null;
        $this->perPrice = null;
        $this->importoPerPrice = null;
    }

    private function carica(): void
    {
        if ($this->perCodice !== null) {
            return;
        }

        // `with('prezzi')` e non due query separate: la mappa dei price si
        // costruisce su **tutte** le righe, correnti e storiche, e il consumo
        // tipico (una pagina di piattaforma) le vuole entrambe.
        $piani = Piano::query()->with('prezzi')->orderBy('ordine')->orderBy('id')->get();

        $perCodice = [];
        $perPrice = [];
        $importoPerPrice = [];

        foreach ($piani as $piano) {
            $perCodice[$piano->codice] = $piano;

            foreach ($piano->prezzi as $prezzo) {
                $perPrice[$prezzo->stripe_price_id] = $piano->codice;
                $importoPerPrice[$prezzo->stripe_price_id] = (int) $prezzo->importo_cent;
            }
        }

        $this->perCodice = $perCodice;
        $this->perPrice = $perPrice;
        $this->importoPerPrice = $importoPerPrice;
    }
}

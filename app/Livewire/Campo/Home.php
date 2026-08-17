<?php

namespace App\Livewire\Campo;

use App\Enums\StatoIntervento;
use App\Models\Intervento;
use App\Models\Strumento;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Home della vista di campo: «inquadra il QR» + «i miei interventi»
 * (🔗 ADR-003/007/030 — Wireframe §3, schermata di sinistra — S4 blocco 10).
 *
 * ⚠️ **È l'UNICA schermata nuova del blocco, ed è deliberato.** Il wireframe §3
 * disegna due schermate, ma quella di destra — macchina, intervento assegnato,
 * segna fatto, aggiungi ricambio, storico, allega — *è* la scheda strumento
 * vista da un telefono, e §1/§2/§5 prevedevano già il responsive di quelle
 * pagine. Costruirne una seconda versione avrebbe contraddetto ADR-003, che
 * esiste per affermare che la scheda vive in un posto solo con una sola catena
 * di autorizzazione: due rese della stessa macchina sono due occasioni di
 * sbagliare i permessi. Qui c'è invece ciò che prima non esisteva da nessuna
 * parte — un punto di partenza per chi arriva col telefono e non sa da dove
 * cominciare.
 *
 * Per la stessa ragione `/q/{token}` continua a portare **tutti** su
 * `strumenti.show`, senza diramare per ruolo.
 *
 * **Nessuna query nuova sugli accessi**: l'elenco parte da `Intervento`, quindi
 * eredita `TenantScope` (con il criterio Tecnico di ADR-030), il livello 2 del
 * Responsabile e il soft delete. Questa pagina non decide chi vede cosa: lo
 * chiede al modello, come tutto il resto del progetto.
 */
#[Layout('components.layouts.app')]
class Home extends Component
{
    /**
     * Tetto della lista.
     *
     * Non è una paginazione: sul campo si guarda cosa fare ADESSO, e chi ha
     * centoquaranta interventi aperti non li scorre col pollice — usa il QR
     * sulla macchina davanti a sé, che è l'altro ingresso di questa pagina.
     * Il numero totale resta scritto in testa e il troncamento si DICHIARA:
     * una lista tagliata in silenzio si legge come una lista completa, ed è lo
     * stesso principio del tetto della ricerca ricambi.
     */
    public const MAX_INTERVENTI = 25;

    /**
     * Interventi aperti assegnati a chi guarda, i più urgenti in cima.
     *
     * `tecnico_id = auth()->id()` e non «gli interventi che posso vedere»: la
     * pagina si chiama «i miei interventi» e un Responsabile che li vedesse
     * tutti si troverebbe una lista che non è la sua. Chi non ne ha nessuno
     * vede una pagina vuota che lo dice — ed è corretto, perché è la situazione
     * di un Admin, che sul campo non ci va.
     *
     * Gli **scaduti restano in cima insieme agli altri**, ordinati per data:
     * separarli in due elenchi avrebbe raddoppiato la lettura senza aggiungere
     * nulla, visto che ogni riga porta già la propria data e il proprio stato.
     *
     * @return Collection<int, Intervento>
     */
    protected function mieiInterventi(): Collection
    {
        return Intervento::query()
            ->where('tecnico_id', auth()->id())
            ->where('stato', StatoIntervento::NonFatto->value)
            // `strumento` per nome e ubicazione: senza eager loading sarebbe una
            // query per riga, e questa lista è pensata per essere lunga.
            ->with('strumento')
            ->orderBy('data_scadenza')
            ->orderBy('id')
            ->get();
    }

    public function render()
    {
        $tutti = $this->mieiInterventi();
        $interventi = $tutti->take(self::MAX_INTERVENTI);

        return view('livewire.campo.home', [
            'totale' => $tutti->count(),
            'interventi' => $interventi,
            // Una query sola per tutte le ubicazioni: la risalita ne costava
            // DUE per riga (dipartimento + Ente), e un test lo congela.
            'nodi' => Strumento::mappaUbicazioni($interventi->pluck('strumento')->filter()),
        ]);
    }
}

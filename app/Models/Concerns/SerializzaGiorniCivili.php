<?php

namespace App\Models\Concerns;

use DateTimeInterface;

/**
 * Rende i **giorni civili** come giorni, invece che come istanti UTC.
 *
 * 🔗 ADR-041. Riguarda le nove colonne castate `date` del progetto
 * (`data_scadenza`, `data_esecuzione`, `data_installazione`, `data_inizio`,
 * `data_scadenza_dichiarata`, `data_scadenza_effettiva`,
 * `spostamenti_strumento.data`, `ricambio_utilizzo.data`,
 * `avvisi_scadenza.data_scadenza`): non sono momenti nel tempo, sono date sul
 * calendario, e non hanno un fuso in cui essere convertite.
 *
 * ## Il difetto che chiude, trovato dalla suite e non a mano
 *
 * `serializeDate()` di Eloquent chiama `toJSON()`, che converte a UTC. Finché
 * l'app stava su UTC la conversione era l'identità e non si notava; da quando
 * sta su `Europe/Rome` una mezzanotte italiana torna indietro di due ore, cioè
 * **cambia giorno**: il 6 settembre diventa `2026-09-05T22:00:00Z`.
 *
 * 🔴 Il valore in tabella resta giusto — a sbagliare è ogni rappresentazione
 * che passi da `toArray()`. La prima è il **registro di audit**, che
 * 🔗 ADR-027 dichiara non riscrivibile: `AuditInterventiTest` ha visto una data
 * di esecuzione registrata al giorno prima di quello in cui il lavoro è stato
 * chiuso. Su una tabella che nessuno può correggere, un giorno sbagliato resta
 * sbagliato per sempre.
 *
 * ## Perché qui e non con un cast `date:Y-m-d`
 *
 * ⛔ È la prima strada che ho tentato, e **rompe la scrittura**: per Eloquent
 * `date:Y-m-d` è un `custom_datetime`, non un `date` (`getCastType()`), quindi
 * `isDateAttribute()` diventa falso e `fromDateTime()` non normalizza più il
 * valore verso il formato del driver. Su SQLite `avvisi_scadenza.data_scadenza`
 * continuava a scriversi `'Y-m-d 00:00:00'` mentre `interventi.data_scadenza`
 * passava a `'Y-m-d`': le due forme non sono uguali per il `whereColumn`
 * dell'idempotenza, quindi il digest **non riconosceva più i propri avvisi e
 * avrebbe rimandato tutto ogni giorno**. È esattamente la trappola già
 * descritta in `NotificaScadenze::registra()`, ripresentata da un'altra porta.
 *
 * Questo trait tocca la **sola serializzazione** e lascia scrittura, cast e
 * confronti dove sono.
 */
trait SerializzaGiorniCivili
{
    private const FORMATO_GIORNO = 'Y-m-d';

    /**
     * I due percorsi da correggere sono `attributesToArray()` — che serve ogni
     * `toArray()`/`toJson()` — e `serializeDate()`, che l'activity log di
     * spatie chiama **direttamente** su ogni attributo di data
     * (`LogsActivity::formatAttributeValue()`). Sono due, quindi si coprono
     * entrambi: correggerne uno solo lascerebbe il difetto proprio nel registro
     * di audit, che è il posto in cui costa di più.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributi = parent::attributesToArray();

        foreach ($this->giorniCivili() as $colonna) {
            if (! array_key_exists($colonna, $attributi)) {
                continue;
            }

            $valore = $this->getAttribute($colonna);

            if ($valore instanceof DateTimeInterface) {
                $attributi[$colonna] = $valore->format(self::FORMATO_GIORNO);
            }
        }

        return $attributi;
    }

    /**
     * ⚠️ **Qui il nome della colonna NON arriva**: la firma porta solo la data,
     * quindi non si può distinguere un giorno da un istante guardando il
     * parametro. Si riconosce invece dal **valore già letto sul modello**: se
     * l'istante coincide con quello di una delle colonne-giorno, è quella.
     *
     * ⛔ Non si usa «è mezzanotte» come indizio, che sarebbe un'euristica:
     * un `created_at` scritto alle 00:00:00 esatte verrebbe scambiato per un
     * giorno e perderebbe l'ora. Il confronto è con le colonne dichiarate.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        foreach ($this->giorniCivili() as $colonna) {
            $valore = $this->getAttribute($colonna);

            if ($valore instanceof DateTimeInterface
                && $valore->getTimestamp() === $date->getTimestamp()
                && $valore->format('T') === $date->format('T')
            ) {
                return $date->format(self::FORMATO_GIORNO);
            }
        }

        return parent::serializeDate($date);
    }

    /**
     * Le colonne di questo modello castate `date` nudo.
     *
     * `datetime` resta fuori: è un istante, e va convertito come sempre.
     *
     * @return list<string>
     */
    private function giorniCivili(): array
    {
        return array_keys(array_filter(
            $this->getCasts(),
            fn (string $tipo): bool => $tipo === 'date',
        ));
    }
}

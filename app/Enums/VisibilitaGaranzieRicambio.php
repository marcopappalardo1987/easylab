<?php

namespace App\Enums;

/**
 * Quanto il ruolo `Tenant` di un Ente vede delle garanzie dei pezzi montati
 * sulle proprie macchine (ADR-029). È una clausola del rapporto commerciale fra
 * EasyLab e quell'Ente, non una preferenza interna: la governa il Superadmin.
 *
 * **Il default è `Modifica`**, ed è l'inversione consapevole della postura
 * precedente (ADR-004: mai al Tenant): chi paga l'abbonamento possiede i propri
 * dati, e l'eccezione va giustificata da chi la impone.
 *
 * I tre stati non sono una scala di comodo, sono tre casi reali:
 * - `Modifica` — il laboratorio compra e gestisce i propri ricambi;
 * - `Lettura` — li registra EasyLab, ma il cliente ha diritto di vederli;
 * - `Nascosta` — full service, dove la copertura dei pezzi è informazione del
 *   fornitore. È il caso che ha originato ADR-004, e senza questo terzo stato
 *   non sarebbe più rappresentabile.
 *
 * ⚠️ `Lettura` e `Nascosta` agiscono su piani DIVERSI e non vanno confusi:
 * `Nascosta` toglie le righe (global scope), `Lettura` toglie la scrittura
 * (Policy). Nascondere una riga per negarne la modifica renderebbe il tab
 * Ricambi incomprensibile — una riga mancante e un pulsante assente si leggono
 * in modo diverso.
 */
enum VisibilitaGaranzieRicambio: string
{
    case Nascosta = 'nascosta';
    case Lettura = 'lettura';
    case Modifica = 'modifica';

    /** Etichetta UI: unica fonte, come per TipoIntervento (ADR-021). */
    public function label(): string
    {
        return match ($this) {
            self::Nascosta => 'Nascoste',
            self::Lettura => 'Sola lettura',
            self::Modifica => 'Lettura e modifica',
        };
    }

    /** Testo di aiuto del form: dice l'effetto, non il nome dello stato. */
    public function descrizione(): string
    {
        return match ($this) {
            self::Nascosta => 'Il Tenant non vede le garanzie dei pezzi montati; il semaforo le conta comunque (ADR-020).',
            self::Lettura => 'Il Tenant vede le garanzie dei pezzi, ma non può modificarle.',
            self::Modifica => 'Il Tenant vede e gestisce le garanzie dei pezzi montati sulle proprie macchine.',
        };
    }
}

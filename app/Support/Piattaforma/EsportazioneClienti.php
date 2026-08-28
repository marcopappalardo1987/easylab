<?php

namespace App\Support\Piattaforma;

use App\Models\Account;
use App\Support\Piani;
use Illuminate\Support\Collection;

/**
 * 🔴 La matrice dell'esportazione clienti: **una sola**, per il CSV e per il PDF.
 *
 * Esiste come classe e non come due generatori perché due generatori divergono,
 * e il giorno in cui divergono nessuno se ne accorge — nessuno apre i due file
 * uno accanto all'altro. Qui le colonne si decidono una volta: chi ne aggiunge
 * una la aggiunge a entrambi i formati per costruzione.
 *
 * 🔴 **Qui vive il perimetro DI COLONNA dell'export.** Il perimetro di *riga* è
 * altrove (`ElencaClienti::queryClienti()`, che passa da
 * `App\Support\Tenancy\VistaPiattaforma`): questa classe risponde all'altra
 * metà della stessa domanda di privacy — «di ciò che è nella riga, cosa esce
 * dall'applicazione?». La regola è **la tabella a schermo**: ragione sociale,
 * P.IVA, piano, le due sorgenti di lockout, sedi/max, strumenti e «cliente dal».
 *
 * ⛔ Cosa NON entra, e perché:
 *  · `pec`, `codice_destinatario_sdi`, `codice_fiscale` — stanno dietro la
 *    modale `apriFiscali()`, gatata da `@can('manage', $cliente)`
 *    (`AccountPolicy`). A schermo sono dietro un secondo permesso; un file non
 *    ha modo di degradare per permesso una volta uscito.
 *  · `locked_reason` e `stripe_lock_reason` — ADR-013 le dichiara **annotazione
 *    interna**: non sono mostrate nemmeno al cliente su `/bloccato`.
 *  · `stripe_id` — identificativo di un sistema terzo, inutile su un foglio e
 *    sufficiente a correlare due esportazioni fra loro.
 *  · i **membri** e le loro email — dati personali di persone che non sono
 *    l'oggetto di questo elenco (ADR-020, minimizzazione).
 *
 * ⚠️ **Le due sorgenti di lockout restano DUE colonne** (`bloccato_a_mano`,
 * `bloccato_per_insoluto`) accanto al `bloccato` riassuntivo. Fonderle nel file
 * rifarebbe il difetto che ADR-013 esiste per impedire: uno sblocco manuale
 * deciso guardando un foglio che dice solo «bloccato» riaprirebbe un contenzioso
 * a un pagamento riuscito. È la stessa scelta che la tabella fa coi due badge.
 *
 * ⛔ **Un piano fuori catalogo non deve far esplodere l'export.**
 * `Account::valoreMensileCent()` chiama `Piani::prezzoMensileCent()`, che
 * **lancia** su un piano che non è più a listino — e la cabina offre apposta il
 * filtro `Cabina::FUORI_CATALOGO` per *trovare* quelle righe. Esportare senza
 * guardia manderebbe in 500 esattamente l'unico filtro che esiste per riparare
 * il dato. Si fa come `MetrichePiattaforma`: `Piani::esiste()` prima, 0 € dopo.
 *
 * ⚠️ Sulla colonna `sedi_max`, `?` e `illimitato` restano **distinti**: `null`
 * da `Piani::maxEnti()` significa «illimitato», l'assenza dal listino significa
 * «non lo sappiamo». Fonderli farebbe leggere il caso corrotto come il più
 * permissivo dei due, proprio sulla riga che la pagina invita a riparare — è la
 * stessa nota che sta nel blade della cabina.
 *
 * Classe di supporto, non modello: `TenantScopeGuardrailTest` non la riguarda e
 * `BelongsToTenant` non c'entra nulla (ADR-032 — `Account` è di piattaforma).
 *
 * 🔗 ERD §1 (accounts), ADR-013 (le due sorgenti di lockout), ADR-031 (si
 * esporta ciò che si vede a schermo), ADR-032 (Account e piani), ADR-035 (il
 * listino a database).
 */
final class EsportazioneClienti
{
    /**
     * Le intestazioni, nello stesso ordine delle celle.
     *
     * In `snake_case` e non in prosa: questo file si riapre in un foglio di
     * calcolo e spesso si ri-importa, e un'intestazione con accenti e spazi è
     * la prima cosa che si rompe nel giro di andata e ritorno.
     *
     * @return list<string>
     */
    public static function intestazioni(): array
    {
        return [
            'ragione_sociale',
            'partita_iva',
            'piano',
            'piano_etichetta',
            'a_catalogo',
            'valore_mensile_eur',
            'bloccato',
            'bloccato_a_mano',
            'bloccato_per_insoluto',
            'sedi',
            'sedi_max',
            'strumenti',
            'cliente_dal',
        ];
    }

    /**
     * Una riga per cliente, nell'ordine in cui la collezione arriva.
     *
     * ⚠️ **L'ordine non si ricostruisce qui**: arriva già dalla query della
     * pagina, tie-break sull'`id` compreso. Riordinare in PHP significherebbe
     * avere due definizioni dell'ordine, e su Postgres i pari si riordinano
     * fra una query e l'altra (CLAUDE.md, 25 Ago 2026).
     *
     * @param  Collection<int,Account>  $clienti
     * @param  array<int,int>  $sediPerAccount  account_id → numero di sedi
     * @param  array<int,int>  $strumentiPerAccount  account_id → numero di macchine
     * @return list<list<string>>
     */
    public static function righe(Collection $clienti, array $sediPerAccount, array $strumentiPerAccount): array
    {
        return $clienti->map(function (Account $c) use ($sediPerAccount, $strumentiPerAccount): array {
            $aCatalogo = Piani::esiste($c->piano);

            return [
                (string) $c->ragione_sociale,
                (string) ($c->partita_iva ?? ''),
                (string) $c->piano,
                $aCatalogo ? Piani::etichetta($c->piano) : $c->piano.' — fuori catalogo',
                self::siNo($aCatalogo),
                (string) self::valoreMensileEuro($c),
                self::siNo((bool) $c->is_locked),
                self::siNo($c->locked_at !== null),
                self::siNo($c->stripe_locked_at !== null),
                (string) ($sediPerAccount[$c->id] ?? 0),
                self::sediMax($c, $aCatalogo),
                (string) ($strumentiPerAccount[$c->id] ?? 0),
                $c->created_at?->format('d/m/Y') ?? '',
            ];
        })->values()->all();
    }

    /**
     * Il totale a **listino** delle sole righe passate, in centesimi.
     *
     * ⚠️ È il totale del *filtrato*, e sul foglio va etichettato come tale: i
     * quattro KPI della cabina sono totali di piattaforma e non seguono i
     * filtri. Metterli accanto senza dirlo farebbe mentire il foglio per
     * accostamento.
     *
     * Centesimi interi come tutto il resto del progetto: una somma di denaro in
     * virgola mobile su decine di clienti accumula errore, e nessuno rilegge la
     * riga che lo fa.
     *
     * @param  Collection<int,Account>  $clienti
     */
    public static function totaleListinoCent(Collection $clienti): int
    {
        return $clienti->sum(
            fn (Account $c) => Piani::esiste($c->piano) ? Piani::prezzoMensileCent($c->piano) : 0
        );
    }

    /**
     * ⛔ `Piani::esiste()` **prima**: vedi il docblock di classe. Mai
     * `$c->valoreMensileCent()`, che lancia sul piano fuori catalogo.
     */
    private static function valoreMensileEuro(Account $c): int
    {
        return Piani::esiste($c->piano) ? intdiv(Piani::prezzoMensileCent($c->piano), 100) : 0;
    }

    /** ⛔ `?` (non lo sappiamo) e `illimitato` restano distinti: vedi il docblock di classe. */
    private static function sediMax(Account $c, bool $aCatalogo): string
    {
        if (! $aCatalogo) {
            return '?';
        }

        $max = Piani::maxEnti($c->piano);

        return $max === null ? 'illimitato' : (string) $max;
    }

    /**
     * I booleani si scrivono per **parola** e non come `1`/`0`.
     *
     * Un foglio di calcolo mostra `0` e la cella vuota allo stesso modo a colpo
     * d'occhio, e questo file si legge per decidere se sbloccare un cliente. È
     * lo stesso principio per cui il PDF dello storico distingue «fatto» da «da
     * fare» con una parola invece che con un colore.
     */
    private static function siNo(bool $valore): string
    {
        return $valore ? 'sì' : 'no';
    }
}

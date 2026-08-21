<?php

namespace App\Support\Piattaforma;

use App\Enums\TipoUnitaOrganizzativa;
use App\Support\Piani;
use App\Support\Tenancy\VistaPiattaforma;

/**
 * I quattro numeri della cabina di regia, in **tre query costanti** (S6).
 *
 * Esiste come classe e non come metodo del componente Livewire per la stessa
 * ragione per cui esiste `VistaPiattaforma`: l'unica strada per sommare denaro
 * dev'essere una, e dev'essere quella protetta. L'API più comoda —
 * `->sum(fn ($a) => $a->valoreMensileCent())` — è anche quella che carica ogni
 * account in memoria e che **esplode** su un piano fuori catalogo; scriverla
 * qui una volta impedisce che venga scritta lì ogni volta.
 *
 * ⚠️ **Un `accounts.piano` fuori catalogo non deve uccidere questa pagina.**
 * `Piani::prezzoMensileCent()` lancia su un piano che non conosce — ed è giusto
 * in console, dove un operatore legge l'errore e corregge. Qui no: questa è
 * l'**unica schermata da cui quel dato si ripara**, e morire proprio lì sarebbe
 * il modo peggiore di segnalarlo. Il caso non è teorico: `Piani::perPrice()`
 * documenta «un piano dismesso dal catalogo» come scenario legittimo, e basta
 * rinominare un codice perché ogni riga rimasta al vecchio diventi orfana.
 * Quindi si itera sui codici **a catalogo** e tutto il resto confluisce in
 * `pianiSconosciuti`, che vale 0 € e si mostra in pagina.
 */
final class MetrichePiattaforma
{
    public static function riepilogo(): RiepilogoPiattaforma
    {
        // Q1 — un solo raggruppamento per piano, sull'indice creato apposta su
        // `accounts.piano`. `accounts()` esclude già i cestinati (il
        // SoftDeletingScope resta applicato) ed EasyLab (`di_piattaforma`).
        //
        // ⚠️ **Gli alias non sono cosmetici.** Senza, la chiave del risultato è
        // `count` su Postgres e `count(*)` su SQLite: verde in locale, rosso in
        // CI — o, peggio, un MRR silenziosamente a zero. E `SUM(CASE WHEN …)` e
        // non `COUNT(*) FILTER (WHERE …)`, che esiste solo su Postgres.
        $righe = VistaPiattaforma::accounts()
            ->selectRaw('piano, count(*) as totale, sum(case when is_locked then 1 else 0 end) as bloccati')
            ->groupBy('piano')
            ->get();

        $perPiano = [];
        $mrrCent = 0;
        $clienti = 0;
        $bloccati = 0;
        $sconosciuti = 0;
        $mrrBloccatoCent = 0;

        foreach ($righe as $riga) {
            // Cast espliciti: Postgres restituisce gli aggregati come stringhe.
            $totale = (int) $riga->totale;
            $clienti += $totale;
            $bloccati += (int) $riga->bloccati;

            if (! Piani::esiste($riga->piano)) {
                $sconosciuti += $totale;

                continue;
            }

            $perPiano[$riga->piano] = $totale;

            $prezzo = Piani::prezzoMensileCent($riga->piano);
            $mrrCent += $totale * $prezzo;
            $mrrBloccatoCent += (int) $riga->bloccati * $prezzo;
        }

        // I piani a catalogo compaiono **tutti e nell'ordine del catalogo**,
        // anche a zero. Due ragioni, entrambe pagate: una riga che sparisce si
        // legge come «quel piano non esiste», e il Free può restare vuoto per
        // settimane; e l'ordine di un `GROUP BY` non è garantito, quindi senza
        // questo riordino la riga «2 SaaS · 1 Free» cambierebbe da un driver
        // all'altro — verde in locale, diversa in CI.
        $ordinati = [];

        foreach (Piani::codici() as $codice) {
            $ordinati[$codice] = $perPiano[$codice] ?? 0;
        }

        $perPiano = $ordinati;

        // ⚠️ **Sedi e strumenti si legano alla stessa nozione di «cliente» dei
        // due numeri sopra**, con una sottoquery sugli id degli account. Senza,
        // contavano anche l'Ente di EasyLab e le sue macchine: sul database di
        // sviluppo erano una sede di nessun cliente e **1.217 strumenti su
        // 5.105**, cioè il 24% di un numero etichettato «macchine di tutti i
        // clienti». La migration che ha creato `di_piattaforma` lo aveva scritto
        // per esteso — «falserebbe tutti e quattro i KPI» — e la prima stesura
        // di questo blocco ne aveva coperti due.
        //
        // Chiude gratis anche il caso opposto: un account **cestinato** lasciava
        // le proprie sedi e macchine nei totali per sempre, perché `Account` non
        // propaga il soft delete ai figli. La sottoquery passa da `accounts()`,
        // che il soft delete ce l'ha.
        //
        // Resta **una query per tabella**: la sottoquery è annidata, non una
        // chiamata in più.
        $clientiVeri = VistaPiattaforma::accounts()->select('accounts.id');

        $sediDeiClienti = VistaPiattaforma::enti()
            ->where('tipo', TipoUnitaOrganizzativa::Ente)
            ->whereIn('account_id', $clientiVeri);

        return new RiepilogoPiattaforma(
            mrrCent: $mrrCent,
            mrrBloccatoCent: $mrrBloccatoCent,
            clienti: $clienti,
            clientiBloccati: $bloccati,
            sedi: (clone $sediDeiClienti)->count(),
            // `strumenti.tenant_id` punta al nodo radice, che è quello che porta
            // `account_id`: la stessa definizione, un salto più in là.
            strumenti: VistaPiattaforma::strumenti()
                ->whereIn('tenant_id', $sediDeiClienti->select('unita_organizzativa.id'))
                ->count(),
            perPiano: $perPiano,
            pianiSconosciuti: $sconosciuti,
        );
    }
}

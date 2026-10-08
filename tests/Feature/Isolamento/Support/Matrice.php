<?php

namespace Tests\Feature\Isolamento\Support;

use App\Jobs\InviaAllertaErrore;
use App\Models\AvvisoScadenza;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Notifications\AvvisoObsolescenza;
use App\Notifications\BenvenutoRegistrazione;
use App\Notifications\DigestScadenze;
use App\Notifications\InvitoUtente;
use App\Notifications\PropostaPiano;
use App\Notifications\VerificaEmailRegistrazione;
use Illuminate\Database\Eloquent\Model;

/**
 * Matrice modello × superficie dell'isolamento multi-tenant (ADR-001), letta
 * da `InventarioIsolationTest`. Ogni cella è:
 *  - `t:<file sotto tests/Feature>::<descrizione esatta del test>`, un test
 *    cross-tenant NEGATIVO che esiste davvero (il meta-test lo verifica);
 *  - `na:<motivo>`, la superficie non esiste per quel modello.
 *
 * Copia leggibile con le motivazioni estese: ~/Desktop/easylab-s7/T2-matrice.md.
 */
final class Matrice
{
    public const SUPERFICI = [
        'elenco', 'dettaglio', 'modifica', 'eliminazione', 'export',
        'download', 'ricerca', 'contatori', 'email',
    ];

    private const PAGINA = 't:Isolamento/SuperficiElencoIsolationTest.php::never shows data of another Ente on a tenant page';

    private const FILTRO = 't:Isolamento/SuperficiElencoIsolationTest.php::never widens the scope through a filter carrying an id of another Ente';

    private const DETTAGLIO = 't:Isolamento/DettaglioIsolationTest.php::answers 404 to a detail route naming a row of another Ente';

    private const STORICO = 't:Isolamento/DettaglioIsolationTest.php::puts only the own history on the storico PDF';

    private const CRUSCOTTO = 't:Isolamento/DettaglioIsolationTest.php::counts only the machines of the own Ente on the dashboard';

    private const DIGEST = 't:Isolamento/CodeIsolationTest.php::delivers the digest of A with the brand and rows of A, whatever the context on the worker';

    private const HARNESS = 't:Isolamento/VettoriIsolationTest.php::blocks every query vector across tenants on every tenant model';

    /** @return array<class-string<Model>, array<string, string>> */
    public static function celle(): array
    {
        return [
            UnitaOrganizzativa::class => [
                'elenco' => self::PAGINA,
                'dettaglio' => 't:Isolamento/AzioniIsolationTest.php::refuses to open, edit, delete or add under a node of another Ente',
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::never creates a child node under a node of another Ente',
                'eliminazione' => 't:Isolamento/AzioniIsolationTest.php::refuses to open, edit, delete or add under a node of another Ente',
                'export' => self::STORICO,
                'download' => 'na:nessun file scaricabile appartiene a un nodo: logo del marchio servito solo dentro le email del proprio Ente',
                'ricerca' => self::FILTRO,
                'contatori' => 'na:nessun contatore di nodi nelle pagine del tenant; il conteggio sedi dello switcher è per account (SwitcherEnteTest)',
                'email' => 't:Isolamento/CodeIsolationTest.php::delivers an invitation queued by A with the brand of A, whatever the context on the worker',
            ],
            Strumento::class => [
                'elenco' => self::PAGINA,
                'dettaglio' => self::DETTAGLIO,
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::refuses to move a machine into a node of another Ente',
                'eliminazione' => self::HARNESS,
                'export' => self::STORICO,
                'download' => self::DETTAGLIO,
                'ricerca' => self::PAGINA,
                'contatori' => self::CRUSCOTTO,
                'email' => self::DIGEST,
            ],
            Intervento::class => [
                'elenco' => self::PAGINA,
                'dettaglio' => 't:Isolamento/AzioniIsolationTest.php::refuses to open, complete, reopen or delete an intervento of another Ente',
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::refuses to act on an intervento of another Ente through a tampered property',
                'eliminazione' => 't:Isolamento/AzioniIsolationTest.php::refuses to act on an intervento of another Ente through a tampered property',
                'export' => self::STORICO,
                'download' => 'na:un intervento non ha un file proprio; i suoi certificati sono Documento (riga download di Documento)',
                'ricerca' => self::PAGINA,
                'contatori' => self::CRUSCOTTO,
                'email' => self::DIGEST,
            ],
            Garanzia::class => [
                'elenco' => self::DETTAGLIO,
                'dettaglio' => 't:Isolamento/AzioniIsolationTest.php::refuses to edit or delete a garanzia of another Ente',
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::refuses to edit or delete a garanzia of another Ente',
                'eliminazione' => 't:Isolamento/AzioniIsolationTest.php::refuses to edit or delete a garanzia of another Ente',
                'export' => self::STORICO,
                'download' => 'na:una garanzia non ha file',
                'ricerca' => 'na:nessuna ricerca sulle garanzie; si leggono solo dalla scheda della macchina',
                'contatori' => self::CRUSCOTTO,
                'email' => self::DIGEST,
            ],
            Documento::class => [
                'elenco' => self::PAGINA,
                'dettaglio' => 't:Isolamento/DettaglioIsolationTest.php::never leaks the bytes of a document of another Ente',
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::refuses to attach an upload to an intervento of another Ente',
                'eliminazione' => 't:Isolamento/AzioniIsolationTest.php::refuses to delete a documento of another Ente',
                'export' => 't:Isolamento/DettaglioIsolationTest.php::exports no document row of another Ente, not even when filtered on its machine',
                'download' => 't:Isolamento/DettaglioIsolationTest.php::keeps the document of another Ente on the disk after a refused download',
                'ricerca' => self::FILTRO,
                'contatori' => 'na:nessun contatore di documenti in dashboard né altrove',
                'email' => 'na:nessun documento viaggia per email (gli allegati delle email sono solo il logo del marchio)',
            ],
            Fornitore::class => [
                'elenco' => self::PAGINA,
                'dettaglio' => 't:Isolamento/AzioniIsolationTest.php::refuses to open a fornitore of another Ente for editing',
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::refuses to save onto a fornitore of another Ente through a tampered editingId',
                'eliminazione' => 't:Isolamento/AzioniIsolationTest.php::refuses to delete a fornitore of another Ente, by argument or by tampered deletingId',
                'export' => 'na:nessun export dei fornitori',
                'download' => 'na:un fornitore non ha file',
                'ricerca' => 't:Isolamento/AzioniIsolationTest.php::refuses to link a machine to a fornitore of another Ente',
                'contatori' => 'na:nessun contatore di fornitori',
                'email' => 'na:nessuna email nomina un fornitore',
            ],
            Ricambio::class => [
                'elenco' => self::PAGINA,
                'dettaglio' => 't:Isolamento/AzioniIsolationTest.php::refuses to merge a ricambio into, or out of, the catalogue of another Ente',
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::refuses to merge a ricambio into, or out of, the catalogue of another Ente',
                'eliminazione' => 't:Isolamento/AzioniIsolationTest.php::refuses to merge a ricambio into, or out of, the catalogue of another Ente',
                'export' => 'na:nessun export del catalogo ricambi',
                'download' => 'na:un ricambio non ha file',
                'ricerca' => self::PAGINA,
                'contatori' => 'na:nessun contatore di ricambi nelle pagine del tenant',
                'email' => 't:Notifiche/NotificaScadenzeTest.php::never reads the name of a ricambio, not even into the payload',
            ],
            RicambioUtilizzo::class => [
                'elenco' => self::DETTAGLIO,
                'dettaglio' => 't:Isolamento/AzioniIsolationTest.php::refuses to correct or remove a ricambio montato of another Ente',
                'modifica' => 't:Isolamento/AzioniIsolationTest.php::refuses to correct or remove a ricambio montato of another Ente',
                'eliminazione' => 't:Isolamento/AzioniIsolationTest.php::refuses to correct or remove a ricambio montato of another Ente',
                'export' => self::STORICO,
                'download' => 'na:un utilizzo di ricambio non ha file',
                'ricerca' => self::PAGINA,
                'contatori' => 'na:nessun contatore di utilizzi',
                'email' => self::DIGEST,
            ],
            SpostamentoStrumento::class => [
                'elenco' => self::DETTAGLIO,
                'dettaglio' => 'na:il registro spostamenti non ha una pagina propria, vive nella scheda (riga elenco)',
                'modifica' => 'na:registro immutabile: nessuna azione lo modifica; lo scrive solo SchedaStrumento::move (riga modifica di Strumento)',
                'eliminazione' => self::HARNESS,
                'export' => self::STORICO,
                'download' => 'na:uno spostamento non ha file',
                'ricerca' => 'na:nessuna ricerca sugli spostamenti',
                'contatori' => 'na:nessun contatore di spostamenti',
                'email' => 't:Notifiche/AvvisoObsolescenzaTest.php::warns the new Ente when an obsolete machine is transferred across tenants',
            ],
            AvvisoScadenza::class => [
                'elenco' => 'na:registro interno di deduplica del digest, senza nessuna superficie di lettura',
                'dettaglio' => 'na:registro interno di deduplica del digest, senza nessuna superficie di lettura',
                'modifica' => 'na:scritto solo da console (comandi notifica), mai da un utente',
                'eliminazione' => self::HARNESS,
                'export' => 'na:registro interno, nessun export',
                'download' => 'na:registro interno, nessun file',
                'ricerca' => 'na:registro interno, nessuna ricerca',
                'contatori' => 'na:registro interno, nessun contatore',
                'email' => 't:Isolamento/CodeIsolationTest.php::builds each digest with the rows of its own Ente only, in the console context of the scheduler',
            ],
        ];
    }

    /**
     * Rotte dell'app con un parametro nel percorso: ciascuna ha un test
     * cross-tenant o un motivo per cui il parametro non è un dato di tenant.
     *
     * @return array<string, string> uri => cella
     */
    public static function rotteConParametro(): array
    {
        $registrazione = 'na:Registrazione è esente (TenantScopeGuardrailTest): URL firmato, rotte guest; coperta da Registrazione/AccessoRegistrazioniGuardrailTest';

        return [
            'strumenti/{strumento}' => self::DETTAGLIO,
            'strumenti/{strumento}/qr' => self::DETTAGLIO,
            'strumenti/{strumento}/storico.pdf' => self::DETTAGLIO,
            'documenti/{documento}' => self::DETTAGLIO,
            'q/{token}' => self::DETTAGLIO,
            'bloccato/passa/{ente}' => 't:Isolamento/SwitcherEnteIsolationTest.php::refuses the lockout escape toward the Ente of another account',
            'guida/{slug}/{pezzo}' => 'na:file statici delle guide, uguali per tutti i clienti',
            'invito/{user}' => 'na:URL firmato per id utente (ADR-012), nessun dato di tenant oltre al proprio invito',
            'piattaforma/errori/{errore}' => 'na:Errore è esente (piattaforma, confine = permesso system.logs.view); Piattaforma/AccessoErroriTest',
            'piattaforma/parco/impersona/{utente}/strumento/{strumento}' => 't:Piattaforma/ImpersonaVersoStrumentoTest.php::refuses whoever cannot see the parco at all',
            'registrazione/{registrazione}/completata' => $registrazione,
            'registrazione/{registrazione}/pagamento' => $registrazione,
            'registrazione/{registrazione}/verifica' => $registrazione,
        ];
    }

    /**
     * Ogni componente Livewire (non Concerns) e la cella che ne prova
     * l'isolamento.
     *
     * @return array<string, string> classe relativa ad app/Livewire => cella
     */
    public static function componenti(): array
    {
        $piattaforma = 'na:superficie di piattaforma, cross-tenant per costruzione dietro tenants.view_all (VistaPiattaforma); negativo in Piattaforma/AccessoPiattaformaTest';

        return [
            'Anagrafica/Albero' => 't:Isolamento/AzioniIsolationTest.php::refuses to open, edit, delete or add under a node of another Ente',
            'Anagrafica/MarchioEnte' => 'na:scrive solo sull\'Ente corrente, risolto da CurrentTenant e non da un id del client',
            'Billing/PaginaAbbonamento' => 'na:legge l\'account dell\'Ente corrente; nessun id dal client',
            'Campo/Home' => self::PAGINA,
            'Dashboard/Home' => self::CRUSCOTTO,
            'Documenti/ElencoDocumenti' => self::FILTRO,
            'Fornitori/ElencoFornitori' => 't:Isolamento/AzioniIsolationTest.php::refuses to delete a fornitore of another Ente, by argument or by tampered deletingId',
            'Guida/Manuale' => 'na:contenuti statici uguali per tutti i clienti',
            'Interventi/Scadenzario' => self::PAGINA,
            'Notifiche/Campanella' => 't:Notifiche/CampanellaTest.php::never shows the notifications of another user',
            'Piattaforma/Cabina' => $piattaforma,
            'Piattaforma/EditorRuoli' => $piattaforma,
            'Piattaforma/Errori' => $piattaforma,
            'Piattaforma/Listino' => $piattaforma,
            'Piattaforma/ParcoGlobale' => $piattaforma,
            'Piattaforma/ParcoRicambi' => $piattaforma,
            'Piattaforma/ParcoScadenzario' => $piattaforma,
            'Piattaforma/RegistroAudit' => $piattaforma,
            'Piattaforma/SchedaErrore' => $piattaforma,
            'Piattaforma/Tecnici' => $piattaforma,
            'Ricambi/RicercaRicambi' => 't:Isolamento/AzioniIsolationTest.php::refuses to merge a ricambio into, or out of, the catalogue of another Ente',
            'Settings/PreferenzeNotifiche' => 'na:preferenze del solo utente autenticato',
            'Settings/SelettoreTema' => 'na:preferenza del solo utente autenticato',
            'Settings/TwoFactorAuthentication' => 'na:2FA del solo utente autenticato (ImpersonationTest copre l\'impersonatore)',
            'Strumenti/ElencoStrumenti' => self::FILTRO,
            'Strumenti/ImportStrumenti' => 'na:scrive solo nel tenant corrente; nodoId risolto con findOrFail scopato (T1c ne è proprietario)',
            'Strumenti/ModelliStrumenti' => self::PAGINA,
            'Strumenti/SchedaStrumento' => 't:Isolamento/AzioniIsolationTest.php::refuses to act on an intervento of another Ente through a tampered property',
            'Strumenti/StampaQr' => self::DETTAGLIO,
            'Tenancy/SwitcherEnte' => 't:Isolamento/SwitcherEnteIsolationTest.php::refuses to switch into the Ente of another account',
            'Utenti/ElencoUtenti' => 't:Isolamento/AzioniIsolationTest.php::refuses to change the role of, or bin, a user of another Ente through tampered properties',
        ];
    }

    /**
     * Ogni classe ShouldQueue dell'app e come porta (o non porta) un Ente.
     *
     * @return array<class-string, string>
     */
    public static function code(): array
    {
        $senzaEnte = 'na:non porta dati di un Ente (piattaforma o registrazione pubblica, prima che il tenant esista)';

        return [
            DigestScadenze::class => self::DIGEST,
            AvvisoObsolescenza::class => 't:Notifiche/AvvisoObsolescenzaTest.php::never puts machines of another Ente in the list',
            InvitoUtente::class => 't:Isolamento/CodeIsolationTest.php::delivers an invitation queued by A with the brand of A, whatever the context on the worker',
            VerificaEmailRegistrazione::class => $senzaEnte,
            BenvenutoRegistrazione::class => $senzaEnte,
            PropostaPiano::class => $senzaEnte,
            InviaAllertaErrore::class => 't:Notifiche/MarchioEmailTest.php::never brands the platform alert with a tenant, by construction and not by convention',
        ];
    }

    /**
     * I modelli con BelongsToTenant, derivati con la stessa logica di
     * TenantScopeGuardrailTest (glob piatto su app/Models, la cui piattezza è
     * imposta da quel file) ma guardando il trait invece di un elenco di esenti.
     *
     * @return list<class-string<Model>>
     */
    public static function modelliTenant(): array
    {
        $modelli = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $classe = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
            if (class_exists($classe) && is_subclass_of($classe, Model::class)
                && in_array(BelongsToTenant::class, class_uses_recursive($classe), true)) {
                $modelli[] = $classe;
            }
        }
        sort($modelli);

        return $modelli;
    }
}

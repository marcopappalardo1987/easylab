<?php

namespace Tests\Feature\Isolamento\Support;

use App\Enums\TransizioneAvviso;
use App\Models\Account;
use App\Models\AvvisoScadenza;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Garanzia;
use App\Models\Intervento;
use App\Models\Ricambio;
use App\Models\RicambioUtilizzo;
use App\Models\SpostamentoStrumento;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Due Enti completi (A e B), una riga per ogni modello con BelongsToTenant, e
 * su ogni riga un marcatore testuale `SEGRETO-{X}` cercabile in qualunque
 * superficie: HTML, CSV, vista del PDF, email, payload Livewire (ADR-001).
 *
 * Tutto nasce in contesto console (nessun utente autenticato), dove il hook
 * `creating` di BelongsToTenant non ritimbra: la riga «estranea» resta davvero
 * dell'Ente B. Va chiamato PRIMA di qualunque actingAs.
 */
final class MondoDueEnti
{
    /** @var array<string, array<string, mixed>> */
    public array $enti = [];

    public static function crea(): self
    {
        Storage::fake(Documento::DISCO);

        $mondo = new self;
        $mondo->enti['A'] = $mondo->ente('A');
        $mondo->enti['B'] = $mondo->ente('B');

        return $mondo;
    }

    public function riga(string $lettera, string $chiave): mixed
    {
        return $this->enti[$lettera][$chiave];
    }

    /**
     * Ogni stringa che identifica un dato dell'Ente: se una compare in una
     * superficie servita all'altro Ente, è una fuga.
     *
     * @return list<string>
     */
    public static function marcatori(string $lettera): array
    {
        return [
            "Ente-SEGRETO-{$lettera}",
            "Reparto-SEGRETO-{$lettera}",
            "Strumento-SEGRETO-{$lettera}",
            "MAT-SEGRETO-{$lettera}",
            "Intervento-SEGRETO-{$lettera}",
            "Fornitore-SEGRETO-{$lettera}",
            "Ricambio-SEGRETO-{$lettera}",
            "Documento-SEGRETO-{$lettera}",
            "Spostamento-SEGRETO-{$lettera}",
            "Utente-SEGRETO-{$lettera}",
            "MOD-SEGRETO-{$lettera}",
            "RIC-SEGRETO-{$lettera}",
            "Account-SEGRETO-{$lettera}",
            "Responsabile-{$lettera}",
            "Tecnico-{$lettera}",
            "RepartoNonAssegnato-{$lettera}",
            "StrumentoNonAssegnato-{$lettera}",
            "MAT-NONASSEGNATO-{$lettera}",
            "MOD-NONASSEGNATO-{$lettera}",
            "InterventoNonAssegnato-{$lettera}",
            "DocumentoNonAssegnato-{$lettera}",
        ];
    }

    /** @return array<string, mixed> */
    private function ente(string $x): array
    {
        $account = Account::factory()->create(['ragione_sociale' => "Account-SEGRETO-{$x}"]);
        $ente = UnitaOrganizzativa::factory()->ente()->perAccount($account)->create(['nome' => "Ente-SEGRETO-{$x}"]);
        $ente->refresh();
        $reparto = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => "Reparto-SEGRETO-{$x}"]);
        $altroReparto = UnitaOrganizzativa::factory()->dipartimento()->under($ente)->create(['nome' => "RepartoNonAssegnato-{$x}"]);

        $strumento = Strumento::factory()->forNode($reparto)->create([
            'nome' => "Strumento-SEGRETO-{$x}",
            'matricola' => "MAT-SEGRETO-{$x}",
            'modello' => "MOD-SEGRETO-{$x}",
        ]);
        $strumentoAltroReparto = Strumento::factory()->forNode($altroReparto)->create([
            'nome' => "StrumentoNonAssegnato-{$x}",
            'matricola' => "MAT-NONASSEGNATO-{$x}",
            'modello' => "MOD-NONASSEGNATO-{$x}",
        ]);

        $admin = $this->utente($ente, 'Admin', "Utente-SEGRETO-{$x}");
        $responsabile = $this->utente($ente, User::DEPARTMENT_SCOPED_ROLE, "Responsabile-{$x}");
        $responsabile->unitaResponsabili()->attach($reparto->id);
        $tecnico = $this->utente($ente, User::TECNICO_ROLE, "Tecnico-{$x}");
        // Come l'app: dell'account è membro solo chi lo amministra (ADR-032).
        // Farne membri anche Responsabile e Tecnico nascondeva T2B-1 e T2B-4.
        $account->aggiungiMembro($admin);

        $intervento = Intervento::factory()->forStrumento($strumento)->scaduto()->create([
            'descrizione' => "Intervento-SEGRETO-{$x}",
            'tecnico_id' => $tecnico->id,
        ]);
        $interventoAltroReparto = Intervento::factory()->forStrumento($strumentoAltroReparto)->scaduto()->create([
            'descrizione' => "InterventoNonAssegnato-{$x}",
        ]);

        $fornitore = Fornitore::factory()->forTenant($ente)->create(['ragione_sociale' => "Fornitore-SEGRETO-{$x}"]);
        $ricambio = Ricambio::factory()->forTenant($ente)->conCodice("RIC-SEGRETO-{$x}")->create(['nome' => "Ricambio-SEGRETO-{$x}"]);
        $utilizzo = RicambioUtilizzo::factory()->forIntervento($intervento)->forRicambio($ricambio)->create();
        $garanzia = Garanzia::factory()->forStrumento($strumento)->imminente()->create();

        $percorso = "documenti/{$x}/segreto.pdf";
        Storage::disk(Documento::DISCO)->put($percorso, "%PDF-1.4 CONTENUTO-SEGRETO-{$x}");
        $documento = Documento::factory()->perStrumento($strumento)->create([
            'nome' => "Documento-SEGRETO-{$x}.pdf",
            'path' => $percorso,
        ]);

        $percorsoFuori = "documenti/{$x}/fuori-reparto.pdf";
        Storage::disk(Documento::DISCO)->put($percorsoFuori, "%PDF-1.4 CONTENUTO-FUORI-REPARTO-{$x}");
        $documentoAltroReparto = Documento::factory()->perStrumento($strumentoAltroReparto)->create([
            'nome' => "DocumentoNonAssegnato-{$x}.pdf",
            'path' => $percorsoFuori,
        ]);

        $spostamento = SpostamentoStrumento::factory()->forStrumento($strumento)->create([
            'nota' => "Spostamento-SEGRETO-{$x}",
        ]);

        $avviso = AvvisoScadenza::create([
            'tenant_id' => $ente->id,
            'riferimento_type' => $intervento->getMorphClass(),
            'riferimento_id' => $intervento->id,
            'transizione' => TransizioneAvviso::Scaduta,
            'data_scadenza' => $intervento->data_scadenza,
        ]);

        return compact(
            'account', 'ente', 'reparto', 'altroReparto', 'strumento', 'strumentoAltroReparto',
            'admin', 'responsabile', 'tecnico', 'intervento', 'interventoAltroReparto',
            'fornitore', 'ricambio', 'utilizzo', 'garanzia', 'documento', 'documentoAltroReparto', 'spostamento', 'avviso',
        );
    }

    private function utente(UnitaOrganizzativa $ente, string $ruolo, string $nome): User
    {
        $user = User::factory()->create([
            'name' => $nome,
            'two_factor_confirmed_at' => now(),
        ]);
        $user->forceFill(['tenant_id' => $ente->id])->save();
        $user->assignRole($ruolo);

        return $user;
    }
}

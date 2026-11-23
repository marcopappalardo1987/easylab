<?php

namespace App\Console\Commands;

use App\Models\Garanzia;
use App\Models\Scopes\DepartmentScope;
use App\Models\Scopes\TenantScope;
use App\Models\UnitaOrganizzativa;
use App\Support\Garanzie\GaranzieDalCollaudo;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Riallinea al collaudo le garanzie della macchina già registrate
 * (🔗 ADR-065; ERD §6.1).
 *
 * Fino al 23 Nov 2026 la garanzia della macchina aveva un inizio suo, scritto
 * nel modulo; da allora conta dalla data di collaudo. Le garanzie nuove
 * nascono già così, e un collaudo corretto sposta le sue (`Strumento::booted()`):
 * restano quelle di prima, con un inizio che può essere un altro giorno.
 *
 * **Di default non scrive niente.** Elenca per cliente le garanzie il cui
 * inizio non è il collaudo, con la scadenza di oggi e quella che avrebbero, e
 * a parte le macchine con garanzia ma **senza** collaudo: quelle non si toccano
 * mai, perché non c'è una data da cui farle contare. È l'elenco che Marco
 * guarda prima di decidere.
 *
 * Con `--applica` scrive, e solo le righe dell'elenco: una garanzia già
 * allineata non si salva, e non lascia una riga di audit vuota. ⚠️ In
 * produzione `--applica` si lancia **solo col via di Marco** (decisione del
 * 23 Nov 2026), e `ConfirmableTrait` chiede conferma: `--force` la salta.
 *
 * Nel ramo con la scadenza dichiarata (ADR-022) la scadenza non cambia: si
 * sposta solo l'inizio. Se quella scadenza non viene dopo il collaudo la riga
 * non si può far contare da lì, e il comando la lascia com'è e la segna «a
 * mano» (`Garanzia::contaDalCollaudo()`).
 *
 * In console i global scope si ritirano (`CurrentTenant::shouldScope()`): le
 * letture stanno in `GaranzieDalCollaudo`, con il tenant esplicito.
 */
class AllineaGaranzieCollaudo extends Command
{
    use ConfirmableTrait;

    protected $signature = 'easylab:allinea-garanzie-collaudo
        {--applica : Scrive il ricalcolo; senza, il comando elenca soltanto}
        {--force : Con --applica, salta la conferma in produzione}';

    protected $description = 'Elenca (e con --applica riallinea) le garanzie della macchina che non contano dal collaudo (ADR-065)';

    public function handle(): int
    {
        $disallineate = GaranzieDalCollaudo::disallineate();
        $senzaCollaudo = GaranzieDalCollaudo::senzaCollaudo();
        $clienti = $this->nomiDeiClienti($disallineate->merge($senzaCollaudo));

        // La scadenza nuova si legge dalla regola vera, sul model in memoria:
        // `contaDalCollaudo()` non salva. Si annota prima ciò che c'è oggi.
        $righe = $disallineate->map(function (Garanzia $garanzia): array {
            $inizio = $garanzia->data_inizio->copy();
            $scadenza = $garanzia->data_scadenza_effettiva->copy();
            // Una riga storica che la normalizzazione rifiuta (un dato scritto
            // a mano nel DB) non deve fermare l'elenco delle altre: si segna a
            // mano, e non si salva.
            try {
                $allineabile = $garanzia->contaDalCollaudo($garanzia->strumento->data_installazione);
            } catch (InvalidArgumentException) {
                $allineabile = false;
            }

            return [
                'garanzia' => $garanzia,
                'allineabile' => $allineabile,
                'tabella' => [
                    $garanzia->strumento->nome,
                    $garanzia->strumento->matricola ?: '—',
                    $garanzia->strumento->data_installazione->format('d/m/Y'),
                    $inizio->format('d/m/Y'),
                    $garanzia->durata_mesi !== null ? $garanzia->durata_mesi.' mesi' : 'fino al '.$garanzia->data_scadenza_dichiarata->format('d/m/Y'),
                    $scadenza->format('d/m/Y'),
                    $allineabile ? $garanzia->data_scadenza_effettiva->format('d/m/Y') : 'a mano: non viene dopo il collaudo',
                ],
            ];
        });

        $this->elencaDisallineate($righe, $clienti);
        $this->elencaSenzaCollaudo($senzaCollaudo, $clienti);

        $daScrivere = $righe->where('allineabile', true);

        if (! $this->option('applica')) {
            $this->newLine();
            $this->line('Nessuna scrittura. Per riallineare queste '.$daScrivere->count().' garanzie: --applica (in produzione solo col via di Marco).');

            return self::SUCCESS;
        }

        if ($daScrivere->isEmpty()) {
            $this->info('Niente da riallineare.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        DB::transaction(fn () => $daScrivere->each(fn (array $riga) => $riga['garanzia']->save()));

        $this->info('Riallineate '.$daScrivere->count().' garanzie della macchina al collaudo.');

        if (($aMano = $righe->where('allineabile', false)->count()) > 0) {
            $this->warn("Lasciate com'erano: {$aMano}, con una scadenza dichiarata che non viene dopo il collaudo. Vanno corrette a mano.");
        }

        if ($senzaCollaudo->isNotEmpty()) {
            $this->warn('Non toccate: '.$senzaCollaudo->count().' garanzie su macchine senza data di collaudo.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array{garanzia: Garanzia, allineabile: bool, tabella: list<string>}>  $righe
     * @param  Collection<int, string>  $clienti
     */
    private function elencaDisallineate(Collection $righe, Collection $clienti): void
    {
        if ($righe->isEmpty()) {
            $this->info('Tutte le garanzie della macchina contano già dal collaudo.');

            return;
        }

        $this->info('Garanzie della macchina che non contano dal collaudo: '.$righe->count()
            .' su '.$righe->pluck('garanzia.strumento_id')->unique()->count().' macchine.');

        foreach ($righe->groupBy(fn (array $r) => $r['garanzia']->tenant_id) as $tenantId => $delCliente) {
            $this->newLine();
            $this->line('<comment>'.$this->cliente($clienti, (int) $tenantId).'</comment>');
            $this->table(
                ['Macchina', 'Matricola', 'Collaudo', 'Inizio di oggi', 'Durata', 'Scadenza di oggi', 'Scadenza nuova'],
                $delCliente->pluck('tabella')->all(),
            );
        }
    }

    /**
     * @param  Collection<int, Garanzia>  $garanzie
     * @param  Collection<int, string>  $clienti
     */
    private function elencaSenzaCollaudo(Collection $garanzie, Collection $clienti): void
    {
        if ($garanzie->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('Macchine con garanzia ma senza data di collaudo (non si toccano): '.$garanzie->pluck('strumento_id')->unique()->count().'.');

        foreach ($garanzie->groupBy('tenant_id') as $tenantId => $delCliente) {
            $this->newLine();
            $this->line('<comment>'.$this->cliente($clienti, (int) $tenantId).'</comment>');
            $this->table(
                ['Macchina', 'Matricola', 'Garanzie', 'Scadenza più lontana'],
                $delCliente->groupBy('strumento_id')->map(fn (Collection $g) => [
                    $g->first()->strumento->nome,
                    $g->first()->strumento->matricola ?: '—',
                    $g->count(),
                    $g->max('data_scadenza_effettiva')->format('d/m/Y'),
                ])->values()->all(),
            );
        }
    }

    /**
     * Il nome del cliente è quello dell'Ente: il nodo radice del tenant.
     *
     * @param  Collection<int, Garanzia>  $garanzie
     * @return Collection<int, string>
     */
    private function nomiDeiClienti(Collection $garanzie): Collection
    {
        // Per nome e coi cestinati: serve solo il nome, anche di un Ente chiuso.
        return UnitaOrganizzativa::withoutGlobalScopes([TenantScope::class, DepartmentScope::class])
            ->withTrashed()
            ->whereIn('id', $garanzie->pluck('tenant_id')->unique()->values())
            ->pluck('nome', 'id');
    }

    /** @param  Collection<int, string>  $clienti */
    private function cliente(Collection $clienti, int $tenantId): string
    {
        return ($clienti[$tenantId] ?? 'Ente senza nome')." (Ente #{$tenantId})";
    }
}

<?php

namespace Database\Seeders;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Popolamento dimostrativo dello staging (ERD §4-§7; ADR-032/038).
 *
 * Crea tre sedi sotto un unico account EasyLab e delega a DemoSeeder la
 * produzione di alberatura, persone, tecnici, fornitori, strumenti,
 * interventi, spostamenti, garanzie, ricambi e semafori forzati.
 *
 * È additivo e rieseguibile: una sede già popolata viene saltata. Una sede
 * omonima con dati parziali o appartenente a un altro account fa fallire il
 * seeder invece di essere modificata implicitamente.
 */
class StagingSeeder extends DemoSeeder
{
    private const ACCOUNT = 'EasyLab — Demo staging';

    private const SEDI = [
        'EasyLab Milano',
        'EasyLab Roma',
        'EasyLab Catania',
    ];

    private const MINIMO_STRUMENTI = 1001;

    public function run(): void
    {
        if (! app()->environment(['staging', 'testing'])) {
            throw new RuntimeException('StagingSeeder può essere eseguito solo con APP_ENV=staging.');
        }

        [$account, $sedi] = DB::transaction(fn () => $this->preparaSedi());

        foreach ($sedi as $sede) {
            $presenti = Strumento::withoutGlobalScopes()->where('tenant_id', $sede->id)->count();

            if ($presenti >= self::MINIMO_STRUMENTI) {
                $this->command?->info("Sede «{$sede->nome}» già popolata: {$presenti} strumenti.");

                continue;
            }

            if ($presenti > 0) {
                throw new RuntimeException(
                    "La sede «{$sede->nome}» contiene {$presenti} strumenti: popolamento parziale, nessuna modifica applicata."
                );
            }

            $this->command?->info("Popolamento sede «{$sede->nome}»...");
            DB::transaction(fn () => $this->popolaEnte($sede, $account));
        }

        $this->verificaInvarianti();
        $this->verificaVolumi($sedi);
        $this->riepilogo();
    }

    /** @return array{0: Account, 1: Collection<int, UnitaOrganizzativa>} */
    private function preparaSedi(): array
    {
        $account = Account::firstOrCreate(['ragione_sociale' => self::ACCOUNT]);
        $account->cambiaPiano('saas');

        $sedi = collect(self::SEDI)->map(function (string $nome) use ($account): UnitaOrganizzativa {
            $sede = UnitaOrganizzativa::withoutGlobalScopes()
                ->where('tipo', TipoUnitaOrganizzativa::Ente->value)
                ->whereNull('parent_id')
                ->where('nome', $nome)
                ->first();

            if ($sede === null) {
                $sede = UnitaOrganizzativa::create([
                    'tipo' => TipoUnitaOrganizzativa::Ente,
                    'parent_id' => null,
                    'nome' => $nome,
                    'note' => 'Dati dimostrativi dello staging',
                ]);
                $sede->radicaComeEnte($account);

                return $sede;
            }

            if ((int) $sede->account_id !== (int) $account->id) {
                throw new RuntimeException("La sede «{$nome}» esiste già sotto un altro account.");
            }

            return $sede;
        });

        return [$account, $sedi];
    }

    /** @param Collection<int, UnitaOrganizzativa> $sedi */
    private function verificaVolumi(Collection $sedi): void
    {
        foreach ($sedi as $sede) {
            $strumenti = Strumento::withoutGlobalScopes()->where('tenant_id', $sede->id)->count();

            if ($strumenti < self::MINIMO_STRUMENTI) {
                throw new RuntimeException(
                    "La sede «{$sede->nome}» ha solo {$strumenti} strumenti; richiesti almeno ".self::MINIMO_STRUMENTI.'.'
                );
            }

            $this->command?->info("Sede «{$sede->nome}» verificata: {$strumenti} strumenti.");
        }
    }
}

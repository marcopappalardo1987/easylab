<?php

namespace Database\Seeders;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\Strumento;
use App\Models\UnitaOrganizzativa;
use RuntimeException;

/**
 * Seconda sede sull'account dimostrativo dello **studio delle guide** (`guide/`).
 *
 * Serve alla guida «cambiare sede»: lo switcher di ADR-032 compare solo se
 * l'account ha più di un Ente, e `DemoSeeder` ne dà uno solo a «Laboratori
 * Aurora». Sposta l'account su `saas` perché il piano `free` ha `max_enti = 1`.
 *
 * Non tocca Stripe: `cambiaPiano()` scrive solo la colonna.
 *
 * Additivo e rieseguibile: una sede già popolata viene saltata.
 */
class GuidaSecondaSedeSeeder extends DemoSeeder
{
    private const PRIMA = 'Sede di Milano';

    private const SECONDA = 'Sede di Bologna';

    public function run(): void
    {
        $milano = UnitaOrganizzativa::withoutGlobalScopes()
            ->whereNull('parent_id')->where('nome', self::PRIMA)->first();

        if ($milano === null) {
            throw new RuntimeException('«'.self::PRIMA.'» non esiste: lanciare prima bin/prepara-demo.sh.');
        }

        $account = Account::findOrFail($milano->account_id);
        $account->cambiaPiano('saas');

        $bologna = UnitaOrganizzativa::withoutGlobalScopes()
            ->whereNull('parent_id')->where('nome', self::SECONDA)->first();

        if ($bologna !== null) {
            $presenti = Strumento::withoutGlobalScopes()->where('tenant_id', $bologna->id)->count();
            $this->command?->info('«'.self::SECONDA."» esiste già: {$presenti} strumenti.");

            return;
        }

        $bologna = UnitaOrganizzativa::create([
            'tipo' => TipoUnitaOrganizzativa::Ente,
            'parent_id' => null,
            'nome' => self::SECONDA,
            'note' => 'Seconda sede dimostrativa per le guide',
        ]);
        $bologna->radicaComeEnte($account);

        $this->popolaEnte($bologna, $account);
        $this->verificaInvarianti();

        $this->command?->info(
            '«'.self::SECONDA.'»: '.Strumento::withoutGlobalScopes()->where('tenant_id', $bologna->id)->count().' strumenti.'
        );
    }
}

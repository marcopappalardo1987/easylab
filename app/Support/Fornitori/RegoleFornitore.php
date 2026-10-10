<?php

namespace App\Support\Fornitori;

/**
 * Le regole dell'anagrafica di un fornitore, in un posto solo (🔗 ADR-023,
 * ADR-051).
 *
 * Un fornitore nasce da due schermate: la pagina Fornitori, e il selettore che
 * lo crea al volo mentre si registra una macchina o un pezzo. Due copie delle
 * stesse regole sarebbero due regole, libere di divergere alla prima modifica:
 * quello creato di fretta dal selettore accetterebbe ciò che la pagina rifiuta.
 */
final class RegoleFornitore
{
    private const REGOLE = [
        'ragione_sociale' => ['required', 'string', 'max:255'],
        'email' => ['nullable', 'email', 'max:255'],
        'telefono' => ['nullable', 'string', 'max:50'],
        'note' => ['nullable', 'string', 'max:1000'],
    ];

    /**
     * Le regole dei campi chiesti, sotto il prefisso della property del form.
     *
     * @param  list<string>  $campi
     * @return array<string, list<string>>
     */
    public static function per(string $prefisso, array $campi = ['ragione_sociale', 'email', 'telefono', 'note']): array
    {
        $regole = [];

        foreach ($campi as $campo) {
            $regole["{$prefisso}.{$campo}"] = self::REGOLE[$campo];
        }

        return $regole;
    }
}

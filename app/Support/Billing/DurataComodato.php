<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Le durate fra cui si sceglie assegnando un piano in comodato d'uso
 * (🔗 ADR-058): dodici, ventiquattro o trentasei mesi, una data precisa, o
 * nessuna scadenza.
 *
 * Un posto solo perché le schermate che lo chiedono sono due — la nascita del
 * cliente e il pannello di un cliente già attivo — e devono offrire le stesse
 * scelte, validarle allo stesso modo e arrivare alla stessa data.
 */
final class DurataComodato
{
    public const SENZA_SCADENZA = '';

    public const DATA_PRECISA = 'data';

    /** I mesi che si offrono: quelli dei contratti di comodato di EasyLab. */
    public const MESI = [12, 24, 36];

    /** Quanti giorni prima della scadenza parte l'avviso. */
    public const GIORNI_DI_PREAVVISO = 30;

    /**
     * Le voci della tendina, nell'ordine in cui si leggono.
     *
     * @return array<string, string>
     */
    public static function opzioni(): array
    {
        $opzioni = [self::SENZA_SCADENZA => 'Senza scadenza'];

        foreach (self::MESI as $mesi) {
            $opzioni[(string) $mesi] = "{$mesi} mesi da oggi";
        }

        return $opzioni + [self::DATA_PRECISA => 'Fino a una data precisa'];
    }

    /** @return list<string> ciò che la tendina può consegnare */
    public static function valori(): array
    {
        return array_map('strval', array_keys(self::opzioni()));
    }

    /**
     * La data di scadenza che la scelta produce, o `null` per «senza scadenza».
     *
     * I mesi si contano **da oggi**, e senza sforare: dodici mesi dal 29
     * febbraio sono il 28, non il 1° marzo.
     *
     * @param  ?string  $data  `Y-m-d`, letta solo per «una data precisa»
     */
    public static function scadenza(string $scelta, ?string $data = null, ?CarbonInterface $oggi = null): ?CarbonImmutable
    {
        $oggi = CarbonImmutable::instance($oggi ?? today())->startOfDay();

        if ($scelta === self::SENZA_SCADENZA) {
            return null;
        }

        if ($scelta === self::DATA_PRECISA) {
            $giorno = CarbonImmutable::createFromFormat('!Y-m-d', (string) $data);

            if ($giorno === false || $giorno->format('Y-m-d') !== $data) {
                throw new InvalidArgumentException("«{$data}» non è una data.");
            }

            return $giorno;
        }

        if (! in_array((int) $scelta, self::MESI, true) || (string) (int) $scelta !== $scelta) {
            throw new InvalidArgumentException("«{$scelta}» non è una durata di comodato.");
        }

        return $oggi->addMonthsNoOverflow((int) $scelta);
    }

    /**
     * Le regole di validazione dei due campi, sotto il prefisso della property
     * del form. La data serve solo se la si è scelta, e non può essere passata:
     * di una scadenza già trascorsa non partirebbe nessun avviso.
     *
     * @return array<string, list<mixed>>
     */
    public static function regole(string $campoDurata, string $campoData): array
    {
        return [
            $campoDurata => ['nullable', 'string', 'in:'.implode(',', array_filter(self::valori(), fn (string $v) => $v !== ''))],
            $campoData => ['nullable', 'required_if:'.$campoDurata.','.self::DATA_PRECISA, 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}

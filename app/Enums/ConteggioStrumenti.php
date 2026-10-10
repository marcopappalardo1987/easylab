<?php

namespace App\Enums;

/**
 * Come si conta il tetto di strumenti di un piano (🔗 ADR-049; ADR-035 il
 * listino, ADR-032 l'Account e le sue sedi).
 *
 * Il numero sta sul piano (`piani.max_strumenti`); questo dice **a che cosa si
 * applica**. È una proprietà del piano e non del contratto: si decide nel
 * listino, e chi attiva il piano la trova già decisa (decisione di Marco del
 * 10 Ott 2026).
 *
 * - `PerSede`: ogni sede (Ente) ha il suo tetto. Con 3 sedi e un tetto di 50 il
 *   cliente arriva a 150.
 * - `PerCliente`: il tetto è uno solo, e conta gli strumenti di tutte le sedi
 *   dello stesso contratto.
 *
 * Su un piano da una sede sola i due modi coincidono.
 */
enum ConteggioStrumenti: string
{
    case PerSede = 'per_sede';
    case PerCliente = 'per_cliente';

    /** Etichetta del form del listino: unica fonte, come per TipoIntervento (ADR-021). */
    public function label(): string
    {
        return match ($this) {
            self::PerSede => 'Per sede',
            self::PerCliente => 'Per cliente, sommando le sedi',
        };
    }

    /**
     * Il tetto detto a parole: «strumenti illimitati», «fino a 50 strumenti per
     * sede», «fino a 1 strumento in tutto».
     *
     * Una frase sola per il listino, la registrazione, l'abbonamento e il
     * rifiuto: più copie divergerebbero alla prima modifica, e a leggerle è chi
     * sta decidendo che cosa comprare.
     */
    public function tetto(?int $max): string
    {
        if ($max === null) {
            return 'strumenti illimitati';
        }

        return "fino a {$max} ".($max === 1 ? 'strumento' : 'strumenti').' '.match ($this) {
            self::PerSede => 'per sede',
            self::PerCliente => 'in tutto',
        };
    }
}

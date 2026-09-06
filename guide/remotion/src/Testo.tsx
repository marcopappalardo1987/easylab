import React from 'react';
import { spring } from 'remotion';
import { FPS } from './camera';

/** Molla morbida: un filo di sorpasso, quanto basta perché non sembri una dissolvenza. */
const MOLLA = { damping: 15, mass: 0.62, stiffness: 130 };

export const molla = (t: number): number =>
  t <= 0 ? 0 : spring({ frame: t * FPS, fps: FPS, config: MOLLA, durationInFrames: 26 });

/**
 * Testo che entra parola per parola.
 *
 * A blocco intero un testo «appare»; sfalsato di poco si LEGGE, perché l'occhio
 * viene portato da sinistra a destra alla velocità con cui lo leggerebbe da sé.
 * Il passo resta piccolo (~60 ms): sopra i 100 ms diventa un'insegna a scorrimento.
 */
export const Parole: React.FC<{
  testo: string;
  t: number;
  ritardo?: number;
  passo?: number;
  salita?: number;
  opacita?: number;
  stile?: React.CSSProperties;
}> = ({ testo, t, ritardo = 0, passo = 0.06, salita = 16, opacita = 1, stile }) => (
  <span style={{ ...stile, display: 'inline-block' }}>
    {testo.split(' ').map((parola, i) => {
      const p = molla(t - ritardo - i * passo);

      return (
        <span
          key={`${parola}-${i}`}
          style={{
            display: 'inline-block',
            whiteSpace: 'pre',
            opacity: p * opacita,
            transform: `translateY(${(1 - p) * salita}px)`,
          }}
        >
          {parola}
          {i < testo.split(' ').length - 1 ? ' ' : ''}
        </span>
      );
    })}
  </span>
);

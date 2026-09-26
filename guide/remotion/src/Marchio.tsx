import React from 'react';
import { Img, staticFile } from 'remotion';

/** Proporzioni del lockup: viewBox 498.69 × 105.46, uguale in tutte le varianti. */
const RAPPORTO = 105.46 / 498.69;

/**
 * Il marchio Easy Lab, variante per fondo scuro (ADR-033/034).
 *
 * `compatto` è il solo lettering: sotto i ~200px di larghezza il payoff
 * «GESTIONE STRUMENTAZIONE E MANUTENZIONE» diventa una riga grigia illeggibile,
 * cioè sporco. Sopra, si usa il lockup intero.
 */
export const Marchio: React.FC<{ larghezza: number; opacita?: number }> = ({ larghezza, opacita = 1 }) => (
  <Img
    src={staticFile(larghezza < 200 ? 'brand/logo-compatto.svg' : 'brand/logo.svg')}
    style={{ width: larghezza, height: larghezza * RAPPORTO, opacity: opacita, display: 'block' }}
  />
);

/**
 * Costanti del montaggio condivise fra chi CATTURA e chi MONTA.
 *
 * ⚠️ `INTRO` stava solo in `remotion/src/Guida.tsx`, quindi l'istante d'inizio
 * di ogni passo — che serve alla guida scritta per saltare nel video — poteva
 * essere calcolato solo dal montaggio. Conseguenza: cambiare una parola di un
 * testo obbligava a rirenderizzare 90 secondi di video per riscrivere il
 * manifest. Vivendo qui, la cattura se lo calcola da sé e un ritocco ai testi
 * costa una giratura di 15 secondi.
 */

/** Secondi di cartello iniziale, prima del primo passo. */
export const INTRO = 2.6;

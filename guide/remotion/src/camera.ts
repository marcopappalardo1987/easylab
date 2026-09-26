import type { Fuoco, Passo } from './tipi';

export const LARGHEZZA = 1920;
export const ALTEZZA = 1080;
export const FPS = 30;

export type Inquadratura = { scala: number; tx: number; ty: number };

/** Il rettangolo da inquadrare in un passo: il suo fuoco, o la pagina intera. */
export const fuocoDi = (passo: Passo | null, w: number, h: number): Fuoco =>
  passo?.fuoco ?? { x: 0, y: 0, w, h };

/**
 * Trasforma che porta un rettangolo della pagina a riempire il fotogramma.
 *
 * `min` sui due assi invece di `max`: il rettangolo va mostrato TUTTO, e le
 * bande che restano ai lati sono il margine su cui poggia l'ombra della
 * finestra. Con `max` un fuoco più stretto del formato verrebbe tagliato.
 */
export const inquadra = (f: Fuoco): Inquadratura => {
  const scala = Math.min(LARGHEZZA / f.w, ALTEZZA / f.h);

  return {
    scala,
    tx: LARGHEZZA / 2 - (f.x + f.w / 2) * scala,
    ty: ALTEZZA / 2 - (f.y + f.h / 2) * scala,
  };
};

/**
 * Interpolazione fra due inquadrature. La scala si interpola in scala
 * LOGARITMICA: linearmente uno zoom da 1× a 3× sembra partire di scatto e
 * frenare, perché l'occhio legge i rapporti, non le differenze.
 */
export const fra = (a: Inquadratura, b: Inquadratura, t: number): Inquadratura => {
  const scala = Math.exp(Math.log(a.scala) + (Math.log(b.scala) - Math.log(a.scala)) * t);

  return {
    scala,
    tx: a.tx + (b.tx - a.tx) * t,
    ty: a.ty + (b.ty - a.ty) * t,
  };
};

/** Punto della pagina → punto del fotogramma. */
export const proietta = (p: { x: number; y: number }, i: Inquadratura) => ({
  x: p.x * i.scala + i.tx,
  y: p.y * i.scala + i.ty,
});

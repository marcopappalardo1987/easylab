export type Fuoco = { x: number; y: number; w: number; h: number };

export type Passo = {
  n: number;
  /** Assente sui cartelli: un capitolo non ha uno scatto suo. */
  file: string | null;
  didascalia: string;
  durata: number;
  fuoco: Fuoco | null;
  cursore: { x: number; y: number } | null;
  click: boolean;
  /** Cartello di sezione: sospende il racconto e annuncia che cosa viene ora. */
  inizio: number;
  capitolo: { titolo: string; occhiello: string } | null;
  /** Scheda di chiusura: il riepilogo con cui si esce. */
  chiusura: { titolo: string; punti: string[] } | null;
};

export type Manifest = {
  titolo: string;
  sottotitolo: string;
  durataTotale: number;
  larghezza: number;
  altezza: number;
  passi: Passo[];
};

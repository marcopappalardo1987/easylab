#!/usr/bin/env node
/**
 * Sintetizza il tema di sottofondo delle guide: `out/audio/tema.wav`.
 *
 * Generato invece che scaricato: una traccia di terzi, anche "royalty free",
 * porta con sé una licenza da rispettare su un video che finisce ai clienti.
 * È deterministica — rumore compreso, vedi `caso()` — quindi non si versiona.
 *
 * ⚠️ Storia delle due versioni precedenti, perché non si rifacciano gli stessi
 * errori cambiando stile:
 *
 *   1. Sinusoidi pure con inviluppo unico: suonava da organo giocattolo. Tre
 *      cose la sistemano, in ordine di resa — un **riverbero** (senza, i suoni
 *      restano appiccicati all'altoparlante), armoniche che si spengono a
 *      **velocità diverse** (è ciò che distingue una corda pizzicata da un
 *      fischio), e un **motivo** invece di un arpeggio che sale e scende.
 *   2. Quella versione, a 62 BPM, era corretta ma statica: un tappeto che non
 *      andava da nessuna parte sotto un video di 90 secondi.
 *
 * Questa è la terza: 100 BPM, ottavi che camminano, e una percussione appena
 * accennata. Il movimento sta nel RITMO, non nel volume — un letto da tutorial
 * resta a -35 dB e non deve mai chiedere attenzione.
 *
 * ⛔ Col ritmo il riverbero va ACCORCIATO (1,5s contro 2,6s): la coda lunga che
 * faceva respirare il tappeto lento impasta gli ottavi e li trasforma in poltiglia.
 *
 * È un anello CHIUSO: la coda oltre la fine — riverbero compreso, per questo il
 * ripiegamento viene DOPO — rientra all'inizio, così si ripete senza giunzione.
 */
import fs from 'node:fs';
import path from 'node:path';

const SR = 44100;
const CODA = 3;

/**
 * Gli stili disponibili. `passo` è quello in uso e NON va cambiato: gli altri
 * esistono per essere ascoltati sotto un video vero prima di decidere.
 *
 *   node bin/musica.mjs                 → out/audio/tema.wav        (passo)
 *   node bin/musica.mjs --stile=cristallo → out/audio/tema-cristallo.wav
 *
 * Campi: `giro` è la successione di accordi (0 = Do centrale, `pentatonica` è
 * la scala su cui pescano arpeggio e melodia); `passoBasso` e `passoArpeggio`
 * sono maschere di ottavi; `melodia` ha una riga per accordo, ognuna
 * `[battito, grado, durata]`.
 */
const STILI = {
  // Fa maggiore, I–V–vi–IV: il giro più camminante che ci sia.
  passo: {
    bpm: 100,
    battutePerAccordo: 2,
    percussione: true,
    riverbero: { decadimento: 1.5, send: 0.3 },
    ampiezze: { tappeto: 0.036, basso: 0.13, sub: 0.16, arpeggio: 0.06, melodia: 0.085, ottava: 0.022, charleston: 0.038 },
    giro: [
      { nome: 'F', accordo: [0, 5, 9, 12], basso: -12, pentatonica: [12, 14, 16, 17, 21] },
      { nome: 'C/E', accordo: [0, 4, 7, 12], basso: -8, pentatonica: [12, 16, 19, 21, 24] },
      { nome: 'Dm7', accordo: [0, 2, 5, 9], basso: -10, pentatonica: [14, 17, 21, 24, 26] },
      { nome: 'Bb', accordo: [-3, 2, 5, 10], basso: -14, pentatonica: [10, 14, 17, 21, 22] },
    ],
    passoBasso: [1, 0, 1, 0, 0, 1, 0, 1],
    passoArpeggio: [0, 2, 1, 3, 2, 4, 3, 2],
    melodia: [
      [[0, 3, 1.2], [1.5, 4, 0.9], [3, 2, 1.4], [5.5, 3, 1.1]],
      [[0.5, 4, 1.0], [2, 3, 1.2], [4, 2, 1.0], [5.5, 1, 1.3]],
      [[0, 2, 1.1], [1.5, 3, 0.9], [3.5, 4, 1.2], [6, 3, 1.0]],
      [[0.5, 3, 1.0], [2, 2, 1.1], [3.5, 1, 0.9], [5, 2, 1.6]],
    ],
  },

  // Lo stesso giro partendo dal RELATIVO MINORE (vi–IV–I–V): stessi accordi,
  // colore più serio. Più veloce e con la melodia ridotta all'osso, perché qui
  // a raccontare è il passo, non il motivo.
  officina: {
    bpm: 112,
    battutePerAccordo: 2,
    percussione: true,
    riverbero: { decadimento: 1.25, send: 0.24 },
    ampiezze: { tappeto: 0.04, basso: 0.15, sub: 0.18, arpeggio: 0.055, melodia: 0.07, ottava: 0.018, charleston: 0.042 },
    giro: [
      { nome: 'Dm7', accordo: [0, 2, 5, 9], basso: -10, pentatonica: [14, 17, 19, 21, 26] },
      { nome: 'Bb', accordo: [-3, 2, 5, 10], basso: -14, pentatonica: [10, 14, 17, 21, 22] },
      { nome: 'F', accordo: [0, 5, 9, 12], basso: -12, pentatonica: [12, 14, 17, 21, 24] },
      { nome: 'C', accordo: [0, 4, 7, 12], basso: -8, pentatonica: [12, 16, 19, 21, 24] },
    ],
    passoBasso: [1, 0, 1, 1, 0, 1, 0, 1],
    passoArpeggio: [0, 2, 4, 2, 1, 3, 2, 4],
    melodia: [
      [[0, 2, 1.6], [3, 3, 1.4]],
      [[0.5, 3, 1.4], [3.5, 2, 1.6]],
      [[0, 4, 1.5], [3, 2, 1.5]],
      [[1, 3, 1.3], [4, 1, 2.0]],
    ],
  },

  // Niente percussione, accordi lunghi il doppio, riverbero che tiene: il
  // silenzio fra una nota e l'altra diventa parte della musica. È il letto che
  // non chiede mai attenzione, al prezzo di non spingere.
  cristallo: {
    bpm: 76,
    battutePerAccordo: 4,
    percussione: false,
    riverbero: { decadimento: 2.9, send: 0.44 },
    ampiezze: { tappeto: 0.05, basso: 0.1, sub: 0.1, arpeggio: 0.042, melodia: 0.075, ottava: 0.02, charleston: 0 },
    giro: [
      { nome: 'Fmaj7', accordo: [0, 4, 9, 16], basso: -12, pentatonica: [16, 19, 21, 24, 28] },
      { nome: 'Dm7', accordo: [0, 2, 5, 9], basso: -10, pentatonica: [14, 17, 21, 24, 26] },
      { nome: 'Bbmaj7', accordo: [-3, 2, 5, 9], basso: -14, pentatonica: [14, 17, 21, 22, 26] },
      { nome: 'C', accordo: [0, 4, 7, 12], basso: -8, pentatonica: [12, 16, 19, 21, 24] },
    ],
    passoBasso: [1, 0, 0, 0, 0, 0, 1, 0],
    passoArpeggio: [0, 2, 4, 3, 1, 3, 2, 4],
    melodia: [
      [[0, 4, 3.2], [5, 3, 2.6]],
      [[1, 3, 3.0], [6, 2, 2.4]],
      [[0.5, 4, 3.4], [5.5, 2, 2.2]],
      [[2, 2, 2.8], [7, 3, 3.0]],
    ],
  },

  // Armonia quasi ferma — accordi sospesi, una battuta ciascuno — e basso
  // sincopato: l'energia viene dal ritmo, non dai cambi. È il taglio più da
  // demo di prodotto, e il più facile da stancarsi di ascoltare.
  motore: {
    bpm: 118,
    battutePerAccordo: 1,
    percussione: true,
    riverbero: { decadimento: 1.05, send: 0.2 },
    ampiezze: { tappeto: 0.034, basso: 0.16, sub: 0.2, arpeggio: 0.05, melodia: 0.062, ottava: 0.016, charleston: 0.045 },
    giro: [
      { nome: 'Fsus2', accordo: [0, 2, 7, 12], basso: -12, pentatonica: [12, 14, 17, 19, 24] },
      { nome: 'Bbsus2', accordo: [-2, 0, 5, 10], basso: -14, pentatonica: [10, 12, 17, 19, 22] },
      { nome: 'Dm9', accordo: [-3, 2, 5, 9], basso: -10, pentatonica: [14, 17, 19, 21, 26] },
      { nome: 'Csus4', accordo: [0, 5, 7, 12], basso: -8, pentatonica: [12, 17, 19, 21, 24] },
    ],
    passoBasso: [1, 0, 0, 1, 0, 1, 1, 0],
    passoArpeggio: [4, 2, 3, 1, 4, 2, 3, 0],
    melodia: [[[0, 4, 0.9]], [[1.5, 3, 0.9]], [[0, 2, 1.0]], [[1.5, 4, 1.2]]],
  },
};

const nomeStile = (process.argv.find((a) => a.startsWith('--stile=')) ?? '--stile=passo').slice(8);
const S = STILI[nomeStile];
if (!S) {
  console.error(`stile sconosciuto: ${nomeStile} — disponibili: ${Object.keys(STILI).join(', ')}`);
  process.exit(1);
}

const BPM = S.bpm;
const BATTITO = 60 / BPM;
const BATTUTA = BATTITO * 4;
const BATTUTE_PER_ACCORDO = S.battutePerAccordo;
const GIRO = S.giro;
const PASSO_BASSO = S.passoBasso;
const PASSO_ARPEGGIO = S.passoArpeggio;
const MELODIA = S.melodia;
const AMP = S.ampiezze;

const hz = (semitoni) => 440 * 2 ** ((semitoni - 9) / 12);

/** Rumore deterministico (mulberry32): il tema non deve cambiare a ogni build. */
let seme = 0x9e3779b9;
const caso = () => {
  seme = (seme + 0x6d2b79f5) >>> 0;
  let x = seme;
  x = Math.imul(x ^ (x >>> 15), x | 1);
  x ^= x + Math.imul(x ^ (x >>> 7), x | 61);
  return (((x ^ (x >>> 14)) >>> 0) / 4294967296) * 2 - 1;
};

const DURATA = GIRO.length * BATTUTE_PER_ACCORDO * BATTUTA;
const n = Math.round((DURATA + CODA) * SR);
const asciutto = [new Float64Array(n), new Float64Array(n)];

const somma = (i, v, pan) => {
  asciutto[0][i] += v * (1 - pan);
  asciutto[1][i] += v * pan;
};

/**
 * Corda percossa. Le armoniche alte si spengono più in fretta delle basse: è
 * quello che dà il colpo iniziale e poi il tondo. Due copie stonate fanno il
 * battimento che toglie la fissità.
 */
const nota = (t0, semitoni, ampiezza, decadimento, pan = 0.5, armoniche = [1, 0.46, 0.26, 0.13, 0.07, 0.035]) => {
  const f = hz(semitoni);
  const i0 = Math.round(t0 * SR);
  const i1 = Math.min(n, Math.round((t0 + decadimento * 3.2) * SR));

  for (let i = i0; i < i1; i++) {
    const t = (i - i0) / SR;
    let v = 0;
    for (let k = 0; k < armoniche.length; k++) {
      const spegnimento = Math.exp(-t / (decadimento / (1 + k * 0.75)));
      // Inarmonicità: le parziali di una corda vera stanno un filo sopra i
      // multipli esatti. Pochissima, ma senza suona sintetico.
      const fk = f * (k + 1) * (1 + 0.00035 * k * k);
      v += armoniche[k] * spegnimento * (Math.sin(2 * Math.PI * fk * t) + Math.sin(2 * Math.PI * fk * 1.0012 * t + 0.7)) * 0.5;
    }
    somma(i, v * ampiezza * Math.min(1, t / 0.005), pan);
  }
};

/** Tappeto: dente di sega addolcito, entrata lenta, uscita lunga. */
const tappeto = (t0, durata, semitoni, ampiezza, pan = 0.5) => {
  const f = hz(semitoni);
  const i0 = Math.round(t0 * SR);
  const i1 = Math.min(n, Math.round((t0 + durata + 2.5) * SR));

  for (let i = i0; i < i1; i++) {
    const t = (i - i0) / SR;
    const entrata = Math.min(1, t / 0.8) ** 2;
    const uscita = t > durata ? Math.max(0, 1 - (t - durata) / 2.2) ** 2 : 1;
    const e = entrata * uscita;
    if (e <= 0.0002) continue;

    let v = 0;
    // 1/k² invece di 1/k: il dente di sega puro è troppo acido per un tappeto.
    for (let k = 1; k <= 8; k++) v += (1 / (k * k)) * Math.sin(2 * Math.PI * f * k * t + k * 0.9);
    const coro = Math.sin(2 * Math.PI * f * (1 + 0.0009 * Math.sin(2 * Math.PI * 0.15 * t)) * t);
    somma(i, (v * 0.72 + coro * 0.28) * e * ampiezza, pan);
  }
};

/** Sub del basso: sinusoide con caduta di intonazione, dà il colpo senza cassa. */
const impulso = (t0, semitoni, ampiezza) => {
  const i0 = Math.round(t0 * SR);
  const i1 = Math.min(n, Math.round((t0 + 0.45) * SR));
  let fase = 0;

  for (let i = i0; i < i1; i++) {
    const t = (i - i0) / SR;
    const f = hz(semitoni) * (1 + 0.7 * Math.exp(-t / 0.03));
    fase += (2 * Math.PI * f) / SR;
    somma(i, Math.sin(fase) * ampiezza * Math.exp(-t / 0.16) * Math.min(1, t / 0.004), 0.5);
  }
};

/** Charleston: rumore differenziato (cioè acuto) con coda cortissima. */
const battuto = (t0, ampiezza, pan) => {
  const i0 = Math.round(t0 * SR);
  const i1 = Math.min(n, Math.round((t0 + 0.09) * SR));
  let precedente = 0;

  for (let i = i0; i < i1; i++) {
    const t = (i - i0) / SR;
    const r = caso();
    const acuto = r - precedente;
    precedente = r;
    somma(i, acuto * ampiezza * Math.exp(-t / 0.018), pan);
  }
};

for (let g = 0; g < GIRO.length; g++) {
  const t0 = g * BATTUTE_PER_ACCORDO * BATTUTA;
  const durata = BATTUTE_PER_ACCORDO * BATTUTA;
  const { accordo, basso, pentatonica } = GIRO[g];
  const ottavo = BATTITO / 2;
  const ottavi = Math.round(durata / ottavo);

  accordo.forEach((s, k) => tappeto(t0, durata * 0.94, s, AMP.tappeto, 0.5 + (k % 2 ? 0.2 : -0.2)));

  for (let o = 0; o < ottavi; o++) {
    const t = t0 + o * ottavo;

    if (PASSO_BASSO[o % PASSO_BASSO.length]) {
      nota(t, basso, AMP.basso, 0.5, 0.5, [1, 0.35, 0.12]);
      if (o % 4 === 0) impulso(t, basso - 12, AMP.sub);
    }

    // Arpeggio: decadimento corto, o gli ottavi si sovrappongono e impastano.
    const grado = PASSO_ARPEGGIO[o % PASSO_ARPEGGIO.length];
    nota(t, pentatonica[grado], AMP.arpeggio, 0.28, o % 2 ? 0.62 : 0.38);

    // Charleston sui levare, con un accento leggero ogni due battute.
    if (S.percussione && o % 2 === 1) battuto(t, AMP.charleston + (o % 8 === 7 ? 0.022 : 0), 0.5 + (o % 4 === 1 ? 0.18 : -0.18));
  }

  for (const [b, grado, durataNota] of MELODIA[g]) {
    nota(t0 + b * BATTITO, pentatonica[grado], AMP.melodia, durataNota * 0.55, 0.45);
    nota(t0 + b * BATTITO + 0.022, pentatonica[grado] + 12, AMP.ottava, durataNota * 0.4, 0.6);
  }
}

/**
 * Riverbero di Schroeder: quattro pettini in parallelo, tre passa-tutto in
 * serie. Non è una sala vera, ma è la differenza fra «suoni sintetizzati» e
 * «musica registrata da qualche parte».
 */
const riverbero = (canale, ritardi, decadimento) => {
  const uscita = new Float64Array(canale.length);

  for (const d of ritardi) {
    const buf = new Float64Array(d);
    const g = 10 ** ((-3 * d) / (decadimento * SR)); // -60 dB in `decadimento` secondi
    let p = 0;
    for (let i = 0; i < canale.length; i++) {
      const v = buf[p];
      uscita[i] += v / ritardi.length;
      buf[p] = canale[i] + v * g;
      p = (p + 1) % d;
    }
  }

  for (const [d, g] of [[556, 0.5], [441, 0.5], [341, 0.5]]) {
    const buf = new Float64Array(d);
    let p = 0;
    for (let i = 0; i < uscita.length; i++) {
      const v = buf[p];
      const y = -g * uscita[i] + v;
      buf[p] = uscita[i] + g * v;
      uscita[i] = y;
      p = (p + 1) % d;
    }
  }

  return uscita;
};

const SEND = S.riverbero.send;
const bagnato = [
  riverbero(asciutto[0], [1557, 1617, 1491, 1422], S.riverbero.decadimento),
  riverbero(asciutto[1], [1580, 1640, 1514, 1445], S.riverbero.decadimento),
];

const lung = Math.round(DURATA * SR);
const buf = Buffer.alloc(lung * 4);
const alfa = 1 - Math.exp((-2 * Math.PI * 6000) / SR);
const filtro = [0, 0];

for (let c = 0; c < 2; c++) {
  const mix = new Float64Array(n);
  for (let i = 0; i < n; i++) mix[i] = asciutto[c][i] + bagnato[c][i] * SEND;

  // Ripiegamento DOPO il riverbero: è la sua coda che deve rientrare, altrimenti
  // all'anello successivo l'ultimo accordo si tronca di netto.
  for (let i = 0; i + lung < n; i++) mix[i] += mix[i + lung];

  for (let i = 0; i < lung; i++) {
    filtro[c] += alfa * (mix[i] - filtro[c]);
    const v = Math.tanh(filtro[c] * 1.3) * 0.9;
    buf.writeInt16LE(Math.round(Math.max(-1, Math.min(1, v)) * 32767), i * 4 + c * 2);
  }
}

const testa = Buffer.alloc(44);
testa.write('RIFF', 0);
testa.writeUInt32LE(36 + buf.length, 4);
testa.write('WAVEfmt ', 8);
testa.writeUInt32LE(16, 16);
testa.writeUInt16LE(1, 20);
testa.writeUInt16LE(2, 22);
testa.writeUInt32LE(SR, 24);
testa.writeUInt32LE(SR * 4, 28);
testa.writeUInt16LE(4, 32);
testa.writeUInt16LE(16, 34);
testa.write('data', 36);
testa.writeUInt32LE(buf.length, 40);

fs.mkdirSync(path.join('out', 'audio'), { recursive: true });
const uscita = nomeStile === 'passo' ? 'tema.wav' : `tema-${nomeStile}.wav`;
fs.writeFileSync(path.join('out', 'audio', uscita), Buffer.concat([testa, buf]));
console.log(`${uscita} — ${BPM} BPM, anello di ${DURATA.toFixed(2)}s, ${GIRO.map((g) => g.nome).join(' · ')}`);

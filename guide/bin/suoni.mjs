#!/usr/bin/env node
/**
 * Gli effetti sonori delle guide: `out/audio/sfx/*.wav`.
 *
 * Sintetizzati come la musica (`bin/musica.mjs`), e per lo stesso motivo: una
 * libreria di effetti, anche gratuita, porta con sé una licenza da rispettare su
 * video che finiscono ai clienti.
 *
 * ⚠️ La regola che tiene in piedi il risultato non è di sintesi ma di
 * montaggio: **un suono marca una cosa che si VEDE** — un click che avviene,
 * una tendina che passa, una pagina che scorre. Un effetto che non corrisponde
 * a niente sullo schermo si nota come rumore, e dopo tre guide dà fastidio. Per
 * questo sono pochi e corti: sei file, nessuno oltre il secondo e mezzo.
 *
 * Tutti a -20 dB circa: stanno sotto la voce e appena sopra la musica.
 */
import fs from 'node:fs';
import path from 'node:path';

const SR = 44100;

/** Rumore deterministico (mulberry32): gli effetti non cambiano a ogni build. */
let seme = 0x1a2b3c4d;
const caso = () => {
  seme = (seme + 0x6d2b79f5) >>> 0;
  let x = seme;
  x = Math.imul(x ^ (x >>> 15), x | 1);
  x ^= x + Math.imul(x ^ (x >>> 7), x | 61);

  return (((x ^ (x >>> 14)) >>> 0) / 4294967296) * 2 - 1;
};

const tela = (secondi) => [new Float64Array(Math.round(secondi * SR)), new Float64Array(Math.round(secondi * SR))];

const metti = (t, i, v, pan = 0.5) => {
  if (i < 0 || i >= t[0].length) return;
  t[0][i] += v * (1 - pan);
  t[1][i] += v * pan;
};

/** Sinusoide con decadimento esponenziale: il mattone di ogni suono corto. */
const tono = (t, t0, f, ampiezza, decadimento, pan = 0.5, curvaturaF = 0) => {
  const i0 = Math.round(t0 * SR);
  let fase = 0;
  for (let i = i0; i < Math.min(t[0].length, i0 + Math.round(decadimento * 6 * SR)); i++) {
    const s = (i - i0) / SR;
    fase += (2 * Math.PI * (f * (1 + curvaturaF * Math.exp(-s / 0.02)))) / SR;
    metti(t, i, Math.sin(fase) * ampiezza * Math.exp(-s / decadimento) * Math.min(1, s / 0.0008), pan);
  }
};

/**
 * Rumore filtrato: `taglio` alto = aria, basso = soffio. Un passa-basso a un
 * polo, che per un effetto di mezzo secondo basta e avanza.
 */
const soffio = (t, t0, durata, ampiezza, taglio, forma, pan = 0.5) => {
  const i0 = Math.round(t0 * SR);
  const n = Math.round(durata * SR);
  let filtro = 0;
  for (let i = 0; i < n; i++) {
    const s = i / SR;
    const q = s / durata;
    const alfa = 1 - Math.exp((-2 * Math.PI * taglio(q)) / SR);
    filtro += alfa * (caso() - filtro);
    metti(t, i0 + i, filtro * ampiezza * forma(q), pan);
  }
};

const scrivi = (nome, t) => {
  const n = t[0].length;
  const dati = Buffer.alloc(n * 4);
  for (let i = 0; i < n; i++) {
    for (let c = 0; c < 2; c++) {
      const v = Math.tanh(t[c][i] * 1.2);
      dati.writeInt16LE(Math.round(Math.max(-1, Math.min(1, v)) * 32767), i * 4 + c * 2);
    }
  }

  const testa = Buffer.alloc(44);
  testa.write('RIFF', 0);
  testa.writeUInt32LE(36 + dati.length, 4);
  testa.write('WAVEfmt ', 8);
  testa.writeUInt32LE(16, 16);
  testa.writeUInt16LE(1, 20);
  testa.writeUInt16LE(2, 22);
  testa.writeUInt32LE(SR, 24);
  testa.writeUInt32LE(SR * 4, 28);
  testa.writeUInt16LE(4, 32);
  testa.writeUInt16LE(16, 34);
  testa.write('data', 36);
  testa.writeUInt32LE(dati.length, 40);

  const cartella = path.join('out', 'audio', 'sfx');
  fs.mkdirSync(cartella, { recursive: true });
  fs.writeFileSync(path.join(cartella, `${nome}.wav`), Buffer.concat([testa, dati]));
  console.log(`${nome}.wav — ${(n / SR).toFixed(2)}s`);
};

/* ── click: due transienti, come un pulsante vero ───────────────────────────
 * Il secondo (il rilascio) è più corto e più piano del primo: è quello che
 * distingue un click da un «toc», e la distanza fra i due è la sola cosa che
 * lo fa sembrare meccanico o gommoso. 38 ms. */
{
  const t = tela(0.22);
  const colpo = (t0, a) => {
    soffio(t, t0, 0.012, a * 0.9, () => 7000, (q) => Math.exp(-q * 7));
    tono(t, t0, 2100, a * 0.5, 0.008, 0.5, 0.4);
    tono(t, t0, 950, a * 0.35, 0.02);
  };
  colpo(0, 0.5);
  colpo(0.038, 0.3);
  scrivi('click', t);
}

/* ── tastiera: dieci battute irregolari ─────────────────────────────────────
 * Gli intervalli NON sono uguali (130 ms ± 40): a passo costante non suona una
 * persona che scrive, suona una stampante. */
{
  const t = tela(1.6);
  let q = 0.02;
  for (let k = 0; k < 10; k++) {
    const a = 0.3 + caso() * 0.08;
    soffio(t, q, 0.01, a, () => 6200 + caso() * 1200, (u) => Math.exp(-u * 9), 0.5 + caso() * 0.14);
    tono(t, q, 1500 + caso() * 400, a * 0.35, 0.01);
    q += 0.13 + caso() * 0.04;
  }
  scrivi('tastiera', t);
}

/* ── stacco: la tendina del cartello ────────────────────────────────────────
 * Rumore che sale di frequenza mentre il pannello entra, e un colpo basso
 * quando si ferma. Il colpo è ciò che dà il peso: senza, la tendina «passa» ma
 * non «arriva». */
{
  const t = tela(0.9);
  soffio(t, 0, 0.5, 0.34, (q) => 600 + 5200 * q, (q) => Math.sin(Math.PI * q) ** 1.4);
  tono(t, 0.42, 120, 0.42, 0.11, 0.5, 0.9);
  tono(t, 0.42, 62, 0.3, 0.16);
  scrivi('stacco', t);
}

/* ── apertura: il marchio che si posa ───────────────────────────────────────
 * Quinta e ottava sopra la fondamentale, entrata lenta, campanella che resta.
 * È l'unico suono che può permettersi due secondi, perché sotto non c'è ancora
 * niente da ascoltare. */
{
  const t = tela(2.4);
  soffio(t, 0, 1.1, 0.12, (q) => 800 + 3000 * q, (q) => q ** 2 * (1 - q) * 4);
  for (const [f, a, d, r] of [[349.2, 0.16, 1.1, 0.0], [523.3, 0.12, 0.9, 0.06], [698.5, 0.09, 0.8, 0.12]]) {
    tono(t, 0.55 + r, f, a, d, 0.5);
  }
  scrivi('apertura', t);
}

/* ── conferma: due note in salita, per la scheda finale ─────────────────── */
{
  const t = tela(1.4);
  tono(t, 0, 523.3, 0.17, 0.22, 0.42);
  tono(t, 0.13, 784.0, 0.15, 0.42, 0.58);
  tono(t, 0.13, 1568.0, 0.04, 0.3, 0.58);
  scrivi('conferma', t);
}

/* ── scorrimento: aria che passa, senza inizio né fine netti ─────────────── */
{
  const t = tela(1.5);
  soffio(t, 0, 1.5, 0.16, (q) => 1200 + 2600 * Math.sin(Math.PI * q), (q) => Math.sin(Math.PI * q) ** 2);
  scrivi('scorrimento', t);
}

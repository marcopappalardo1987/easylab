#!/usr/bin/env node
// Monta una guida: legge il manifest prodotto da Playwright e renderizza l'mp4.
//   node bin/monta.mjs intervento
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const slug = process.argv[2] ?? 'intervento';
const manifest = JSON.parse(fs.readFileSync(path.join('out', slug, 'manifest.json'), 'utf8'));
// Il marchio viene dal repo, non da una copia: se in `public/brand/` cambia,
// il prossimo montaggio lo prende. Variante per FONDO SCURO — quella normale
// ha il blu profondo che su #111C2E fa 1,9:1 e sparisce (ADR-033/034).
fs.mkdirSync(path.join('out', 'brand'), { recursive: true });
for (const [origine, destinazione] of [
  ['easylab-logo-scuro.svg', 'logo.svg'],            // col payoff, per i cartelli
  ['easylab-logo-compatto-scuro.svg', 'logo-compatto.svg'], // solo lettering
]) {
  fs.copyFileSync(path.join('..', 'public', 'brand', origine), path.join('out', 'brand', destinazione));
}

// L'istante d'inizio di ogni passo lo sa SOLO il montaggio, perché dipende
// dalla durata della testata. Si scrive nel manifest invece di ricalcolarlo
// altrove: la pagina della Guida ci aggancia i salti nel video, e due formule
// in due linguaggi diversi divergerebbero al primo ritocco della testata.
const INTRO = 2.6;
let cursore = INTRO;
for (const passo of manifest.passi) {
  passo.inizio = Number(cursore.toFixed(2));
  cursore += passo.durata;
}
manifest.durataTotale = Number(cursore.toFixed(2));
fs.writeFileSync(path.join('out', slug, 'manifest.json'), JSON.stringify(manifest, null, 2));

const props = path.join('out', slug, '.props.json');
fs.writeFileSync(props, JSON.stringify({ slug, manifest }));

const comune = ['--props', props, '--public-dir', 'out', '--config', 'remotion/remotion.config.ts'];

execFileSync(
  'npx',
  ['remotion', 'render', 'remotion/src/index.ts', 'Guida', `out/${slug}/${slug}.mp4`, ...comune, '--log', 'info'],
  { stdio: 'inherit' },
);

/*
 * La copertina, cioè quel che il lettore mostra da fermo.
 *
 * ⛔ Non si può prendere dal fotogramma zero, e le due strade sbagliate sono
 * state percorse entrambe: col cartello del titolo in dissolvenza, a zero si
 * vedeva **lo scatto nudo** della prima schermata, cioè la guida sembrava
 * cominciare a metà; rendendo opaco il fondo della testata, a zero è rimasto un
 * **rettangolo vuoto**, perché il contenuto entra con la molla. Il fotogramma
 * buono è quello a testata finita di entrare — qui 1,5s.
 */
execFileSync(
  'npx',
  ['remotion', 'still', 'remotion/src/index.ts', 'Guida', `out/${slug}/copertina.jpg`,
   ...comune, '--frame', '45', '--image-format', 'jpeg', '--log', 'error'],
  { stdio: 'inherit' },
);

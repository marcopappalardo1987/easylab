#!/usr/bin/env node
// Monta il formato «vetrina»: legge out/<slug>/copione.json e renderizza l'mp4.
//   node bin/monta-vetrina.mjs vetrina [props-voce.json]
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const slug = process.argv[2] ?? 'vetrina';
const voci = process.argv[3] ? JSON.parse(fs.readFileSync(process.argv[3], 'utf8')).voci : undefined;
const copione = JSON.parse(fs.readFileSync(path.join('out', slug, 'copione.json'), 'utf8'));

// Il marchio arriva dal repo, non da una copia: variante per fondo scuro.
fs.mkdirSync(path.join('out', 'brand'), { recursive: true });
for (const [origine, destinazione] of [
  ['easylab-logo-scuro.svg', 'logo.svg'],
  ['easylab-logo-compatto-scuro.svg', 'logo-compatto.svg'],
]) {
  fs.copyFileSync(path.join('..', 'public', 'brand', origine), path.join('out', 'brand', destinazione));
}

// ⛔ Il lessico si controlla PRIMA di montare, non dopo: una parola sbagliata
// scoperta guardando il video costa la voce già sintetizzata e pagata.
execFileSync('node', ['bin/lessico.mjs'], { stdio: 'inherit' });

// Gli effetti sono generati, non versionati: su un clone pulito mancherebbero
// e il montaggio fallirebbe su un file che non c'è.
if (!fs.existsSync(path.join('out', 'audio', 'sfx', 'click.wav'))) {
  execFileSync('node', ['bin/suoni.mjs'], { stdio: 'inherit' });
}

const props = path.join('out', slug, '.props-vetrina.json');
fs.writeFileSync(props, JSON.stringify({ slug, copione, ...(voci ? { voci } : {}) }));

const comune = ['--props', props, '--public-dir', 'out', '--config', 'remotion/remotion.config.ts'];

execFileSync('npx', [
  'remotion', 'render', 'remotion/src/index.ts', 'Vetrina', `out/${slug}/${slug}.mp4`,
  ...comune, '--concurrency', '4', '--log', 'info',
], { stdio: 'inherit' });

/*
 * La copertina: quel che il lettore mostra da fermo.
 *
 * ⛔ Non il fotogramma zero — lì la testata sta ancora entrando e si vedrebbe
 * un rettangolo mezzo vuoto. Si prende a testata finita, 2,2s.
 */
execFileSync('npx', [
  'remotion', 'still', 'remotion/src/index.ts', 'Vetrina', `out/${slug}/copertina.jpg`,
  ...comune, '--frame', '66', '--image-format', 'jpeg', '--log', 'error',
], { stdio: 'inherit' });

/*
 * Il `manifest.json` che l'APPLICAZIONE legge (🔗 App\Support\Guide\Manuale).
 *
 * Il copione del formato nuovo non è il manifest: descrive scene e materiali,
 * mentre la pagina della guida vuole l'elenco dei passi coi loro istanti, per
 * poter cliccare una riga scritta e saltare al secondo giusto del video.
 *
 * ⚠️ Si genera QUI e non alla cattura, perché gli istanti veri si conoscono
 * solo dopo: la voce allunga le scene, e un manifest scritto prima manderebbe
 * ogni salto qualche secondo più in là.
 */
const passi = [];
let istante = 0;
let n = 0;
for (const scena of copione.scene) {
  if (scena.tipo === 'scatto' || scena.tipo === 'ripresa') {
    passi.push({
      n: ++n,
      file: scena.file,
      didascalia: scena.didascalia,
      durata: scena.durata,
      inizio: Number(istante.toFixed(2)),
      capitolo: null,
      chiusura: null,
    });
  } else if (scena.tipo === 'cartello') {
    passi.push({
      n: 0,
      file: null,
      didascalia: '',
      durata: scena.durata,
      inizio: Number(istante.toFixed(2)),
      capitolo: { titolo: scena.parola, occhiello: scena.occhiello },
      chiusura: null,
    });
  } else if (scena.tipo === 'chiusura') {
    passi.push({
      n: 0,
      file: null,
      didascalia: '',
      durata: scena.durata,
      inizio: Number(istante.toFixed(2)),
      capitolo: null,
      chiusura: { titolo: scena.titolo, punti: scena.punti },
    });
  }
  istante += scena.durata;
}

fs.writeFileSync(
  path.join('out', slug, 'manifest.json'),
  JSON.stringify(
    {
      titolo: copione.titolo,
      sottotitolo: copione.sottotitolo,
      durataTotale: Number(istante.toFixed(2)),
      larghezza: copione.larghezza,
      altezza: copione.altezza,
      passi,
    },
    null,
    2,
  ),
);
console.log(`manifest.json — ${n} passi, ${istante.toFixed(1)}s`);

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

const props = path.join('out', slug, '.props-vetrina.json');
fs.writeFileSync(props, JSON.stringify({ slug, copione, ...(voci ? { voci } : {}) }));

execFileSync('npx', [
  'remotion', 'render', 'remotion/src/index.ts', 'Vetrina', `out/${slug}/${slug}.mp4`,
  '--props', props, '--public-dir', 'out', '--config', 'remotion/remotion.config.ts',
  '--concurrency', '4', '--log', 'info',
], { stdio: 'inherit' });

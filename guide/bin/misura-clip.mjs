#!/usr/bin/env node
// Confronta la durata vera di ogni clip con quella scritta nel copione.
//   node bin/misura-clip.mjs fornitori   → esce con 1 se una clip è corta
//
// ⚠️ Le clip sono uscite di una frazione della loro durata (0,6 s invece di 4,6),
// perdendo la fine dell'azione, finché `lib/ripresa.ts` passava a ffmpeg 9
// `-r 30 -fps_mode cfr` (7 Nov 2026). Il montaggio non se ne accorgerebbe: questa
// misura resta, perché la stessa cosa può tornare con la prossima versione.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const slug = process.argv[2];
const copione = JSON.parse(fs.readFileSync(path.join('out', slug, 'copione.json'), 'utf8'));
const corte = copione.scene
  .filter((s) => s.tipo === 'ripresa')
  .map((s) => {
    const vera = Number(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path.join('out', slug, s.file)]).toString());
    return { file: s.file, vera, attesa: s.durata };
  })
  .filter((c) => c.vera < c.attesa * 0.9);

for (const c of corte) console.error(`clip corta: ${slug}/${c.file} ${c.vera.toFixed(2)}s su ${c.attesa.toFixed(2)}s`);
process.exit(corte.length ? 1 : 0);

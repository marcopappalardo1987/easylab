#!/usr/bin/env node
/**
 * Voce narrante con ElevenLabs.
 *
 *   node bin/voce.mjs vetrina testi/vetrina-voce.json [--voce=<id>]
 *   node bin/voce.mjs --voci            # elenca le voci disponibili
 *
 * Produce `out/<slug>/voce/NN.wav` (NN = indice della scena nel copione),
 * allunga le scene che non stanno dentro il parlato e scrive
 * `out/<slug>/.voci.json` da passare a `bin/monta-vetrina.mjs`.
 *
 * ⚠️ La chiave sta in `.env` alla radice del progetto (`ELEVENLABS_API_KEY`) e
 * non passa mai dalla riga di comando: finirebbe nella cronologia della shell.
 *
 * ⚠️ `previous_text` / `next_text` non sono un dettaglio: senza, ogni frase
 * viene sintetizzata come se fosse l'unica al mondo e riparte ogni volta dalla
 * stessa intonazione neutra — è esattamente il «meccanico» che si sente quando
 * si incollano dieci clip generate a parte. Dando alla frase il suo contesto, il
 * modello chiude la cadenza di quella prima e apre quella dopo.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const API = 'https://api.elevenlabs.io/v1';
const MODELLO = 'eleven_multilingual_v2';
/** Rachel non parla italiano: la voce si sceglie con `--voci` e si fissa qui. */
const VOCE_PREDEFINITA = process.env.ELEVENLABS_VOICE_ID ?? '';

const chiave = () => {
  const env = fs.readFileSync(path.join('..', '.env'), 'utf8');
  const riga = env.split('\n').find((r) => r.startsWith('ELEVENLABS_API_KEY='));
  if (!riga) throw new Error('ELEVENLABS_API_KEY manca in .env');

  return riga.slice('ELEVENLABS_API_KEY='.length).trim().replace(/^["']|["']$/g, '');
};

const elencaVoci = async () => {
  const r = await fetch(`${API}/voices`, { headers: { 'xi-api-key': chiave() } });
  const { voices } = await r.json();
  for (const v of voices) {
    const lingue = (v.verified_languages ?? []).map((l) => l.language).join(',');
    console.log(`${v.voice_id}  ${v.name.padEnd(22)} ${v.labels?.accent ?? ''} ${v.labels?.description ?? ''} ${lingue}`);
  }
};

const sintetizza = async (testo, prima, dopo, voce, destinazione) => {
  const r = await fetch(`${API}/text-to-speech/${voce}?output_format=mp3_44100_128`, {
    method: 'POST',
    headers: { 'xi-api-key': chiave(), 'content-type': 'application/json' },
    body: JSON.stringify({
      text: testo,
      model_id: MODELLO,
      previous_text: prima,
      next_text: dopo,
      voice_settings: {
        // Stabilità bassa = più variazione fra una frase e l'altra, che è ciò
        // che distingue una persona da un lettore automatico. Troppo bassa
        // (<0,3) e il modello comincia a inventarsi enfasi che non c'entrano.
        stability: 0.38,
        similarity_boost: 0.75,
        style: 0.32,
        use_speaker_boost: true,
      },
    }),
  });
  if (!r.ok) throw new Error(`ElevenLabs ${r.status}: ${await r.text()}`);

  const mp3 = `${destinazione}.mp3`;
  fs.writeFileSync(mp3, Buffer.from(await r.arrayBuffer()));
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', mp3, '-ar', '48000', '-ac', '2', destinazione]);
  fs.rmSync(mp3);

  return Number(
    execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', destinazione])
      .toString()
      .trim(),
  );
};

const main = async () => {
  if (process.argv.includes('--voci')) return elencaVoci();

  const slug = process.argv[2];
  const testiFile = process.argv[3];
  const voce = (process.argv.find((a) => a.startsWith('--voce=')) ?? `--voce=${VOCE_PREDEFINITA}`).slice(7);
  if (!slug || !testiFile || !voce) {
    console.error('uso: node bin/voce.mjs <slug> <testi.json> --voce=<id>');
    process.exit(1);
  }

  const cartella = path.join('out', slug);
  const copione = JSON.parse(fs.readFileSync(path.join(cartella, 'copione.json'), 'utf8'));
  const testi = JSON.parse(fs.readFileSync(testiFile, 'utf8'));
  const indici = Object.keys(testi).map(Number).sort((a, b) => a - b);

  fs.mkdirSync(path.join(cartella, 'voce'), { recursive: true });

  const voci = [];
  let caratteri = 0;
  for (let k = 0; k < indici.length; k++) {
    const i = indici[k];
    const file = path.join(cartella, 'voce', `${String(i).padStart(2, '0')}.wav`);
    const durata = await sintetizza(
      testi[i],
      k > 0 ? testi[indici[k - 1]] : '',
      k + 1 < indici.length ? testi[indici[k + 1]] : '',
      voce,
      file,
    );
    caratteri += testi[i].length;
    voci.push({ i, durata: Number(durata.toFixed(2)) });
    console.log(`${String(i).padStart(2, '0')}  ${durata.toFixed(1)}s  ${testi[i].length} car.`);

    // La scena dura almeno quanto la sua frase, più un respiro: se il parlato
    // sfora, la voce finirebbe sopra la scena successiva.
    const scena = copione.scene[i];
    const nuova = Number(Math.max(scena.durata, durata + 1.3).toFixed(2));

    // ⛔ Una RIPRESA non si allunga da sola: il video finisce e resta il nero.
    // Si congela l'ultimo fotogramma per la differenza (`tpad`), così la scena
    // dura quanto la frase senza che la pagina scatti via.
    if (scena.tipo === 'ripresa' && nuova > scena.durata) {
      const clip = path.join(cartella, scena.file);
      const esteso = `${clip}.tmp.mp4`;
      execFileSync('ffmpeg', [
        '-y', '-loglevel', 'error', '-i', clip,
        '-vf', `tpad=stop_mode=clone:stop_duration=${(nuova - scena.durata + 0.2).toFixed(2)}`,
        '-c:v', 'libx264', '-crf', '19', '-preset', 'veryfast', esteso,
      ]);
      fs.renameSync(esteso, clip);
    }

    scena.durata = nuova;
  }

  fs.writeFileSync(path.join(cartella, 'copione.json'), JSON.stringify(copione, null, 2));
  fs.writeFileSync(path.join(cartella, '.voci.json'), JSON.stringify({ voci }, null, 2));
  console.log(`\n${caratteri} caratteri consumati · ${voci.length} frasi`);
};

main().catch((e) => {
  console.error(e.message);
  process.exit(1);
});

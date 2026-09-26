#!/usr/bin/env node
/**
 * Voce narrante, via Replicate.
 *
 *   node bin/voce.mjs vetrina testi/vetrina-voce.json
 *   node bin/voce.mjs vetrina testi/vetrina-voce.json --fornitore=elevenlabs --voce=Sarah
 *
 * Produce `out/<slug>/voce/NN.wav` (NN = indice della scena nel copione),
 * allunga le scene che non stanno dentro il parlato e scrive
 * `out/<slug>/.voci.json` da passare a `bin/monta-vetrina.mjs`.
 *
 * ⚠️ Il token sta in `.env` alla radice (`REPLICATE_API_TOKEN`) e non passa mai
 * dalla riga di comando: finirebbe nella cronologia della shell.
 *
 * Perché **Replicate** e non l'API di ElevenLabs: lì si paga a mese con un
 * tetto di caratteri (30.000 sul piano base, cioè meno di quanto servirebbe per
 * le 46 guide), qui si paga a consumo. In più dà accesso ai modelli di più
 * fornitori con una chiave sola, e la differenza si sente: le voci di
 * ElevenLabs sono personaggi inglesi a cui si chiede di parlare italiano,
 * MiniMax ha voci italiane native.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

/**
 * ⚠️ Sei richieste al minuto finché il credito Replicate sta sotto i 5 dollari
 * (risposta 429, verificata il 26 Set 2026). Undici secondi fra una frase e
 * l'altra stanno dentro il limite senza rimbalzare. Sopra i 5 dollari si può
 * abbassare: cinquecento file a questo ritmo sono un'ora e mezza.
 */
const PAUSA = 11_000;

const FORNITORI = {
  // Voci italiane native. `<#0.4#>` nel testo inserisce una pausa in secondi:
  // è il modo di dare respiro a una frase senza spezzarla in due chiamate.
  minimax: {
    modello: 'minimax/speech-02-hd',
    voce: 'Italian_DiligentLeader',
    corpo: (testo, voce) => ({
      text: testo,
      voice_id: voce,
      language_boost: 'Italian',
      emotion: 'auto',
      speed: 1,
    }),
  },
  // `previous_text` / `next_text` non sono un dettaglio: senza, ogni frase
  // viene sintetizzata come se fosse l'unica al mondo e riparte ogni volta
  // dalla stessa intonazione neutra — è il «meccanico» che si sente quando si
  // incollano dieci clip generate a parte.
  elevenlabs: {
    modello: 'elevenlabs/v3',
    voce: 'Sarah',
    corpo: (testo, voce, prima, dopo) => ({
      prompt: testo,
      voice: voce,
      language_code: 'it',
      previous_text: prima,
      next_text: dopo,
      stability: 0.4,
      similarity_boost: 0.75,
      style: 0.35,
      speed: 1,
    }),
  },
};

const gettone = () => {
  const riga = fs
    .readFileSync(path.join('..', '.env'), 'utf8')
    .split('\n')
    .find((r) => r.startsWith('REPLICATE_API_TOKEN='));
  if (!riga) throw new Error('REPLICATE_API_TOKEN manca in .env');

  return riga.slice('REPLICATE_API_TOKEN='.length).trim().replace(/^["']|["']$/g, '');
};

const argomento = (nome, difetto) =>
  (process.argv.find((a) => a.startsWith(`--${nome}=`)) ?? `--${nome}=${difetto}`).slice(nome.length + 3);

/**
 * Una frase, con tre tentativi.
 *
 * ⚠️ Replicate fallisce da solo ogni tanto («Director: unexpected error
 * handling prediction», visto il 26 Set 2026 su una frase qualunque, ripetuta
 * subito dopo senza problemi). Su cinquecento file una riprova automatica non è
 * un lusso: è la differenza fra un comando che finisce e uno da sorvegliare.
 */
const sintetizza = async (F, testo, voce, prima, dopo, destinazione) => {
  for (let tentativo = 1; ; tentativo++) {
    try {
      return await unaVolta(F, testo, voce, prima, dopo, destinazione);
    } catch (e) {
      if (tentativo === 3) throw e;
      console.log(`     ritento (${e.message.slice(0, 60)}…)`);
      await new Promise((r) => setTimeout(r, 6000));
    }
  }
};

const unaVolta = async (F, testo, voce, prima, dopo, destinazione) => {
  const r = await fetch(`https://api.replicate.com/v1/models/${F.modello}/predictions`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${gettone()}`,
      'content-type': 'application/json',
      // Aspetta la fine invece di restituire subito un id da interrogare: per
      // una frase di due secondi il giro di polling costa più della sintesi.
      Prefer: 'wait',
    },
    body: JSON.stringify({ input: F.corpo(testo, voce, prima, dopo) }),
  });
  const p = await r.json();
  if (p.status !== 'succeeded') {
    throw new Error(`${F.modello}: ${p.status ?? r.status} ${JSON.stringify(p.error ?? p.detail ?? p).slice(0, 300)}`);
  }

  const mp3 = `${destinazione}.mp3`;
  fs.writeFileSync(mp3, Buffer.from(await (await fetch(p.output)).arrayBuffer()));
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', mp3, '-ar', '48000', '-ac', '2', destinazione]);
  fs.rmSync(mp3);

  return Number(
    execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', destinazione])
      .toString()
      .trim(),
  );
};

const main = async () => {
  const slug = process.argv[2];
  const testiFile = process.argv[3];
  const F = FORNITORI[argomento('fornitore', 'minimax')];
  if (!slug || !testiFile || !F) {
    console.error('uso: node bin/voce.mjs <slug> <testi.json> [--fornitore=minimax|elevenlabs] [--voce=...]');
    process.exit(1);
  }
  const voce = argomento('voce', F.voce);

  const cartella = path.join('out', slug);
  const copione = JSON.parse(fs.readFileSync(path.join(cartella, 'copione.json'), 'utf8'));
  const testi = JSON.parse(fs.readFileSync(testiFile, 'utf8'));
  const indici = Object.keys(testi)
    .map(Number)
    .sort((a, b) => a - b);

  fs.mkdirSync(path.join(cartella, 'voce'), { recursive: true });
  console.log(`${F.modello} · ${voce} · ${indici.length} frasi`);

  const voci = [];
  let caratteri = 0;
  for (let k = 0; k < indici.length; k++) {
    if (k > 0) await new Promise((r) => setTimeout(r, PAUSA));

    const i = indici[k];
    const file = path.join(cartella, 'voce', `${String(i).padStart(2, '0')}.wav`);
    const durata = await sintetizza(
      F,
      testi[i],
      voce,
      k > 0 ? testi[indici[k - 1]] : '',
      k + 1 < indici.length ? testi[indici[k + 1]] : '',
      file,
    );
    caratteri += testi[i].length;
    voci.push({ i, durata: Number(durata.toFixed(2)) });
    console.log(`  ${String(i).padStart(2, '0')}  ${durata.toFixed(1)}s  ${testi[i].length} car.`);

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
  console.log(`\n${caratteri} caratteri · ${voci.length} frasi · ${(voci.reduce((a, v) => a + v.durata, 0)).toFixed(1)}s di parlato`);
};

main().catch((e) => {
  console.error(e.message);
  process.exit(1);
});

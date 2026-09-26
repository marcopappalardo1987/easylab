#!/usr/bin/env node
/**
 * Guardia del lessico delle guide: rifiuta le parole che nel video suonano
 * sbagliate a un italiano.
 *
 *   node bin/lessico.mjs            → controlla copioni e testi, esce 1 se trova
 *
 * ⚠️ Esiste per una ragione precisa. Il 26 Set 2026 una guida diceva «le
 * briciole in alto dicono dove sei»: traduzione letterale di *breadcrumbs*, che
 * in inglese è un'immagine (Pollicino) e in italiano non è niente. L'errore non
 * si vede rileggendo il codice — si sente guardando il video, cioè quando la
 * voce è già stata sintetizzata e pagata. Una parola in un elenco costa zero e
 * lo ferma prima.
 *
 * Controlla la PROSA, non i selettori: sono stringhe lunghe, con spazi, senza
 * i caratteri dei selettori CSS. Un `page.locator('form')` non è una frase.
 */
import fs from 'node:fs';
import path from 'node:path';

/** parola → come si dice. Si allunga ogni volta che una sfugge. */
const BANDITE = {
  briciole: 'il percorso (calco da «breadcrumbs»: in italiano non vuol dire niente)',
  switcher: 'il selettore (di sede)',
  droppare: 'trascinare',
  uploadare: 'caricare',
  downloadare: 'scaricare',
  settare: 'impostare',
  schedulare: 'programmare',
  matchare: 'corrispondere',
  checkare: 'controllare',
  deletare: 'eliminare',
  tools: 'strumenti',
  form: 'modulo',
  tab: 'scheda',
  folder: 'cartella',
  device: 'dispositivo',
};

const PROSA = /(['"`])((?:\\.|(?!\1)[^\\])*)\1/g;

/** Una stringa è prosa se ha respiro e non ha l'aria di un selettore. */
const eFrase = (t) => t.length > 25 && (t.match(/ /g) ?? []).length >= 3 && !/[[\]{}#>=]|\.\w+\(/.test(t);

const trovate = [];

const esamina = (file, testo, riga, dove) => {
  for (const [parola, invece] of Object.entries(BANDITE)) {
    if (new RegExp(`\\b${parola}\\b`, 'i').test(testo)) {
      trovate.push({ file, riga, parola, invece, dove: dove.slice(0, 90) });
    }
  }
};

for (const f of fs.readdirSync('flussi').filter((f) => f.endsWith('.spec.ts'))) {
  const righe = fs.readFileSync(path.join('flussi', f), 'utf8').split('\n');
  righe.forEach((riga, i) => {
    for (const m of riga.matchAll(PROSA)) {
      if (eFrase(m[2])) esamina(`flussi/${f}`, m[2], i + 1, m[2]);
    }
  });
}

if (fs.existsSync('testi')) {
  for (const f of fs.readdirSync('testi').filter((f) => f.endsWith('.json'))) {
    const dati = JSON.parse(fs.readFileSync(path.join('testi', f), 'utf8'));
    for (const [chiave, testo] of Object.entries(dati)) {
      if (typeof testo === 'string') esamina(`testi/${f}`, testo, chiave, testo);
    }
  }
}

if (trovate.length === 0) {
  console.log('lessico: nessuna parola bandita.');
  process.exit(0);
}

for (const t of trovate) {
  console.error(`${t.file}:${t.riga}  «${t.parola}» → ${t.invece}`);
  console.error(`    ${t.dove}…`);
}
console.error(`\n${trovate.length} da correggere. L'elenco sta in bin/lessico.mjs.`);
process.exit(1);

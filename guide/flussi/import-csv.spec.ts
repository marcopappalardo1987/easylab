import path from 'node:path';
import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C9 — «Importare strumenti da CSV». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (10, in
 * tre capitoli da 4, 3 e 3): i testi scritti in `testi/import-csv.md` sono
 * numerati come i passi.
 *
 * ⚠️ Il file di scena (`fixture/strumenti-esempio.csv`) contiene DI PROPOSITO
 * due righe sbagliate — un nome vuoto e un'ubicazione inesistente — perché la
 * guida deve mostrare l'anteprima degli errori, che è il punto della funzione.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */

// `--lang` per coerenza con le altre guide della serie (date all'italiana).
test.use({ launchOptions: { args: ['--force-color-profile=srgb', '--font-render-hinting=none', '--lang=it-IT'] } });

test('import csv', async ({ page }) => {
  const s = new Scenografo(page, 'import-csv', 'Importare strumenti da CSV', 'Caricare un parco intero, e vedere prima che cosa non torna');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/import');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: il file ────────────────────────────────────────────────────────────
  s.cartello('Il file', 'Parte 1 di 3');

  await s.scatto("Si arriva da «Importa CSV», in cima all'elenco degli strumenti: serve a caricare molte macchine in una volta, non una alla volta.", {
    su: page.getByRole('heading', { name: /Importa strumenti/ }),
    zoom: 1.7,
    durata: 7.5,
  });

  await s.scatto('Il formato è scritto qui, con un esempio: sei colonne, la prima riga con le intestazioni.', {
    su: page.locator('pre').first(),
    zoom: 1.7,
    durata: 7,
  });

  await s.scatto('«Scarica template CSV» dà il file già intestato: è la strada più corta per non sbagliare i nomi delle colonne.', {
    su: page.getByRole('button', { name: /Scarica template/ }),
    zoom: 2,
    durata: 7,
  });

  const analizza = page.getByRole('button', { name: 'Analizza' });
  const importa = page.getByRole('button', { name: /Importa 3 righe valide/ });

  await s.movimento(
    'Si carica il proprio file e si chiede di analizzarlo.',
    async () => {
      await page.setInputFiles('#file', path.resolve(process.cwd(), 'fixture', 'strumenti-esempio.csv'));
      await page.waitForTimeout(1000);
      await analizza.click();
      await expect(importa).toBeVisible({ timeout: 10_000 });
      await page.waitForTimeout(600);
    },
    { su: analizza, zoom: 1.4, coda: 1.4 },
  );

  // ── Due: l'anteprima ────────────────────────────────────────────────────────
  s.cartello("L'anteprima, riga per riga", 'Parte 2 di 3');

  const tabella = page.locator('table').first();

  await s.scatto("L'analisi non importa niente: mostra che cosa succederebbe, riga per riga, con il conto di valide ed errori.", {
    su: page.getByText('3 valide'),
    zoom: 1.6,
    durata: 7,
  });

  await s.scatto('Ogni riga porta il proprio esito: quelle valide entreranno, quelle con un problema no, e il problema è scritto.', {
    su: tabella,
    zoom: 1.5,
    alone: false,
    durata: 7.5,
  });

  await expect(page.getByText('Nome mancante')).toBeVisible();
  await expect(page.getByText(/Ubicazione non trovata/)).toBeVisible();

  await s.scatto("Gli errori tipici sono due: un campo obbligatorio vuoto, e un'ubicazione che fra i laboratori non esiste.", {
    su: page.locator('tr', { hasText: 'Nome mancante' }),
    zoom: 1.7,
    alone: false,
    durata: 7.5,
  });

  // ── Tre: importare ──────────────────────────────────────────────────────────
  s.cartello('Importare, e che cosa resta fuori', 'Parte 3 di 3');

  await s.movimento(
    'Il pulsante dice quante righe verranno importate: solo le valide, e il numero è quello che hai appena letto.',
    async () => {
      await importa.click();
      await expect(importa).toHaveCount(0, { timeout: 10_000 });
      await expect(page.getByText(/2 righe scartate/)).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: importa, zoom: 1.6, coda: 1.4 },
  );

  await s.scatto('Le macchine sono nel parco. Le righe scartate non sono andate perdute: si correggono nel foglio e si ricarica.', {
    su: page.getByText(/Importati 3 strumenti/).locator('..'),
    zoom: 1.6,
    durata: 7.5,
  });

  await s.scatto("Conviene importare a lotti piccoli la prima volta: cinque righe di prova dicono se le ubicazioni combaciano, prima di caricarne trecento.", {
    durata: 7.5,
  });

  s.chiusura('Da ricordare', [
    "L'analisi non scrive niente: si vede sempre l'esito prima di importare.",
    "L'ubicazione va scritta come in «Laboratori», nella forma «Laboratorio > Sotto-laboratorio»: è l'errore più frequente.",
    'Le righe scartate restano nel tuo foglio: si correggono e si ricarica, senza rifare le altre.',
  ]);

  await s.scrivi();
});

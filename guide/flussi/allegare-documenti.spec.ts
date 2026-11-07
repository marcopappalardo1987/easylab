import path from 'node:path';
import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida G1 — «Allegare un documento a una macchina». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (10, in
 * tre capitoli da 2, 5 e 3): i testi scritti in `testi/allegare-documenti.md`
 * sono numerati come i passi.
 *
 * ⚠️ Il copione SCRIVE (carica un file sul disco dimostrativo): `azzera.sh`
 * prima di ogni giratura riporta il database; il file resta, ma nessuna riga lo
 * indica più.
 */
test('allegare documenti', async ({ page }) => {
  const s = new Scenografo(page, 'allegare-documenti', 'Allegare un documento', 'Manuali, certificati e rapporti sulla scheda della macchina');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: dove vanno ─────────────────────────────────────────────────────────
  s.cartello('Dove vanno', 'Parte 1 di 3');

  const linguetta = page.getByRole('button', { name: 'Documenti' }).first();

  await s.movimento(
    'Ogni macchina ha una linguetta «Documenti»: è il posto in cui vivono il manuale, i certificati e i rapporti.',
    async () => {
      await linguetta.click();
      await expect(page.getByRole('button', { name: /Carica documento/ })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: linguetta, zoom: 1.3, coda: 1.2 },
  );

  await s.scatto('Non è un archivio generico: quello che carichi qui resta legato a questa macchina e la segue.', {
    su: page.getByText('Manuali e certificati della macchina', { exact: false }),
    zoom: 1.6,
    durata: 6.5,
  });

  // ── Due: caricare ───────────────────────────────────────────────────────────
  s.cartello('Caricare', 'Parte 2 di 3');

  const apri = page.getByRole('button', { name: /Carica documento/ });

  await s.movimento(
    'Si carica dal proprio computer, un file alla volta.',
    async () => {
      await apri.click();
      await expect(page.locator('#fileDocumento')).toBeAttached();
      await page.waitForTimeout(500);
    },
    { su: apri, zoom: 1.4, coda: 1 },
  );

  const file = page.locator('#fileDocumento');

  await s.movimento(
    'PDF o immagine, fino a venti megabyte.',
    async () => {
      await file.setInputFiles(path.resolve(process.cwd(), 'fixture', 'certificato-taratura.pdf'));
      await expect(page.locator('form:has(#fileDocumento)').getByRole('button', { name: 'Carica' })).toBeEnabled();
      await page.waitForTimeout(700);
    },
    { su: file, zoom: 1.7, coda: 1.2, suono: 'click' },
  );

  const tipo = page.locator('#tipoDocumento');

  await s.movimento(
    "Il tipo dice che cos'è: manuale, certificato, rapporto di fine lavoro. Serve a ritrovarlo dopo.",
    async () => {
      await tipo.selectOption('certificato_taratura');
      await page.waitForTimeout(700);
    },
    { su: tipo, zoom: 1.6, coda: 1.2 },
  );

  const aggancio = page.locator('#documentoInterventoId');

  await s.movimento(
    "Un certificato si può agganciare all'intervento che lo ha prodotto: da quel momento la riga dello storico lo porta con sé.",
    async () => {
      await aggancio.selectOption({ index: 1 });
      await expect(aggancio).not.toHaveValue('');
      await page.waitForTimeout(700);
    },
    { su: aggancio, zoom: 1.6, coda: 1.4 },
  );

  const invia = page.locator('form:has(#fileDocumento)').getByRole('button', { name: 'Carica' });

  await s.movimento(
    'Si carica.',
    async () => {
      await invia.click();
      await expect(page.locator('#fileDocumento')).toHaveCount(0, { timeout: 10_000 });
      await expect(page.getByRole('cell', { name: 'certificato-taratura.pdf' })).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: invia, zoom: 1.6, coda: 1.4 },
  );

  // ── Tre: ritrovarlo e scaricarlo ────────────────────────────────────────────
  s.cartello('Ritrovarlo e scaricarlo', 'Parte 3 di 3');

  const riga = page.locator('tr', { has: page.getByRole('cell', { name: 'certificato-taratura.pdf' }) });

  await s.scatto("Il documento è in elenco con il suo tipo, l'intervento a cui è allegato, la dimensione e chi lo ha caricato.", {
    su: riga,
    zoom: 1.4,
    durata: 7,
  });

  const scarica = riga.getByRole('link', { name: 'Scarica' });

  await s.scatto("«Scarica» passa dall'applicazione, non da un indirizzo pubblico: il permesso viene ricontrollato a ogni richiesta.", {
    su: scarica,
    zoom: 2.4,
    durata: 7,
  });

  await s.movimento(
    'E ogni scarico lascia una traccia nel registro: chi, che cosa, quando. Vale anche per te.',
    async () => {
      const arrivo = page.waitForEvent('download');
      await scarica.click();
      const scaricato = await arrivo;
      expect(scaricato.suggestedFilename()).toContain('certificato-taratura');
      await page.waitForTimeout(600);
    },
    { su: scarica, zoom: 2, coda: 1.2 },
  );

  s.chiusura('Da ricordare', [
    'I documenti stanno sulla macchina, non in una cartella a parte: la seguono per tutta la sua vita.',
    "Il tipo e l'aggancio a un intervento sono ciò che li rende ritrovabili fra due anni.",
    "Lo scarico passa sempre dall'applicazione, che ricontrolla il permesso e registra chi ha scaricato.",
  ]);

  await s.scrivi();
});

import { test } from '@playwright/test';
import path from 'node:path';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida G1 — «Allegare un documento a una macchina».
 *
 * ⚠️ Il copione SCRIVE (carica un file sul disco delle guide dimostrativo):
 * `azzera.sh` prima di ogni giratura riporta il database, il file resta ma non
 * dà fastidio perché nessuna riga lo indica più.
 */
test('allegare documenti', async ({ page }) => {
  const g = new Regista(page, 'allegare-documenti', 'Allegare un documento', 'Manuali, certificati e rapporti sulla scheda della macchina');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Dove vanno');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: 'Documenti' }).first().click();
  await page.waitForTimeout(700);

  await g.passo("Ogni macchina ha una linguetta «Documenti»: è il posto in cui vivono il manuale, i certificati e i rapporti.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Non è un archivio generico: quello che carichi qui resta legato a questa macchina e la segue.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Caricare');

  await g.passo('Si carica dal proprio computer, un file alla volta.', {
    su: page.getByRole('button', { name: /Carica/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  await page.getByRole('button', { name: /Carica/ }).first().click();
  await page.waitForTimeout(900);

  await page.setInputFiles('#fileDocumento', path.resolve(process.cwd(), 'fixture', 'certificato-taratura.pdf'));
  await page.waitForTimeout(1500);

  await g.passo('PDF o immagine, fino a venti megabyte.', {
    su: page.locator('#fileDocumento'),
    zoom: 2,
    durata: 7,
  });

  await g.passo("Il tipo dice che cos'è: manuale, certificato, rapporto di fine lavoro. Serve a ritrovarlo dopo.", {
    su: page.locator('#tipoDocumento'),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("Un certificato si può agganciare all'intervento che lo ha prodotto: da quel momento la riga dello storico lo porta con sé.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('Si carica.', {
    su: page.locator('form:has(#fileDocumento)').getByRole('button', { name: /Carica|Salva/ }).first(),
    zoom: 2.2,
    durata: 5,
  });

  await page.locator('form:has(#fileDocumento)').getByRole('button', { name: /Carica|Salva/ }).first().click();
  await page.waitForTimeout(1800);

  g.capitolo('Parte 3 di 3', 'Ritrovarlo e scaricarlo');

  await g.passo("Il documento è in elenco con il suo tipo, la dimensione e chi lo ha caricato.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("«Scarica» passa dall'applicazione, non da un indirizzo pubblico: il permesso viene ricontrollato a ogni richiesta.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("E ogni scarico lascia una traccia: chi, che cosa, quando. Vale anche per te.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'I documenti stanno sulla macchina, non in una cartella a parte: la seguono per tutta la sua vita.',
    "Il tipo e l'aggancio a un intervento sono ciò che li rende ritrovabili fra due anni.",
    'Lo scarico passa sempre dall\'applicazione, che ricontrolla il permesso e registra chi ha scaricato.',
  ]);

  g.scrivi();
});

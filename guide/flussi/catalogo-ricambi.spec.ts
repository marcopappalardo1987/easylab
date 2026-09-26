import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida F2 — «Il catalogo dei ricambi». */
test('catalogo ricambi', async ({ page }) => {
  const g = new Regista(page, 'catalogo-ricambi', 'Il catalogo dei ricambi', 'I pezzi montati sulle macchine, raccolti per nome');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', "Che cos'è");

  await page.goto('/ricambi');
  await page.waitForLoadState('networkidle');

  await g.passo("«Ricambi» raccoglie i pezzi che sono stati montati sulle macchine, non un magazzino di quelli a scorta.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Ogni voce è un pezzo, con quante volte è stato montato: il catalogo si costruisce da solo, annotando gli interventi.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Cercare un pezzo');

  await g.passo('La ricerca lavora sul nome del pezzo, e basta una parola.', {
    su: page.getByPlaceholder(/Cerca un ricambio/),
    zoom: 2,
    durata: 7,
  });

  await page.getByPlaceholder(/Cerca un ricambio/).fill('guarnizione');
  await page.waitForTimeout(900);

  await g.passo("Restano i pezzi che corrispondono, con l'elenco delle macchine su cui stanno.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('Per ognuna si legge la macchina, la matricola, il laboratorio e la data di montaggio.', {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Le grafie doppie');

  await g.passo("Il nome del pezzo lo scrive chi registra l'intervento, quindi lo stesso ricambio può finire con due grafie diverse.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("«Unisci» serve a quello: si sceglie il nome buono e le due voci diventano una, senza perdere nessun montaggio.", {
    su: page.getByRole('button', { name: /Unisci/ }).first(),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("Vale la pena farlo appena ci si accorge: più a lungo convivono, più montaggi finiscono sulla grafia sbagliata.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Il catalogo non è un magazzino: raccoglie i pezzi già montati, e si costruisce annotando gli interventi.',
    'Da un pezzo si arriva alle macchine su cui sta, con matricola, laboratorio e data.',
    '«Unisci» risolve le grafie doppie dello stesso ricambio senza perdere i montaggi.',
  ]);

  g.scrivi();
});

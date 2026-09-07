import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida C7 — «Dove è installato un modello». */
test('per modello', async ({ page }) => {
  const g = new Regista(page, 'per-modello', 'Dove è installato un modello', 'Dato un modello, in quali laboratori sta e quante unità');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 2', 'La domanda al contrario');

  await page.goto('/strumenti/modelli');
  await page.waitForLoadState('networkidle');

  await g.passo('«Per modello» è la seconda linguetta degli strumenti, e ribalta la domanda: non «dov\'è questa macchina», ma «dove sono tutte quelle di questo tipo».', {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo('Ogni riquadro è un modello, con sotto i laboratori in cui è installato e quante unità per ciascuno.', {
    zoom: 1,
    durata: 8,
  });

  await g.passo('La ricerca filtra sui modelli, non sulle macchine: basta una parte del nome commerciale.', {
    su: page.getByPlaceholder(/Cerca un modello/),
    zoom: 2.1,
    durata: 7,
  });

  await page.fill('#search', 'Steri');
  await page.waitForTimeout(900);

  await g.passo("Restano i modelli che corrispondono, con la loro distribuzione nei reparti.", {
    zoom: 1,
    durata: 7,
  });

  g.capitolo('Parte 2 di 2', 'A che cosa serve');

  await g.passo('Cliccando un laboratorio si apre l\'elenco degli strumenti già filtrato su quel modello e quel reparto.', {
    su: page.getByRole('link').last(),
    zoom: 2,
    durata: 8,
  });

  await g.passo("È la vista con cui si decide un acquisto: sapere che di un modello ne hai quattordici sparse in sei laboratori cambia la trattativa con il fornitore.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Ed è anche quella con cui si gestisce un richiamo: se un modello ha un difetto noto, qui c'è l'elenco esatto delle unità da controllare.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "«Elenco» risponde su una macchina, «Per modello» risponde su un tipo di macchina.",
    'Ogni laboratorio elencato è un collegamento: porta alle unità di quel modello in quel reparto.',
    'Serve per gli acquisti, per i ricambi comuni e per i richiami del fornitore.',
  ]);

  g.scrivi();
});

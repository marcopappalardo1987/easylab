import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida F3 — «Dove è montato un pezzo».
 *
 * ⚠️ Stessa pagina di `catalogo-ricambi`, angolo opposto: là si spiega che cos'è
 * il catalogo e come si tengono puliti i nomi, qui si parte da un pezzo e si
 * arriva alle macchine. Le due si citano a vicenda invece di ripetersi.
 */
test('dove e montato', async ({ page }) => {
  const g = new Regista(page, 'dove-e-montato', 'Dove è montato un pezzo', 'Dal ricambio alle macchine che lo hanno sopra');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'La domanda');

  await page.goto('/ricambi');
  await page.waitForLoadState('networkidle');
  await page.getByPlaceholder(/Cerca un ricambio/).fill('guarnizione');
  await page.waitForTimeout(1000);

  await g.passo("La domanda è: questo pezzo, su quali macchine sta? Si scrive il nome e la risposta è tutta qui.", {
    su: page.getByPlaceholder(/Cerca un ricambio/),
    zoom: 2,
    durata: 8,
  });

  await g.passo("In cima al riquadro ci sono i due numeri che contano: su quante macchine è montato, e quanti pezzi in tutto.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Per laboratorio');

  await g.passo("Le etichette sotto il nome dividono i montaggi per laboratorio, con quanti ne ha ciascuno.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È la vista che dice dove quel pezzo si consuma di più: un laboratorio con il triplo dei montaggi degli altri sta dicendo qualcosa.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Le macchine');

  await g.passo("Sotto c'è l'elenco delle macchine: nome, matricola, laboratorio e quanti pezzi ha sopra.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Ogni macchina è un collegamento alla sua scheda: da lì si vede quando il pezzo è stato montato e in quale intervento.", {
    su: page.locator('tbody tr a').first(),
    zoom: 2,
    durata: 8,
  });

  await g.passo("Serve a preparare un giro di manutenzione — cambio la stessa guarnizione su tutte — e a ordinare la quantità giusta.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("E serve il giorno in cui un lotto di ricambi risulta difettoso: qui c'è l'elenco esatto delle macchine da controllare.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Da un pezzo si arriva alle macchine, con matricola e laboratorio: è la ricerca al contrario.',
    'I montaggi divisi per laboratorio dicono dove quel pezzo si consuma di più.',
    'Serve per i giri di manutenzione, per gli ordini e per i lotti difettosi.',
  ]);

  g.scrivi();
});

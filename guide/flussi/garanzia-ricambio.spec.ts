import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida E2 — «La garanzia di un ricambio».
 *
 * ⚠️ Girata con l'**Admin**: la visibilità delle garanzie sui ricambi è
 * configurabile per Ente (ADR-020/029) e alcuni ruoli ne vedono solo
 * l'aggregato. La guida spiega proprio quella differenza, quindi va ritratta da
 * chi vede il dettaglio.
 */
test('garanzia ricambio', async ({ page }) => {
  const g = new Regista(page, 'garanzia-ricambio', 'La garanzia di un ricambio', 'La copertura del pezzo, distinta da quella della macchina');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Due coperture diverse');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: 'Garanzie' }).first().click();
  await page.waitForTimeout(700);

  await g.passo("Una macchina ha la sua garanzia, e i pezzi montati sopra possono averne una propria: sono due cose distinte.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Scadono in momenti diversi, perché un pezzo sostituito ieri è coperto da ieri, non da quando fu comprata la macchina.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Da dove nasce');

  await page.getByRole('button', { name: 'Ricambi' }).first().click();
  await page.waitForTimeout(700);

  await g.passo("La garanzia del pezzo si mette quando lo si annota, nell'intervento che lo ha montato.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Nella linguetta «Ricambi» si ritrova, e da qui si corregge se la data era sbagliata.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Ogni riga tiene insieme il pezzo, l'intervento che lo ha montato e la sua copertura.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Chi la vede, e che cosa accende');

  await g.passo("Una garanzia di ricambio scaduta accende il semaforo della macchina, esattamente come quella della macchina.", {
    zoom: 1,
    durata: 8,
  });

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo("Nella Panoramica il motivo è scritto: «garanzia ricambio scaduta» invece di «garanzia scaduta».", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Non tutti vedono il dettaglio: per alcuni ruoli il pezzo resta un aggregato — quanti sono coperti — senza il nome.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È una scelta di riservatezza configurabile per Ente: il pallino lo vedono tutti, l'elenco no.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'La garanzia del pezzo è distinta da quella della macchina, e parte dal giorno del montaggio.',
    'Anche quella del pezzo accende il semaforo: è la spiegazione di molte macchine gialle senza interventi in ritardo.',
    "Il dettaglio dei pezzi coperti può essere riservato: il pallino lo vedono tutti, l'elenco dipende dal ruolo e dall'Ente.",
  ]);

  g.scrivi();
});

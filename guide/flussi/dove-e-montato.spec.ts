import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida F3 — «Dove è montato un pezzo». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa pagina di `catalogo-ricambi`, angolo opposto: là si spiega che cos'è
 * il catalogo e come si tengono puliti i nomi, qui si parte da un pezzo e si
 * arriva alle macchine. Le due si citano a vicenda invece di ripetersi.
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (8, in
 * tre capitoli da 2, 2 e 4): i testi scritti in `testi/dove-e-montato.md` sono
 * numerati come i passi.
 *
 * Girata col Responsabile Reparto: vede i soli suoi laboratori, e l'elenco
 * delle macchine resta abbastanza corto da scorrerlo in una panoramica.
 * Non scrive niente.
 */
test('dove e montato', async ({ page }) => {
  const s = new Scenografo(page, 'dove-e-montato', 'Dove è montato un pezzo', 'Dal ricambio alle macchine che lo hanno sopra');

  await accedi(page, 'giulia.ferrari@aurora.test');
  await page.goto('/ricambi');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: la domanda ─────────────────────────────────────────────────────────
  s.cartello('La domanda', 'Parte 1 di 3');

  const ricerca = page.getByPlaceholder(/Cerca un ricambio/);
  await s.movimento(
    'La domanda è: questo pezzo, su quali macchine sta? Si scrive il nome e la risposta è tutta qui.',
    async () => {
      await ricerca.click();
      await ricerca.pressSequentially('guarnizione', { delay: 110 });
      await page.waitForTimeout(1200);
      await expect(page.getByRole('heading', { name: 'Guarnizione di tenuta' })).toBeVisible();
    },
    { su: ricerca, zoom: 1.3, coda: 1.2, suono: 'tastiera', durata: 7 },
  );

  const titolo = page.getByRole('heading', { name: 'Guarnizione di tenuta' });
  const macchine = page.getByText(/\d+ macchine/).first();
  await s.scatto('In cima al riquadro ci sono i due numeri che contano: su quante macchine è montato, e quanti pezzi in tutto.', {
    su: macchine.locator('..'),
    zoom: 2,
    durata: 7.5,
  });

  // ── Due: per laboratorio ────────────────────────────────────────────────────
  s.cartello('Per laboratorio', 'Parte 2 di 3');

  const scheda = titolo.locator('xpath=ancestor::*[.//table][1]');
  const etichette = scheda.locator('a[href*="ubicazioneId"]');
  await s.scatto('Le etichette sotto il nome dividono le macchine per laboratorio, con quante ne ha ciascuno.', {
    su: etichette.first().locator('..'),
    zoom: 1.4,
    alone: false,
    durata: 7,
  });

  const prima = etichette.first();
  const laboratorio = ((await prima.textContent()) ?? '').replace(/\d+/g, '').trim();
  await s.movimento(
    "Un laboratorio con il triplo delle macchine degli altri sta dicendo qualcosa; l'etichetta apre l'elenco di tutte le sue macchine.",
    async () => {
      await prima.click();
      await page.waitForURL(/\/strumenti\?.*ubicazioneId=/);
      await expect(page.locator('tbody tr').first()).toContainText(laboratorio);
      await page.waitForTimeout(800);
    },
    { su: prima, zoom: 1.5, coda: 1.2, durata: 8 },
  );

  await page.goBack();
  await page.waitForLoadState('networkidle');
  if (!(await titolo.isVisible())) {
    await ricerca.fill('guarnizione');
    await expect(titolo).toBeVisible();
  }

  // ── Tre: le macchine ────────────────────────────────────────────────────────
  s.cartello('Le macchine', 'Parte 3 di 3');

  await s.scatto("Sotto c'è l'elenco delle macchine: nome, matricola, laboratorio e quanti pezzi ha sopra.", {
    su: scheda.locator('thead'),
    zoom: 1.3,
    durata: 7,
  });

  const link = scheda.locator('tbody tr a').first();
  await s.movimento(
    'Ogni macchina è un collegamento alla sua scheda: nella linguetta «Ricambi» si vede quando il pezzo è stato montato e in quale intervento.',
    async () => {
      await link.click();
      await page.waitForURL(/\/strumenti\/\d+/);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(500);
      await page.getByRole('button', { name: /^Ricambi/ }).first().click();
      await expect(page.locator('tbody tr').filter({ visible: true }).filter({ hasText: 'Guarnizione di tenuta' }).first()).toBeVisible();
      await page.waitForTimeout(900);
    },
    { su: link, zoom: 1.3, coda: 1.4, durata: 8.5 },
  );

  await page.goBack();
  await page.waitForLoadState('networkidle');
  if (!(await titolo.isVisible())) {
    await ricerca.fill('guarnizione');
    await expect(titolo).toBeVisible();
  }

  await s.panoramica('Serve a preparare un giro di manutenzione, la stessa guarnizione su tutte, e a ordinare la quantità giusta.', 8);

  await s.scatto("E serve il giorno in cui un lotto di ricambi risulta difettoso: qui c'è l'elenco esatto delle macchine da controllare.", {
    su: scheda.locator('tbody tr').first(),
    zoom: 1.3,
    durata: 7.5,
  });

  s.chiusura('Da ricordare', [
    'Da un pezzo si arriva alle macchine, con matricola e laboratorio: è la ricerca al contrario.',
    'Le macchine divise per laboratorio dicono dove quel pezzo si consuma di più.',
    'Serve per i giri di manutenzione, per gli ordini e per i lotti difettosi.',
  ]);

  await s.scrivi();
});

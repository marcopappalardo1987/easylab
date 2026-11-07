import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C10 — «Lo storico in PDF». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (7, in due
 * capitoli da 2 e 5): i testi scritti in `testi/storico-pdf.md` sono numerati
 * come i passi.
 *
 * «Storico PDF» apre il documento in una scheda nuova del browser: la ripresa
 * vede il click, non il foglio. Che il foglio sia davvero un PDF lo dice
 * l'asserzione sulla risposta, non l'immagine.
 *
 * Il copione non scrive niente.
 */
test('storico pdf', async ({ page }) => {
  const s = new Scenografo(page, 'storico-pdf', 'Lo storico in PDF', 'Il documento con tutta la vita della macchina');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  const pdf = page.getByRole('link', { name: /Storico PDF/ }).first();
  const indirizzo = await pdf.getAttribute('href');
  const risposta = await page.request.get(indirizzo!);
  expect(risposta.ok()).toBe(true);
  expect(risposta.headers()['content-type']).toContain('application/pdf');

  await s.avvia();

  // ── Uno: da dove si prende ──────────────────────────────────────────────────
  s.cartello('Da dove si prende', 'Parte 1 di 2');

  await s.scatto('«Storico PDF» sta fra le azioni della scheda, e produce un documento sulla singola macchina.', {
    su: pdf,
    zoom: 2.2,
    durata: 7,
  });

  await s.movimento(
    "Non è un'esportazione da configurare: si clicca, e il documento si apre accanto, già impaginato.",
    async () => {
      const [foglio] = await Promise.all([page.waitForEvent('popup'), pdf.click()]);
      await page.waitForTimeout(1200);
      await foglio.close();
    },
    { su: pdf, zoom: 1.8, coda: 1 },
  );

  // ── Due: che cosa contiene ──────────────────────────────────────────────────
  s.cartello('Che cosa contiene, e a chi serve', 'Parte 2 di 2');

  const linguetta = page.locator('[data-tab="interventi"]');
  const pannello = page.locator('[x-show="tab === \'interventi\'"]');

  await s.movimento(
    "Dentro c'è questo: i dati della macchina e lo storico completo degli interventi, aperti e già fatti.",
    async () => {
      await linguetta.click();
      await expect(pannello.locator('tbody tr').first()).toBeVisible();
      await page.waitForTimeout(900);
    },
    { su: linguetta, zoom: 1.2, coda: 1.4 },
  );

  await s.scatto('Ogni riga porta data, tipo, descrizione e chi ha eseguito: le stesse informazioni che leggi qui.', {
    su: pannello.locator('thead'),
    zoom: 1.6,
    durata: 7,
  });

  await s.panoramica("Serve quando la manutenzione va dimostrata a qualcun altro: un ispettore, un cliente, un ente certificatore.", 7.5);

  await page.evaluate(() => window.scrollTo(0, 0));

  await s.scatto("È anche il documento da allegare quando una macchina cambia proprietario o esce dall'Ente: la sua storia esce con lei.", {
    su: page.getByRole('heading', { name: 'Autoclave' }).locator('../..'),
    zoom: 1.6,
    alone: false,
    durata: 7.5,
  });

  await s.scatto('Il PDF fotografa il momento in cui lo chiedi: se domani registri un intervento, va rigenerato.', {
    su: pdf,
    zoom: 2,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    'Il PDF contiene dati e storico completo degli interventi di una macchina, impaginati.',
    'Serve a dimostrare la manutenzione fuori da Easy Lab: ispezioni, certificazioni, passaggi di proprietà.',
    'È una fotografia del momento: dopo un intervento nuovo va rigenerato.',
  ]);

  await s.scrivi();
});

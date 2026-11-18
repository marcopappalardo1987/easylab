import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida E2 — «La garanzia di un ricambio». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (9, in
 * tre capitoli da 2, 3 e 4): i testi scritti in `testi/garanzia-ricambio.md`
 * sono numerati come i passi.
 *
 * La macchina è la 160 del seme: garanzia propria attiva fino al 2029, e gialla
 * per UN solo motivo, la garanzia scaduta di un pezzo montato. È il caso che
 * mostra le due coperture separate e il numerino sulla linguetta «Ricambi»
 * (ADR-055). Girata con l'Admin, che vede il dettaglio dei pezzi.
 *
 * Non scrive niente: «Correggi» si apre e si chiude con «Annulla».
 */
test('garanzia ricambio', async ({ page }) => {
  const s = new Scenografo(page, 'garanzia-ricambio', 'La garanzia di un ricambio', 'La copertura del pezzo, distinta da quella della macchina');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/160');
  await page.waitForLoadState('networkidle');
  await expect(page.getByText(/Garanzia ricambio scaduta il/)).toBeVisible();
  await s.avvia();

  // ── Uno: due coperture diverse ──────────────────────────────────────────────
  s.cartello('Due coperture diverse', 'Parte 1 di 3');

  const garanzie = page.getByRole('button', { name: /^Garanzie/ }).first();
  await s.movimento(
    'Una macchina ha la sua garanzia, e i pezzi montati sopra possono averne una propria: sono due cose distinte.',
    async () => {
      await garanzie.click();
      await expect(page.getByRole('button', { name: /Nuova garanzia/ })).toBeVisible();
      await expect(page.locator('tbody tr').filter({ visible: true }).filter({ hasText: 'Attiva' })).toHaveCount(1);
      await page.waitForTimeout(600);
    },
    { su: garanzie, zoom: 1.5, coda: 1.2, durata: 7.5 },
  );

  const ricambi = page.getByRole('button', { name: /^Ricambi/ }).first();
  await expect(ricambi).toContainText(/\d/);
  await s.movimento(
    'Scadono in momenti diversi: la macchina è coperta fino al 2029, il pezzo montato ad aprile non più, e la linguetta lo segnala.',
    async () => {
      await ricambi.click();
      await expect(page.locator('p', { hasText: 'Ricambi montati' })).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: ricambi, zoom: 1.5, coda: 1.2, durata: 8 },
  );

  // ── Due: da dove nasce ──────────────────────────────────────────────────────
  s.cartello('Da dove nasce', 'Parte 2 di 3');

  await s.scatto("La garanzia del pezzo si mette quando lo si annota, nell'intervento che lo ha montato.", {
    su: page.getByText(/I pezzi si registrano dal form dell'intervento/),
    zoom: 1.9,
    durata: 6.5,
  });

  const riga = page.locator('tbody tr').filter({ visible: true }).filter({ hasText: 'Scheda di controllo' });
  const correggi = riga.getByRole('button', { name: 'Correggi' });
  await s.movimento(
    'Nella linguetta «Ricambi» si ritrova, e con «Correggi» si sistema la scadenza se la data era sbagliata.',
    async () => {
      await correggi.click();
      await expect(page.locator('#ricambioForm\\.scadenza_garanzia')).toBeVisible();
      await page.waitForTimeout(500);
      await page.locator('#ricambioForm\\.scadenza_garanzia').click();
      await page.waitForTimeout(700);
    },
    { su: correggi, zoom: 1.4, coda: 1.2, durata: 7 },
  );

  await page.getByRole('button', { name: 'Annulla' }).filter({ visible: true }).click();
  await expect(page.locator('#ricambioForm\\.scadenza_garanzia')).toBeHidden();

  await s.scatto("Ogni riga tiene insieme il pezzo, la data di montaggio, l'intervento che lo ha montato, il fornitore e la sua copertura.", {
    su: riga,
    zoom: 1.4,
    durata: 7.5,
  });

  // ── Tre: chi la vede, e che cosa accende ────────────────────────────────────
  s.cartello('Chi la vede, e che cosa accende', 'Parte 3 di 3');

  await s.scatto('Una garanzia di ricambio scaduta accende il semaforo della macchina, esattamente come quella della macchina.', {
    su: page.locator('h1').first().locator('..'),
    zoom: 1.6,
    durata: 7,
  });

  const panoramica = page.getByRole('button', { name: 'Panoramica' });
  await s.movimento(
    'Nella Panoramica il motivo è scritto: «Garanzia ricambio scaduta», distinto dalla garanzia della macchina.',
    async () => {
      await panoramica.click();
      await expect(page.getByText(/Garanzia ricambio scaduta il/)).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: panoramica, zoom: 1.3, coda: 1.2, durata: 7.5 },
  );

  await s.scatto('Non tutti vedono il dettaglio: per alcuni ruoli il pezzo resta un aggregato, quanti sono coperti, senza il nome.', {
    su: page.getByText('Ricambi coperti da garanzia').locator('..'),
    zoom: 1.8,
    durata: 7.5,
  });

  await s.scatto("È una scelta di riservatezza configurabile per Ente: il pallino lo vedono tutti, l'elenco no.", {
    su: page.getByText(/Garanzia ricambio scaduta il/),
    zoom: 1.7,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    'La garanzia del pezzo è distinta da quella della macchina, e parte dal giorno del montaggio.',
    'Anche quella del pezzo accende il semaforo: è la spiegazione di molte macchine gialle senza interventi in ritardo.',
    "Il dettaglio dei pezzi coperti può essere riservato: il pallino lo vedono tutti, l'elenco dipende dal ruolo e dall'Ente.",
  ]);

  await s.scrivi();
});

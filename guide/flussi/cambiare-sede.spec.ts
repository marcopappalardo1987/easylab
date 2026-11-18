import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';

/**
 * Guida A5 — «Passare da una sede all'altra» (ADR-032). Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (11, in
 * tre capitoli da 3, 3 e 5): i testi scritti in `testi/cambiare-sede.md` sono
 * numerati come i passi.
 *
 * ⚠️ Girata con un utente **Tenant**: vede tutte le sedi del contratto, e la
 * tendina ne elenca più d'una.
 */
test('cambiare sede', async ({ page }) => {
  const s = new Scenografo(page, 'cambiare-sede', "Passare da una sede all'altra", 'Quando il tuo contratto ne copre più di una');

  const barra = page.getByRole('banner');
  const switcher = barra.getByRole('button', { name: /Sede di/ }).first();
  const riquadri = page.getByRole('link', { name: /Strumentazione idonea/ }).locator('..');

  await page.goto('/login');
  await page.fill('#email', 'paolo.greco@aurora.test');
  await page.fill('#password', 'guida-demo');
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');
  await s.avvia();

  // ── Uno: dove sei adesso ────────────────────────────────────────────────────
  s.cartello('Dove sei adesso', 'Parte 1 di 3');

  await s.scatto('Un contratto può coprire più sedi. Easy Lab te ne mostra una alla volta.', { durata: 6 });

  await s.scatto('Il nome della sede in cui sei sta sempre in alto a sinistra.', {
    su: switcher,
    zoom: 2.6,
    durata: 5,
  });

  await s.scatto('E i numeri della Dashboard sono soltanto i suoi.', {
    su: riquadri,
    zoom: 1.15,
    alone: false,
    durata: 5,
  });

  // ── Due: le tue sedi ────────────────────────────────────────────────────────
  s.cartello('Le tue sedi', 'Parte 2 di 3');

  await s.movimento(
    'Se le sedi sono più di una, il nome è cliccabile.',
    async () => {
      await switcher.click();
      await expect(page.getByText('Le tue sedi')).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: switcher, zoom: 2.2, coda: 1 },
  );

  await s.scatto('La tendina elenca le altre sedi del tuo contratto. Solo quelle: non si esce mai dal proprio.', {
    su: page.getByText('Le tue sedi').locator('..'),
    zoom: 2.2,
    durata: 7,
  });

  const bologna = page.getByRole('button', { name: 'Sede di Bologna' });

  await s.movimento(
    'Si sceglie, e si cambia.',
    async () => {
      await bologna.click();
      // Se il nome in barra non cambia lo switch non è avvenuto, e tutto ciò
      // che il video racconta da qui in poi sarebbe falso.
      await expect(barra.getByRole('button', { name: 'Sede di Bologna' }).first()).toBeVisible({ timeout: 10_000 });
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(600);
    },
    { su: bologna, zoom: 1.6, coda: 1.4 },
  );

  // ── Tre: che cosa cambia ────────────────────────────────────────────────────
  s.cartello('Che cosa cambia', 'Parte 3 di 3');

  await expect(page.getByText(/Sede di Bologna/).first()).toBeVisible();

  await s.scatto('Da questo momento tutto parla di Bologna: la Dashboard, gli elenchi, le scadenze.', { durata: 6 });

  await s.scatto('Il nome in alto lo conferma.', { su: switcher, zoom: 2.6, durata: 4 });

  const strumenti = page.getByRole('link', { name: 'Strumenti', exact: true });

  await s.movimento(
    'Anche gli strumenti sono i suoi.',
    async () => {
      await strumenti.click();
      await page.waitForURL('**/strumenti');
      await expect(page.locator('tbody tr').first()).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: strumenti, zoom: 1.6, coda: 1.2 },
  );

  await expect(page.locator('tbody')).not.toContainText('Milano');

  await s.scatto("Le macchine dell'altra sede non compaiono, e non si mescolano mai.", {
    su: page.locator('tbody tr').first(),
    zoom: 1.4,
    durata: 6,
  });

  const milano = page.getByRole('button', { name: 'Sede di Milano' });

  await s.movimento(
    'Per tornare indietro si rifà la stessa cosa: il nome, e la sede di prima.',
    async () => {
      await switcher.click();
      await expect(milano).toBeVisible();
      await page.waitForTimeout(700);
      await milano.click();
      await expect(barra.getByRole('button', { name: 'Sede di Milano' }).first()).toBeVisible({ timeout: 10_000 });
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(500);
    },
    { su: switcher, zoom: 2, coda: 1.2 },
  );

  s.chiusura('Da ricordare', [
    'La sede in cui ti trovi è sempre scritta in alto a sinistra.',
    'La tendina elenca soltanto le sedi del tuo contratto: da lì non si esce.',
    'Cambiando sede cambia tutto quello che vedi, e i dati delle due non si mescolano mai.',
  ]);

  await s.scrivi();
});

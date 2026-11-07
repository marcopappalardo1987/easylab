import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M3 — «Impersonare un cliente». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Guida INTERNA, girata col Superadmin di piattaforma. Stessa sequenza e
 * stesso numero di passi del copione precedente (9, in tre capitoli da 3): i
 * testi scritti in `testi/impersonazione.md` sono numerati come i passi.
 *
 * L'impersonazione qui è VERA: si entra come il Responsabile di una sede del
 * cliente demo, si crea un fornitore (fuori ripresa) e si esce, così il
 * registro dell'ultima parte mostra righe scritte adesso.
 *
 * ⚠️ L'uscita dall'impersonazione oggi riporta al login (difetto riportato il
 * 7 Nov 2026): si esce fuori ripresa e si rientra con `accedi()`.
 *
 * ⚠️ Il copione SCRIVE: `gira-in-fila.sh` azzera prima di ogni giratura.
 */
const FORNITORE = 'Fornitore di prova';

test('impersonazione', async ({ page }) => {
  const s = new Scenografo(page, 'impersonazione', 'Impersonare un cliente', 'Entrare come lui, e la traccia che resta');

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma/parco');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: perché esiste ──────────────────────────────────────────────────────
  s.cartello('Perché esiste', 'Parte 1 di 3');

  await s.scatto('Dal parco si legge soltanto: nessuna azione sulle macchine dei clienti, per scelta.', {
    su: page.locator('main p', { hasText: 'Sola lettura' }).first(),
    zoom: 1.6,
    durata: 6,
  });

  const impersonaMacchina = page.locator('[wire\\:click^="apriSceltaSuMacchina"]').first();
  const scelta = page.locator('[role=dialog]:visible').last();

  await s.movimento(
    'Quando serve intervenire davvero, da «Impersona» si sceglie la persona del cliente con cui entrare.',
    async () => {
      await impersonaMacchina.click();
      await expect(scelta.getByRole('link', { name: /Entra/ }).first()).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: impersonaMacchina, zoom: 1.2, coda: 1.2 },
  );

  await s.scatto('Si entra come quella persona: permessi, sede attiva e visibilità sono i suoi, e l\'ingresso è registrato.', {
    su: scelta.getByText(/Si entra come una persona/),
    zoom: 1.8,
    durata: 7,
  });

  // ── Due: i limiti ───────────────────────────────────────────────────────────
  await page.goto('/piattaforma');
  await page.waitForLoadState('networkidle');

  s.cartello('I limiti', 'Parte 2 di 3');

  await s.scatto('Non tutti sono impersonabili: il Developer non lo è mai, e nessuno può impersonare sé stesso.', {
    su: page.getByText(/il Developer non lo e' mai/),
    zoom: 2,
    durata: 6.5,
  });

  // Si torna alla scelta, fuori ripresa: l'«Entra» della cabina passa da
  // `/impersonate/take`, che oggi perde la sessione (difetto riportato); quello
  // del parco porta dritto alla macchina e funziona.
  await page.goto('/piattaforma/parco');
  await page.waitForLoadState('networkidle');
  await impersonaMacchina.click();
  const entra = scelta.getByRole('link', { name: /Entra/ }).first();
  await expect(entra).toBeVisible();

  await s.movimento(
    'Una volta dentro, «Piattaforma» sparisce dal menù: si è quella persona, non due persone insieme.',
    async () => {
      await entra.click();
      await page.waitForURL('**/strumenti/**');
      await expect(page.getByText('Stai impersonando')).toBeVisible();
      await expect(page.getByRole('link', { name: 'Piattaforma', exact: true })).toHaveCount(0);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(800);
    },
    { su: entra, zoom: 1, coda: 1.4 },
  );

  await s.scatto('Il banner resta in cima a ogni pagina: dice chi stai impersonando e chi sei davvero.', {
    su: page.getByText('Stai impersonando'),
    zoom: 1.5,
    durata: 7,
  });

  // Una scrittura fatta da dentro, fuori ripresa: è ciò che il registro deve attribuire.
  await page.goto('/fornitori');
  await page.getByRole('button', { name: /Nuovo fornitore/ }).click();
  await page.locator('#form\\.ragione_sociale').fill(FORNITORE);
  await page.locator('form:has(#form\\.ragione_sociale)').getByRole('button', { name: 'Salva' }).click();
  await expect(page.getByRole('cell', { name: FORNITORE })).toBeVisible();

  await page.getByRole('link', { name: /Esci dall'impersonation/ }).click();
  await page.waitForLoadState('networkidle');
  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma/audit');
  await page.waitForLoadState('networkidle');

  // ── Tre: la traccia ─────────────────────────────────────────────────────────
  s.cartello('La traccia', 'Parte 3 di 3');

  const rigaScrittura = page.locator('main tbody tr', { hasText: 'Creazione fornitore' }).first();
  await expect(page.locator('main tbody tr', { hasText: 'Impersonation avviata' }).first()).toBeVisible();
  await expect(page.locator('main tbody tr', { hasText: 'Impersonation terminata' }).first()).toBeVisible();

  await s.scatto("Apertura e chiusura sono due righe del registro col tuo nome: «Impersonation avviata» e «terminata».", {
    su: rigaScrittura,
    zoom: 1.3,
    alone: false,
    durata: 7,
  });

  await s.scatto("E ogni scrittura fatta nel frattempo porta, sotto il nome del cliente, quello di chi c'era dietro.", {
    su: rigaScrittura.getByText('per conto di'),
    zoom: 2.2,
    durata: 7,
  });

  await s.panoramica("È la ragione per cui l'impersonazione è uno strumento accettabile: non è invisibile.", 6.5);

  s.chiusura('Da ricordare', [
    'Dal parco si legge; per scrivere si impersona, e si vede esattamente ciò che vede il cliente.',
    "Il Developer non è impersonabile, e durante l'impersonazione la piattaforma è chiusa.",
    "Apertura, chiusura e ogni scrittura restano nel registro col nome di chi c'era dietro.",
  ]);

  await s.scrivi();
});

import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C5 — «Spostare una macchina». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (8, in
 * tre capitoli da 2, 3 e 3): i testi scritti in `testi/spostare-strumento.md`
 * sono numerati come i passi.
 *
 * Il trasloco si vede accadere: la finestra si apre, la destinazione si sceglie,
 * l'ubicazione sotto il nome cambia, e la riga compare fra gli spostamenti.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
// ⚠️ Senza `--lang` Chromium disegna i campi data all'americana (11/07/2026 per
// il 7 novembre): `locale` del config non basta. Gli altri argomenti ripetono
// quelli del config, che `launchOptions` qui sostituisce per intero.
test.use({ launchOptions: { args: ['--force-color-profile=srgb', '--font-render-hinting=none', '--lang=it-IT'] } });

test('spostare strumento', async ({ page }) => {
  const s = new Scenografo(page, 'spostare-strumento', 'Spostare una macchina', 'Il trasloco, e la traccia che lascia');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const ubicazione = page.getByText(/Sede di Milano ›/).first();

  // ── Uno: dove sta adesso ────────────────────────────────────────────────────
  s.cartello('Dove sta adesso', 'Parte 1 di 3');

  await expect(ubicazione).toContainText('Analisi Cliniche › Camera Bianca');

  await s.scatto("L'ubicazione completa è sotto il nome: sede, laboratorio, sotto-laboratorio.", {
    su: ubicazione,
    zoom: 2,
    durata: 6,
  });

  const sposta = page.getByRole('button', { name: 'Sposta' }).first();
  const destinazione = page.locator('#destinazioneId');

  await s.movimento(
    '«Sposta» apre il trasloco, e non va confuso con «Modifica»: qui non si cambia la macchina, si cambia dove sta.',
    async () => {
      await sposta.click();
      await expect(destinazione).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: sposta, zoom: 2, coda: 1.2 },
  );

  // ── Due: la destinazione ────────────────────────────────────────────────────
  s.cartello('La destinazione', 'Parte 2 di 3');

  await s.movimento(
    "La destinazione si sceglie fra i laboratori e i sotto-laboratori della sede: non è un indirizzo scritto a mano.",
    async () => {
      await destinazione.focus();
      await page.waitForTimeout(400);
      await destinazione.selectOption({ label: 'Spettrometria' });
      await expect(destinazione.locator('option:checked')).toHaveText('Spettrometria');
      await page.waitForTimeout(700);
    },
    { su: destinazione, zoom: 1.8, coda: 1 },
  );

  await s.scatto('La data serve a datare il trasloco, non a programmarlo: è quando la macchina si è spostata davvero.', {
    su: page.locator('#dataSpostamento'),
    zoom: 2,
    durata: 6.5,
  });

  const conferma = page.locator('form:has(#destinazioneId)').getByRole('button', { name: 'Sposta' });

  await s.movimento(
    'Si conferma.',
    async () => {
      await conferma.click();
      await expect(destinazione).toHaveCount(0, { timeout: 10_000 });
      await page.waitForTimeout(600);
    },
    { su: conferma, zoom: 2, coda: 1.2 },
  );

  // ── Tre: la traccia ─────────────────────────────────────────────────────────
  s.cartello('La traccia', 'Parte 3 di 3');

  await expect(ubicazione).toContainText('Spettrometria');

  await s.scatto("L'ubicazione in cima è cambiata, e con lei gli elenchi filtrati per laboratorio.", {
    su: ubicazione,
    zoom: 2,
    durata: 6,
  });

  const linguetta = page.locator('[data-tab="anagrafica"]');
  const spostamenti = page.getByText('Spostamenti', { exact: true }).locator('..');

  await s.movimento(
    'Lo spostamento non sostituisce il passato: resta scritto nello storico, con la data e il posto da cui veniva.',
    async () => {
      await linguetta.click();
      await expect(spostamenti).toContainText('Spettrometria');
      await spostamenti.scrollIntoViewIfNeeded();
      await page.waitForTimeout(900);
    },
    { su: linguetta, zoom: 1.2, coda: 1.4 },
  );

  await s.scatto("È la risposta alla domanda «dov'era prima», che serve più spesso di quanto si creda quando una macchina dà problemi.", {
    su: spostamenti,
    zoom: 1.6,
    durata: 7.5,
  });

  s.chiusura('Da ricordare', [
    '«Sposta» cambia dove sta la macchina; «Modifica» cambia che cosa è. Sono due gesti diversi.',
    "La destinazione si sceglie fra i laboratori: se quello giusto non c'è, va prima creato in «Laboratori».",
    'Ogni trasloco resta nello storico con la sua data: il passato non si sovrascrive.',
  ]);

  await s.scrivi();
});

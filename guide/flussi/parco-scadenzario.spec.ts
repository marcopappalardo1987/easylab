import { expect, test, type Page } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M3 — «Lo scadenzario di tutti i clienti». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Guida INTERNA, girata col Superadmin di piattaforma. Stessi sei passi del
 * copione precedente, in due capitoli da 3: `testi/parco-scadenzario.md` è
 * numerato come i passi.
 *
 * Non esiste un filtro per un cliente solo: il perimetro si stringe a un piano
 * o ai preferiti. Il copione segna «Laboratori Aurora» fra i preferiti dalla
 * cabina, fuori campo, e poi lo filtra. ⚠️ Scrive (la ★): `azzera.sh` prima.
 */
const ridipingi = async (page: Page): Promise<void> => {
  // ⚠️ Lo screencast si ferma a metà di una transizione: senza un ritocco
  // invisibile la clip finisce sullo stato di prima.
  await page.waitForTimeout(350);
  await page.evaluate(() => {
    const b = document.body;
    b.style.outline = b.style.outline ? '' : '1px solid transparent';
  });
  await page.waitForTimeout(250);
};

test('parco scadenzario', async ({ page }) => {
  const s = new Scenografo(page, 'parco-scadenzario', 'Lo scadenzario di tutti i clienti', 'Che cosa scade, su tutto il parco');

  await accedi(page, 'superadmin@easylab.test');

  // Fuori campo: Laboratori Aurora fra i preferiti, per filtrarlo nella parte 2.
  await page.goto('/piattaforma');
  await page.waitForLoadState('networkidle');
  const aurora = page.locator('tbody tr', { hasText: 'Laboratori Aurora' }).first();
  const stella = aurora.locator('button[wire\\:click^="alternaPreferito"]');
  await stella.click();
  await expect(stella).toHaveAttribute('aria-pressed', 'true');

  await page.goto('/piattaforma/parco/scadenzario');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: le scadenze di tutti ───────────────────────────────────────────────
  s.cartello('Le scadenze di tutti', 'Parte 1 di 2');

  await s.scatto("È lo Scadenzario che vede un cliente, esteso a tutti: le scadenze aperte di ogni parco, in un elenco solo.", {
    su: page.getByRole('heading', { name: 'Scadenzario di tutti i clienti' }),
    zoom: 1.7,
    durata: 7,
  });

  await s.scatto('La colonna del cliente è quella in più: dice a chi appartiene ogni riga.', {
    su: page.locator('tbody tr').first(),
    zoom: 1.4,
    durata: 5.5,
  });

  const contatori = page.locator('button[wire\\:click="filtra(\'scaduti\')"]').locator('xpath=..');

  await s.scatto('I tre contatori dicono dove si accumula lavoro, prima che sia il cliente a telefonare.', {
    su: contatori,
    zoom: 1.4,
    alone: false,
    durata: 6.5,
  });

  // ── Due: come si usa ────────────────────────────────────────────────────────
  s.cartello('Come si usa', 'Parte 2 di 2');

  const inScadenza = page.locator('button[wire\\:click="filtra(\'in_scadenza\')"]');
  const tipo = page.getByLabel('Tipo di intervento');

  await s.movimento(
    'I filtri sono gli stessi del cliente: scaduti, in scadenza, tipo di intervento.',
    async () => {
      await inScadenza.click();
      await page.waitForTimeout(900);
      await tipo.selectOption({ label: 'Taratura e certificazione' });
      await page.waitForTimeout(800);
      await expect(page.locator('tbody tr').first()).toContainText('Taratura e certificazione');
      await ridipingi(page);
    },
    { su: inScadenza, zoom: 1.2, coda: 1 },
  );

  const modo = page.locator('#perimetro-modo');

  await s.movimento(
    "Con «I miei preferiti» restano i clienti che si seguono: «avete tarature in scadenza questo mese» è un discorso diverso da «come va».",
    async () => {
      await modo.selectOption('preferiti');
      await page.waitForTimeout(900);
      await expect(page.locator('tbody tr').first()).toContainText('Laboratori Aurora');
      await expect(page.locator('tbody tr', { hasText: 'Gruppo Sanitario Demo' })).toHaveCount(0);
      await ridipingi(page);
    },
    { su: modo, zoom: 1.2, coda: 1.2 },
  );

  await s.scatto("Anche qui si legge soltanto: per intervenire si impersona, e la traccia porta chi c'era davvero.", {
    su: page.locator('tbody tr').first().getByRole('button', { name: /Impersona/ }),
    zoom: 1.8,
    durata: 6.5,
  });

  s.chiusura('Da ricordare', [
    'È lo Scadenzario del cliente, esteso a tutti, con la colonna del cliente in più.',
    'I contatori mostrano il lavoro che si accumula prima che diventi una telefonata.',
    'In sola lettura: per agire si impersona.',
  ]);

  await s.scrivi();
});

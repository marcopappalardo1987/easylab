import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida B1 — «L'albero dei laboratori». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (10, in
 * tre capitoli da 3, 4 e 3): i testi scritti in `testi/albero-anagrafica.md`
 * sono numerati come i passi.
 *
 * ⚠️ Girata con l'**Admin**: un Responsabile Reparto vede solo i nodi che gli
 * sono assegnati, quindi l'albero che ritrarrebbe sarebbe un ramo e non la
 * struttura. Si scende in Ematologia e non in Genetica Medica perché è seguita
 * da Giulia Ferrari: il blocco «Responsabili» (ADR-057) si vede pieno.
 */
test('albero laboratori', async ({ page }) => {
  const s = new Scenografo(page, 'albero-anagrafica', "L'albero dei laboratori", 'Dove sta ogni macchina: sede, laboratorio, sotto-laboratorio');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/laboratori');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: la sede ────────────────────────────────────────────────────────────
  s.cartello('La sede come è fatta davvero', 'Parte 1 di 3');

  await s.scatto('«Laboratori» apre la sede alla sua radice: è la struttura della sede, non un elenco di macchine.', {
    su: page.getByRole('heading', { name: 'Laboratori', level: 1 }),
    zoom: 1.7,
    durata: 6,
  });

  const griglia = page.getByRole('button', { name: /Analisi Cliniche/ }).locator('xpath=ancestor::div[contains(@class,"grid")][1]');

  await s.scatto('Al primo livello ci sono i laboratori, una scheda ciascuno.', {
    su: griglia,
    zoom: 1.3,
    alone: false,
    durata: 5,
  });

  await s.scatto('Ogni scheda dice quanti sotto-laboratori contiene e quante macchine stanno direttamente lì.', {
    su: page.getByRole('button', { name: /Radiologia/ }),
    zoom: 2.1,
    durata: 6.5,
  });

  // ── Due: scendere ───────────────────────────────────────────────────────────
  s.cartello('Scendere un livello alla volta', 'Parte 2 di 3');

  const filtro = page.locator('#cerca-nodi');

  await s.movimento(
    'Con tanti laboratori si filtra per nome, e si apre quello giusto cliccandolo.',
    async () => {
      await filtro.click();
      await filtro.pressSequentially('Emat', { delay: 120 });
      const scheda = page.getByRole('button', { name: /Ematologia/ });
      await expect(page.getByRole('button', { name: /Radiologia/ })).toHaveCount(0);
      await page.waitForTimeout(700);
      await scheda.click();
      await expect(page.getByRole('navigation', { name: 'Percorso' })).toContainText('Ematologia');
      await page.waitForTimeout(500);
    },
    { su: filtro, zoom: 1.4, coda: 1, suono: 'tastiera' },
  );

  await expect(page.locator('[data-responsabili]')).toContainText('Giulia Ferrari');

  await s.panoramica('Dentro ci sono i sotto-laboratori, chi segue il laboratorio, e le macchine che stanno qui.', 7);

  await s.scatto('Il percorso in alto dice sempre dove sei, e ogni pezzo è cliccabile.', {
    su: page.getByRole('navigation', { name: 'Percorso' }),
    zoom: 2.4,
    durata: 6,
  });

  const camera = page.getByRole('button', { name: /Camera Bianca/ });

  await s.movimento(
    'Si scende ancora, fino al sotto-laboratorio.',
    async () => {
      await camera.click();
      await expect(page.getByRole('navigation', { name: 'Percorso' })).toContainText('Camera Bianca');
      await page.waitForTimeout(500);
    },
    { su: camera, zoom: 1.6, coda: 1 },
  );

  // ── Tre: le macchine ────────────────────────────────────────────────────────
  s.cartello('In fondo ci sono le macchine', 'Parte 3 di 3');

  await expect(page.getByText('Nessun sotto-laboratorio qui')).toBeVisible();

  await s.scatto("In fondo all'albero non ci sono più laboratori: ci sono gli strumenti.", { durata: 6 });

  await s.scatto("Da qui si apre la scheda di una macchina, senza passare dall'elenco generale.", {
    su: page.locator('main a[href*="/strumenti/"]').first(),
    zoom: 2,
    durata: 6,
  });

  const radice = page.getByRole('navigation', { name: 'Percorso' }).getByRole('button', { name: 'Sede di Milano' });

  await s.movimento(
    'E il percorso riporta su di un livello, o alla radice, senza ricominciare.',
    async () => {
      await radice.click();
      await expect(page.getByRole('button', { name: /Analisi Cliniche/ })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: radice, zoom: 1.8, coda: 1.2 },
  );

  s.chiusura('Da ricordare', [
    "«Laboratori» risponde alla domanda «dove», l'elenco degli strumenti alla domanda «quale».",
    'Il filtro per nome restringe le schede del livello in cui sei, a ogni livello.',
    'Il percorso in alto è la via di ritorno: dice dove sei e riporta indietro di un passo.',
  ]);

  await s.scrivi();
});

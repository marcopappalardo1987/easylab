import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C8 — «Dove è installato un modello». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stesso numero di passi del copione precedente (7, in due capitoli da 4 e
 * 3). Il passo 5 ora clicca davvero un laboratorio e porta all'elenco filtrato:
 * il richiamo (prima il 7) si racconta lì, sulle unità vere, e l'acquisto (prima
 * il 6) tornando al riquadro del modello. `testi/per-modello.md` segue quest'ordine.
 *
 * Il copione non scrive niente.
 */
test('per modello', async ({ page }) => {
  const s = new Scenografo(page, 'per-modello', 'Dove è installato un modello', 'Dato un modello, in quali laboratori sta e quante unità');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/modelli');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const cerca = page.getByPlaceholder(/Cerca un modello/);
  const riquadri = page.locator('[wire\\:key^="modello-"] h2');

  // ── Uno: la domanda al contrario ────────────────────────────────────────────
  s.cartello('La domanda al contrario', 'Parte 1 di 2');

  await s.scatto("«Per modello» è la seconda linguetta degli strumenti, e ribalta la domanda: non «dov'è questa macchina», ma «dove sono tutte quelle di questo tipo».", {
    su: page.getByRole('link', { name: 'Per modello' }),
    zoom: 2,
    durata: 8,
  });

  await s.scatto('Ogni riquadro è un modello, con i laboratori in cui è installato e quante unità per ciascuno.', {
    su: riquadri.first().locator('../..'),
    zoom: 1.4,
    durata: 7,
  });

  await s.scatto('La ricerca filtra sui modelli, non sulle macchine: basta una parte del nome commerciale.', {
    su: cerca,
    zoom: 1.8,
    durata: 6.5,
  });

  await s.movimento(
    'Restano i modelli che corrispondono, con la loro distribuzione nei laboratori.',
    async () => {
      await cerca.click();
      await cerca.pressSequentially('Steri', { delay: 140 });
      await expect(riquadri.filter({ hasNotText: /Steri/i })).toHaveCount(0);
      await expect(riquadri.first()).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: cerca, zoom: 1.2, coda: 1.4, suono: 'tastiera' },
  );

  // ── Due: a che cosa serve ───────────────────────────────────────────────────
  s.cartello('A che cosa serve', 'Parte 2 di 2');

  const modello = (await riquadri.first().textContent())!.trim();
  const etichetta = riquadri.first().locator('../..').getByRole('link').first();
  const laboratorio = (await etichetta.textContent())!.trim().replace(/\s+\d+$/, '');

  await s.movimento(
    "Cliccando un laboratorio si apre l'elenco degli strumenti già filtrato su quel modello e quel laboratorio.",
    async () => {
      await etichetta.click();
      await page.waitForURL('**/strumenti?**');
      await expect(page.locator('#search')).toHaveValue(modello);
      await expect(page.locator('tbody tr').first()).toContainText(laboratorio);
      await page.waitForTimeout(900);
    },
    { su: etichetta, zoom: 1.5, coda: 1.4 },
  );

  await s.scatto("Ed è la vista con cui si gestisce un richiamo: se un modello ha un difetto noto, ecco le unità esatte da controllare.", {
    su: page.locator('tbody'),
    zoom: 1.3,
    alone: false,
    durata: 7.5,
  });

  await page.goto('/strumenti/modelli');
  await page.waitForLoadState('networkidle');
  await cerca.fill('Steri');
  await expect(riquadri.filter({ hasNotText: /Steri/i })).toHaveCount(0);
  await expect(riquadri.first()).toHaveText(modello);
  const conteggio = riquadri.first().locator('..').getByText(/unità/);

  await s.scatto('È anche quella con cui si decide un acquisto: sapere quante unità hai, e in quanti laboratori, cambia la trattativa col fornitore.', {
    su: conteggio,
    zoom: 2.2,
    durata: 8,
  });

  s.chiusura('Da ricordare', [
    '«Elenco» risponde su una macchina, «Per modello» risponde su un tipo di macchina.',
    'Ogni laboratorio elencato è un collegamento: porta alle unità di quel modello in quel laboratorio.',
    'Serve per gli acquisti, per i ricambi comuni e per i richiami del fornitore.',
  ]);

  await s.scrivi();
});

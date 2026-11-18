import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida H1 — «I fornitori». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (11, in
 * tre capitoli da 3, 5 e 3): i testi scritti in `testi/fornitori.md` sono
 * numerati come i passi.
 *
 * La terza parte, che prima si raccontava con tre fermi sull'elenco, ora si vede
 * accadere: dalla scheda di una macchina si apre il selettore del fornitore, si
 * scrive, si sceglie (🔗 ADR-051), e il conteggio in elenco sale.
 *
 * ⚠️ Il copione SCRIVE (crea un fornitore, lo lega a una macchina): `azzera.sh`
 * prima di ogni giratura.
 */
test('fornitori', async ({ page }) => {
  const s = new Scenografo(page, 'fornitori', 'I fornitori', 'A chi si telefona quando una macchina si guasta');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/fornitori');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: a che cosa serve ───────────────────────────────────────────────────
  s.cartello('A che cosa serve', 'Parte 1 di 3');

  await s.scatto("«Fornitori» è l'elenco di chi vende e assiste le macchine della sede.", {
    su: page.getByRole('heading', { name: 'Fornitori' }),
    zoom: 1.7,
    durata: 6,
  });

  await s.scatto('Ogni riga porta i contatti e quante macchine gli sono legate: è il numero che dice quanto conta quel fornitore per te.', {
    su: page.locator('tbody tr').first(),
    zoom: 1.4,
    durata: 7,
  });

  await s.panoramica("Non è una rubrica generica: un fornitore esiste qui perché una macchina lo indica, e serve il giorno del guasto.", 7);

  // ── Due: aggiungerne uno ────────────────────────────────────────────────────
  s.cartello('Aggiungerne uno', 'Parte 2 di 3');

  const nuovo = page.getByRole('button', { name: /Nuovo fornitore/ });

  await s.movimento(
    'Se ne aggiunge uno quando arriva una macchina di una marca nuova.',
    async () => {
      await nuovo.click();
      await expect(page.locator('#form\\.ragione_sociale')).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: nuovo, zoom: 1.5, coda: 1 },
  );

  await s.movimento(
    'La ragione sociale è quella con cui emette fattura, non il nome commerciale abbreviato.',
    async () => {
      await page.locator('#form\\.ragione_sociale').click();
      await page.locator('#form\\.ragione_sociale').pressSequentially('Zeiss Italia', { delay: 90 });
      await page.waitForTimeout(400);
    },
    { su: page.locator('#form\\.ragione_sociale'), zoom: 1.6, coda: 0.8, suono: 'tastiera' },
  );

  await s.movimento(
    "Telefono ed email sono quelli dell'assistenza, non del commerciale: sono i contatti che servono quando la macchina è ferma.",
    async () => {
      await page.locator('#form\\.email').click();
      await page.locator('#form\\.email').pressSequentially('assistenza@zeiss.example', { delay: 60 });
      await page.locator('#form\\.telefono').click();
      await page.locator('#form\\.telefono').pressSequentially('02 1234567', { delay: 80 });
      await page.waitForTimeout(400);
    },
    { su: page.locator('#form\\.email'), zoom: 1.4, coda: 0.8, suono: 'tastiera' },
  );

  await s.scatto('Le note sono il posto per il numero di contratto, gli orari, o il nome della persona che risponde.', {
    su: page.locator('#form\\.note'),
    zoom: 1.6,
    durata: 6.5,
  });

  const salva = page.locator('form:has(#form\\.ragione_sociale)').getByRole('button', { name: 'Salva' });

  await s.movimento(
    'Si salva.',
    async () => {
      await salva.click();
      await expect(page.locator('#form\\.ragione_sociale')).toHaveCount(0);
      await expect(page.getByRole('cell', { name: 'Zeiss Italia' })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: salva, zoom: 1.8, coda: 1.2 },
  );

  // ── Tre: il legame con una macchina ─────────────────────────────────────────
  s.cartello('Il legame con una macchina', 'Parte 3 di 3');

  const rigaZeiss = page.locator('tbody tr', { has: page.getByRole('cell', { name: 'Zeiss Italia' }) });

  await s.scatto('Il fornitore appena creato è in elenco, con zero macchine collegate.', {
    su: rigaZeiss,
    zoom: 1.5,
    durata: 6,
  });

  // Una macchina qualunque della sede: si apre la scheda e il modulo di modifica.
  await page.goto('/strumenti');
  await page.locator('tbody tr a').first().click();
  await page.waitForURL('**/strumenti/**');
  await page.getByRole('button', { name: /Modifica/ }).first().click();
  const selettore = page.locator('[data-selettore-fornitore="strumento"]');
  await expect(selettore).toBeVisible();

  await s.movimento(
    'Il legame si fa dalla scheda della macchina, nel campo «Fornitore»: si apre, si scrive il nome, e si sceglie.',
    async () => {
      await selettore.click();
      const cerca = page.locator('#fornitore-strumento-cerca');
      await expect(cerca).toBeVisible();
      await cerca.pressSequentially('Zeiss', { delay: 110 });
      const voce = page.getByRole('option').getByRole('button', { name: 'Zeiss Italia' });
      await expect(voce).toBeVisible();
      await page.waitForTimeout(500);
      await voce.click();
      await expect(selettore).toContainText('Zeiss Italia');
      await page.getByRole('button', { name: 'Salva' }).last().click();
      await page.waitForTimeout(900);
    },
    { su: selettore, zoom: 1.3, coda: 1.2 },
  );

  await page.goto('/fornitori');
  await page.waitForLoadState('networkidle');

  await s.scatto('Da quel momento il conteggio sale, e dalla macchina si arriva a chi chiamare senza cercare in rubrica.', {
    su: page.locator('tbody tr', { has: page.getByRole('cell', { name: 'Zeiss Italia' }) }),
    zoom: 1.5,
    durata: 6.5,
  });

  s.chiusura('Da ricordare', [
    "L'elenco esiste per rispondere a «a chi telefono»: i contatti da mettere sono quelli dell'assistenza.",
    'Il legame si fa dalla scheda della macchina: qui si crea il fornitore, là lo si sceglie scrivendone il nome.',
    'Il numero di macchine collegate dice quanto quel fornitore pesa sul tuo parco.',
  ]);

  await s.scrivi();
});

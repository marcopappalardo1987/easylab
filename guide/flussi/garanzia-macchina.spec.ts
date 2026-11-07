import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida E1 — «La garanzia della macchina». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (10, in
 * tre capitoli da 2, 5 e 3): i testi scritti in `testi/garanzia-macchina.md`
 * sono numerati come i passi.
 *
 * La macchina è la 195 del seme: gialla per UN solo motivo, la garanzia
 * scaduta, che la Panoramica elenca. ⚠️ Il rinnovo NON la fa tornare verde:
 * il semaforo guarda la scadenza minima fra TUTTE le garanzie (ADR-004), e
 * quella vecchia resta in elenco. Che la garanzia si scriva già in «Nuovo strumento»
 * (ADR-059) lo dice il passo 3.
 *
 * ⚠️ Il copione SCRIVE (registra una garanzia): `azzera.sh` prima di ogni giratura.
 */
test('garanzia macchina', async ({ page }) => {
  const s = new Scenografo(page, 'garanzia-macchina', 'La garanzia della macchina', 'Fino a quando è coperta, e perché conta per il semaforo');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/195');
  await page.waitForLoadState('networkidle');
  await expect(page.getByText(/Motivi \(1\)/i)).toBeVisible();
  await s.avvia();

  // ── Uno: dove vive ──────────────────────────────────────────────────────────
  s.cartello('Dove vive', 'Parte 1 di 3');

  const linguetta = page.getByRole('button', { name: /^Garanzie/ }).first();
  await expect(linguetta).toContainText(/\d/);

  await s.movimento(
    'La garanzia sta in una linguetta sua, perché non è una manutenzione: è una copertura, e ha una scadenza propria.',
    async () => {
      await linguetta.click();
      await expect(page.getByRole('button', { name: /Nuova garanzia/ })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: linguetta, zoom: 1.6, coda: 1, durata: 7 },
  );

  const scaduta = page.locator('tbody tr').filter({ hasText: 'Scaduta' }).filter({ visible: true }).first();
  await s.scatto('Ogni riga dice da quando parte, quanto dura e quando scade davvero. Questa è scaduta, e il numerino sulla linguetta lo segnala.', {
    su: scaduta,
    zoom: 1.5,
    durata: 7.5,
  });

  // ── Due: registrarne una ────────────────────────────────────────────────────
  s.cartello('Registrarne una', 'Parte 2 di 3');

  const nuova = page.getByRole('button', { name: /Nuova garanzia/ });
  await s.movimento(
    'Se ne aggiunge una quando si rinnova il contratto. La prima si può scrivere già in «Nuovo strumento», registrando la macchina.',
    async () => {
      await nuova.click();
      await expect(page.locator('#garanziaForm\\.data_inizio')).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: nuova, zoom: 1.6, coda: 1, durata: 8 },
  );

  const inizio = page.locator('#garanziaForm\\.data_inizio');
  await s.movimento(
    "La data di inizio è quella del contratto, che non sempre coincide con l'installazione della macchina.",
    async () => {
      await inizio.fill('2026-11-01');
      await page.waitForTimeout(700);
    },
    { su: inizio, zoom: 1.8, coda: 0.8, durata: 7 },
  );

  const durata = page.locator('#garanziaForm\\.durata_mesi');
  // ⚠️ La durata resta quella proposta (24): scriverne una a mano oggi manda
  // il salvataggio in errore 500 (`durata_mesi` arriva come stringa a
  // `Garanzia::normalizzaScadenza()` → `addMonths()`). Difetto riportato.
  await s.scatto('La durata si dà in mesi: ventiquattro, trentasei, quello che dice il contratto.', {
    su: durata,
    zoom: 1.8,
    durata: 6,
  });

  await s.scatto('La scadenza non si scrive: la calcola Easy Lab sommando inizio e durata, così non ci sono due date che possono discordare.', {
    su: page.getByText(/scadenza effettiva è calcolata/i),
    zoom: 2,
    durata: 7.5,
  });

  const salva = page.locator('form:has(#garanziaForm\\.data_inizio)').getByRole('button', { name: 'Salva' });
  await s.movimento(
    'Si salva.',
    async () => {
      await salva.click();
      await expect(inizio).toBeHidden({ timeout: 10_000 });
      await page.waitForTimeout(600);
    },
    { su: salva, zoom: 1.8, coda: 1.2 },
  );

  // ── Tre: che cosa muove ─────────────────────────────────────────────────────
  s.cartello('Che cosa muove', 'Parte 3 di 3');

  const nuovaRiga = page.locator('tbody tr').filter({ hasText: '01/11/2028' }).filter({ visible: true });
  await expect(nuovaRiga).toHaveCount(1);
  await expect(nuovaRiga).toContainText('Attiva');

  await s.scatto('La garanzia nuova è in elenco con il suo stato, attiva fino al 2028; quella scaduta resta accanto, con il suo.', {
    su: nuovaRiga,
    zoom: 1.5,
    durata: 7,
  });

  const panoramica = page.getByRole('button', { name: 'Panoramica' });
  await s.movimento(
    'E conta per il semaforo: una garanzia scaduta accende la macchina come farebbe una manutenzione non fatta.',
    async () => {
      await panoramica.click();
      await expect(page.getByText(/Garanzia macchina scaduta il/)).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: panoramica, zoom: 1.3, coda: 1.2, durata: 7 },
  );

  await s.scatto('È il motivo per cui certe macchine sono gialle senza nessun intervento in ritardo: il motivo è scritto in Panoramica.', {
    su: page.getByText(/Garanzia macchina scaduta il/),
    zoom: 1.6,
    durata: 7.5,
  });

  s.chiusura('Da ricordare', [
    'La scadenza si calcola da inizio più durata: non esistono due date che possono discordare.',
    'Una garanzia scaduta accende il semaforo come una manutenzione non fatta.',
    'Se una macchina è gialla senza interventi in ritardo, la spiegazione è quasi sempre qui.',
  ]);

  await s.scrivi();
});

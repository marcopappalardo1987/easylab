import { expect, test, type Page } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M2 — «Il registro di audit». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Guida INTERNA, girata col Superadmin di piattaforma. Stessa sequenza e
 * stesso numero di passi del copione precedente (9, in tre capitoli da 3): i
 * testi scritti in `testi/registro-audit.md` sono numerati come i passi.
 *
 * Il seme non ha righe scritte in impersonazione né soggetti cestinati: le
 * produce il copione, fuori ripresa, impersonando un Responsabile del cliente
 * demo. ⚠️ Il copione SCRIVE: `gira-in-fila.sh` azzera prima di ogni giratura.
 */
const TELEFONO = '02 7654321';
const FORNITORE = 'Fornitore di prova';

/** Impersona dal parco, crea un fornitore, ne corregge il telefono, lo cestina, esce. */
const scriviInImpersonazione = async (page: Page): Promise<void> => {
  await page.goto('/piattaforma/parco');
  await page.waitForLoadState('networkidle');
  await page.locator('[wire\\:click^="apriSceltaSuMacchina"]').first().click();
  await page.locator('[role=dialog]:visible a', { hasText: 'Entra' }).first().click();
  await page.waitForURL('**/strumenti/**');
  await expect(page.getByText('Stai impersonando')).toBeVisible();

  await page.goto('/fornitori');
  await page.getByRole('button', { name: /Nuovo fornitore/ }).click();
  await page.locator('#form\\.ragione_sociale').fill(FORNITORE);
  await page.locator('#form\\.telefono').fill('02 1234567');
  await page.locator('form:has(#form\\.ragione_sociale)').getByRole('button', { name: 'Salva' }).click();
  const riga = page.locator('tbody tr', { hasText: FORNITORE });
  await expect(riga).toBeVisible();
  await riga.getByRole('button', { name: 'Modifica' }).click();
  await page.locator('#form\\.telefono').fill(TELEFONO);
  await page.locator('form:has(#form\\.ragione_sociale)').getByRole('button', { name: 'Salva' }).click();
  await expect(page.locator('#form\\.ragione_sociale')).toHaveCount(0);
  await expect(riga).toContainText(TELEFONO);
  await riga.getByRole('button', { name: 'Elimina' }).click();
  await page.locator('[role=dialog]:visible').getByRole('button', { name: /Elimina|Conferma/ }).last().click();
  await expect(page.locator('tbody tr', { hasText: FORNITORE })).toHaveCount(0);

  await page.getByRole('link', { name: /Esci dall'impersonation/ }).click();
  await page.waitForLoadState('networkidle');
  // ⚠️ Oggi l'uscita riporta al login (difetto riportato, 7 Nov 2026): si rientra.
  await accedi(page, 'superadmin@easylab.test');
};

test('registro audit', async ({ page }) => {
  const s = new Scenografo(page, 'registro-audit', 'Il registro di audit', 'Chi ha fatto che cosa, e quando');

  await accedi(page, 'superadmin@easylab.test');
  await scriviInImpersonazione(page);

  await page.goto('/piattaforma/audit');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const rigaModifica = page.locator('main tbody tr', { hasText: 'per conto di' }).filter({ hasText: 'Modifica fornitore' }).first();
  const rigaFornitore = page.locator('main tbody tr', { hasText: 'cestinato' }).first();
  const avviso = page.locator('main p', { hasText: "durante un'impersonazione" }).first();

  // ── Uno: che cosa registra ──────────────────────────────────────────────────
  s.cartello('Che cosa registra', 'Parte 1 di 3');

  await s.scatto('Il registro raccoglie le scritture di dominio: chi ha creato, modificato o cancellato che cosa, e quando.', {
    su: page.locator('main a', { hasText: 'Registro di audit' }).first(),
    zoom: 1.5,
    durata: 6.5,
  });

  await s.panoramica('Non è un log tecnico: è la memoria delle decisioni, e serve quando due persone ricordano diversamente.', 7);

  const freccia = rigaModifica.getByRole('button', { name: /Dettaglio della riga/ });

  await s.movimento(
    'Ogni riga ha un autore, un soggetto e un momento. Aprendola si vedono i valori cambiati, prima e dopo.',
    async () => {
      await freccia.click();
      await expect(page.getByRole('columnheader', { name: 'Prima' })).toBeVisible();
      await expect(page.locator('main').getByText(TELEFONO)).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: freccia, zoom: 1.3, coda: 1.2 },
  );

  // ── Due: l'impersonazione ───────────────────────────────────────────────────
  s.cartello("L'impersonazione", 'Parte 2 di 3');

  await s.scatto("Una riga scritta in impersonazione porta il nome del cliente e, sotto, quello di chi c'era davvero dietro.", {
    su: rigaModifica.getByText('per conto di'),
    zoom: 2.2,
    durata: 7,
  });

  const chi = page.locator('#audit-chi');

  await s.movimento(
    'Cercando il nome del cliente escono anche i gesti nostri: senza quel timbro il registro li direbbe suoi.',
    async () => {
      await chi.click();
      await chi.pressSequentially('Responsabile Ospedale', { delay: 70 });
      await expect(page.locator('main tbody tr', { hasText: 'Impersonation avviata' })).toHaveCount(0);
      await expect(page.locator('main tbody tr', { hasText: 'per conto di' }).first()).toBeVisible();
      await page.waitForTimeout(900);
    },
    { su: chi, zoom: 1.2, coda: 1.4, suono: 'tastiera' },
  );

  await chi.fill('');
  await expect(page.locator('main tbody tr', { hasText: 'Impersonation avviata' }).first()).toBeVisible();

  await s.scatto('Vale dentro una richiesta. Ciò che parte in coda, da console o da un webhook non ha sessione, quindi non ha timbro: è dichiarato.', {
    su: page.locator('main p', { hasText: 'differite' }).first(),
    zoom: 1.15,
    durata: 8,
  });

  // ── Tre: che cosa NON si può fare ───────────────────────────────────────────
  s.cartello('Che cosa NON si può fare', 'Parte 3 di 3');

  await s.scatto('Il registro non si modifica e non si cancella: nessuna riga ha un pulsante per farlo, solo il dettaglio.', {
    su: page.locator('main thead tr').last(),
    zoom: 1.3,
    durata: 7,
  });

  await s.scatto('Un soggetto cestinato resta nominato: la riga lo segna «cestinato» invece di sparire.', {
    su: rigaFornitore,
    zoom: 1.5,
    durata: 6.5,
  });

  await s.scatto("L'attribuzione è affidabile da una data dichiarata in pagina: prima di quella, lo storico è quello che è.", {
    su: avviso.locator('strong').nth(1),
    zoom: 1.15,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    'Registra le scritture di dominio con autore, soggetto e momento: è la memoria delle decisioni.',
    "Le righe scritte in impersonazione portano chi c'era davvero dietro.",
    'Non si modifica e non si cancella: è la sola ragione per cui ci si può fidare.',
  ]);

  await s.scrivi();
});

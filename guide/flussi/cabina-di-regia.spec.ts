import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M1 — «La cabina di regia». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Guida INTERNA: non compare nella Guida dei clienti, che filtra per
 * permesso. Girata col Superadmin di piattaforma del seme.
 *
 * Dieci passi, uno in più del copione precedente: la scadenza del comodato
 * (🔗 ADR-058) si imposta in un pannello e si legge poi sulla riga, e sono due
 * momenti. I preferiti non ordinano l'elenco: alimentano il filtro del Parco.
 *
 * ⚠️ Il copione SCRIVE (una ★, la scadenza del comodato): `azzera.sh` prima di
 * ogni giratura. Il cliente nuovo NON si crea: «Crea e invita» spedirebbe l'invito.
 */
test('cabina di regia', async ({ page }) => {
  const s = new Scenografo(page, 'cabina-di-regia', 'La cabina di regia', 'La home di piattaforma: clienti, numeri, contratti');

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: che cosa si vede entrando ──────────────────────────────────────────
  s.cartello('Che cosa si vede entrando', 'Parte 1 di 3');

  await s.scatto('La cabina è la home di chi gestisce EasyLab, non di chi lo usa: raccoglie i clienti e come stanno.', {
    su: page.getByRole('heading', { name: 'Piattaforma' }),
    zoom: 1.7,
    durata: 6.5,
  });

  const numeri = page.getByText('Ricavo mensile').locator('xpath=ancestor::*[contains(@class,"grid")][1]');

  await s.scatto('In cima i numeri della piattaforma: ricavo mensile a listino, clienti, sedi e strumenti. I filtri sotto non li cambiano.', {
    su: numeri,
    zoom: 1.5,
    alone: false,
    durata: 7,
  });

  await s.panoramica("Sotto, l'andamento degli ultimi dodici mesi e l'elenco dei clienti, con piano, stato, sedi e strumenti di ciascuno.", 7.5);

  // ── Due: trovare un cliente ─────────────────────────────────────────────────
  s.cartello('Trovare un cliente', 'Parte 2 di 3');

  const cerca = page.locator('#cerca-cliente');
  const righe = page.locator('tbody.divide-y > tr');
  // Il campo in alto: sotto deve starci la riga aperta con le sue sedi.
  await cerca.scrollIntoViewIfNeeded();

  await s.movimento(
    'La ricerca lavora su ragione sociale, partita IVA e nome della sede: è il gesto più rapido quando arriva una telefonata.',
    async () => {
      await cerca.click();
      await cerca.pressSequentially('Aurora', { delay: 110 });
      await expect(page.getByText('Gruppo Sanitario Demo')).toHaveCount(0);
      await expect(page.getByText('Laboratori Aurora').first()).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: cerca, zoom: 1.3, coda: 1, suono: 'tastiera' },
  );

  const rigaAurora = righe.filter({ hasText: 'Laboratori Aurora' }).first();
  const stella = rigaAurora.locator('[wire\\:click^="alternaPreferito"]');

  await s.movimento(
    'La ★ mette il cliente fra i propri preferiti: le schede del Parco clienti li raccolgono nel filtro «I miei preferiti».',
    async () => {
      await stella.click();
      await expect(stella).toContainText('★');
      await page.waitForTimeout(600);
    },
    { su: stella, zoom: 1.6, coda: 1 },
  );

  const espandi = rigaAurora.locator('[wire\\:click^="espandi"]');

  await s.movimento(
    'Ogni riga si apre sulle sedi del cliente, e porta le azioni su di lui: impersonare, bloccare, aggiungere un Ente, i dati fiscali.',
    async () => {
      await espandi.click();
      await expect(page.getByText('Sede di Milano').first()).toBeVisible();
      // La pagina si allunga verso il basso: si scende quanto basta a vedere le sedi.
      await page.evaluate(() => window.scrollBy({ top: 220, behavior: 'smooth' }));
      await page.waitForTimeout(900);
    },
    { su: espandi, zoom: 1.2, coda: 1.2 },
  );

  // ── Tre: la vita del contratto ──────────────────────────────────────────────
  s.cartello('La vita del contratto', 'Parte 3 di 3');

  await cerca.fill('');
  await expect(page.getByText('Gruppo Sanitario Demo').first()).toBeVisible();
  const rigaDemo = righe.filter({ hasText: 'Gruppo Sanitario Demo' }).first();
  await rigaDemo.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  const comodato = rigaDemo.locator('[wire\\:click^="apriComodato"]');
  const durata = page.locator('#comodato-durata');

  await s.movimento(
    "Per un cliente in comodato d'uso, «Comodato» dice fino a quando vale: 12, 24 o 36 mesi, una data precisa, o nessuna scadenza.",
    async () => {
      await comodato.click();
      await expect(durata).toBeVisible();
      await page.waitForTimeout(900);
      await durata.selectOption('24');
      await expect(page.locator('[data-anteprima-scadenza]')).toBeVisible();
      await page.waitForTimeout(900);
    },
    { su: comodato, zoom: 1, coda: 1.4 },
  );

  const salva = page.locator('[wire\\:click="salvaComodato"]');

  await s.movimento(
    "Salvata, la scadenza si legge sulla riga. Trenta giorni prima parte un'email al cliente e ai Superadmin.",
    async () => {
      await salva.click();
      await expect(durata).toBeHidden();
      await expect(rigaDemo.locator('[data-comodato-scade]')).toContainText('comodato fino al');
      await page.waitForTimeout(700);
    },
    { su: salva, zoom: 1, coda: 1.6 },
  );

  await page.evaluate(() => window.scrollTo(0, 0));
  const nuovo = page.locator('[wire\\:click="apriProvisioning"]');

  await s.movimento(
    "«Nuovo cliente» fa nascere insieme il cliente, la sua prima sede e l'Amministratore, che riceve l'invito per email.",
    async () => {
      await nuovo.click();
      await expect(page.locator('#prov-nome')).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: nuovo, zoom: 1, coda: 1.4 },
  );

  const durataNuovo = page.locator('#prov-comodato-durata');

  await s.movimento(
    "Con il piano in comodato d'uso, la stessa finestra chiede subito quanto dura il comodato.",
    async () => {
      await durataNuovo.selectOption('36');
      await expect(page.locator('[data-anteprima-scadenza]')).toBeVisible();
      await page.waitForTimeout(900);
    },
    { su: durataNuovo, zoom: 1.6, coda: 1.4 },
  );

  s.chiusura('Da ricordare', [
    'La cabina è la vista di piattaforma: i clienti e come stanno, non le macchine di uno solo.',
    'La ★ segna i propri preferiti, che il Parco clienti raccoglie nel filtro «I miei preferiti».',
    "Il comodato d'uso può avere una scadenza: un mese prima parte l'avviso, e alla scadenza nulla si blocca da solo.",
  ]);

  await s.scrivi();
});

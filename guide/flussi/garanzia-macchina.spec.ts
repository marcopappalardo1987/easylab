import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida E1 — «La garanzia della macchina».
 *
 * ⚠️ Il copione SCRIVE (crea una garanzia): `azzera.sh` prima di ogni giratura.
 */
test('garanzia macchina', async ({ page }) => {
  const g = new Regista(page, 'garanzia-macchina', 'La garanzia della macchina', 'Fino a quando è coperta, e perché conta per il semaforo');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Dove vive');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: 'Garanzie' }).first().click();
  await page.waitForTimeout(600);

  await g.passo("La garanzia sta in una linguetta sua, perché non è una manutenzione: è una copertura, e ha una scadenza propria.", {
    zoom: 1,
    durata: 8,
  });

  // ⚠️ Niente `su` su elementi interni alla linguetta: il pannello si rimonta
  // e Playwright si trova in mano un nodo staccato dal DOM.
  await g.passo("Ogni riga dice da quando parte, quanto dura e quando scade davvero.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Registrarne una');

  // ⚠️ Lo scatto e il click sono separati: il pulsante sta dentro la linguetta,
  // che Alpine rimonta, e il click del regista (che va a coordinate) finiva a
  // volte nel vuoto. Il passo ritrae, poi si clicca col locator.
  await g.passo('Se ne aggiunge una nuova quando arriva il contratto, o quando si rinnova.', {
    su: page.getByRole('button', { name: /Nuova garanzia/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  await page.getByRole('button', { name: /Nuova garanzia/ }).first().click();
  await page.waitForTimeout(900);

  await page.fill('#garanziaForm\\.data_inizio', '2026-03-01');

  await g.passo("La data di inizio è quella del contratto, che non sempre coincide con l'installazione della macchina.", {
    su: page.locator('#garanziaForm\\.data_inizio'),
    zoom: 2.1,
    durata: 8,
  });

  await page.fill('#garanziaForm\\.durata_mesi', '24');

  await g.passo('La durata si dà in mesi: ventiquattro, trentasei, quello che dice il contratto.', {
    su: page.locator('#garanziaForm\\.durata_mesi'),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo("La scadenza non si scrive: la calcola Easy Lab sommando inizio e durata, così non ci sono due date che possono discordare.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('Si salva.', {
    su: page.locator('form:has(#garanziaForm\\.data_inizio)').getByRole('button', { name: 'Salva' }),
    zoom: 2.3,
    durata: 5,
  });

  await page.locator('form:has(#garanziaForm\\.data_inizio)').getByRole('button', { name: 'Salva' }).click();
  await page.waitForTimeout(1200);

  g.capitolo('Parte 3 di 3', 'Che cosa muove');

  await g.passo("La garanzia è in elenco con il suo stato: in corso, in scadenza, scaduta.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("E conta per il semaforo: una garanzia scaduta accende la macchina come farebbe una manutenzione non fatta.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È il motivo per cui certe macchine sono gialle senza avere nessun intervento in ritardo: il motivo è scritto in Panoramica.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'La scadenza si calcola da inizio più durata: non esistono due date che possono discordare.',
    'Una garanzia scaduta accende il semaforo come una manutenzione non fatta.',
    'Se una macchina è gialla senza interventi in ritardo, la spiegazione è quasi sempre qui.',
  ]);

  g.scrivi();
});

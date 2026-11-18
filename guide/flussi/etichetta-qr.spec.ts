import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C8 — «L'etichetta QR». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (8, in
 * tre capitoli da 3, 3 e 2): i testi scritti in `testi/etichetta-qr.md` sono
 * numerati come i passi.
 *
 * La terza parte prima si raccontava sul pulsante fermo; ora la rigenerazione
 * si vede accadere: la conferma, e l'indirizzo nel riquadro che cambia.
 *
 * ⚠️ Il copione SCRIVE (rigenera il codice dell'Autoclave): `azzera.sh` prima
 * di ogni giratura, o la guida «Dall'adesivo alla scheda» girata dopo
 * troverebbe un codice diverso da quello del seme.
 */
test('etichetta qr', async ({ page }) => {
  const s = new Scenografo(page, 'etichetta-qr', "L'etichetta QR", "L'adesivo che porta il telefono sulla scheda giusta");

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: a che cosa serve ───────────────────────────────────────────────────
  s.cartello('A che cosa serve', 'Parte 1 di 3');

  const azione = page.getByRole('link', { name: /Etichetta QR/ }).first();

  await s.movimento(
    "«Etichetta QR» sta fra le azioni della scheda, e produce l'adesivo da attaccare sulla macchina.",
    async () => {
      await azione.click();
      await page.waitForURL('**/strumenti/55/qr');
      await expect(page.getByRole('heading', { name: 'Etichetta QR' })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: azione, zoom: 1.4, coda: 1.2 },
  );

  const codice = page.locator('main svg').first();

  await s.scatto("L'etichetta porta il codice, il nome della macchina, il modello e la matricola: si riconosce anche senza telefono.", {
    su: codice,
    zoom: 1.5,
    durata: 7,
  });

  await s.scatto('Chi lo inquadra arriva su questa scheda dopo aver fatto accesso: il codice non mostra dati da solo.', {
    su: page.getByText('Indirizzo contenuto nel codice'),
    zoom: 1.6,
    durata: 7,
  });

  // ── Due: stampare e applicare ───────────────────────────────────────────────
  s.cartello('Stampare e applicare', 'Parte 2 di 3');

  await s.scatto('«Stampa» manda alla stampante la sola etichetta: tutto il resto della pagina non finisce sul foglio.', {
    su: page.getByRole('button', { name: /Stampa/ }),
    zoom: 2.2,
    durata: 7,
  });

  await s.scatto('Conviene applicarla dove si legge stando in piedi davanti alla macchina, non sul retro né sotto il piano.', {
    su: codice,
    zoom: 1.2,
    alone: false,
    durata: 6.5,
  });

  await s.scatto("Su una macchina che scalda o che si lava spesso, vale la pena proteggerla: un adesivo illeggibile è un'etichetta che non c'è.", {
    su: codice,
    zoom: 2.2,
    alone: false,
    durata: 7.5,
  });

  // ── Tre: se il codice cambia ────────────────────────────────────────────────
  s.cartello('Se il codice cambia', 'Parte 3 di 3');

  const rigenera = page.getByRole('button', { name: 'Rigenera codice' });

  await s.movimento(
    '«Rigenera codice» ne crea uno nuovo, e da quel momento il vecchio adesivo non porta più da nessuna parte.',
    async () => {
      await rigenera.click();
      await expect(page.getByText('Rigenerare il codice QR?')).toBeVisible();
      await page.waitForTimeout(900);
    },
    { su: rigenera, zoom: 1.5, coda: 1.6 },
  );

  const indirizzo = page.getByText('Indirizzo contenuto nel codice').locator('xpath=following-sibling::p[1]');
  const prima = await indirizzo.innerText();
  const conferma = page.getByRole('button', { name: 'Rigenera', exact: true });

  await s.movimento(
    "Confermato, l'indirizzo è nuovo. Si usa se un'etichetta è finita dove non doveva, non per ristamparla: per quello basta «Stampa».",
    async () => {
      await conferma.click();
      await expect(page.getByText('Rigenerare il codice QR?')).toBeHidden();
      await expect(indirizzo).not.toHaveText(prima);
      await indirizzo.scrollIntoViewIfNeeded();
      await page.waitForTimeout(900);
    },
    { su: conferma, zoom: 1.3, coda: 1.6 },
  );

  s.chiusura('Da ricordare', [
    'Il QR porta sulla scheda chi ha già accesso a Easy Lab: da solo non mostra niente a un estraneo.',
    'La stampa contiene la sola etichetta, con nome, modello e matricola leggibili a occhio.',
    'Rigenerare il codice invalida gli adesivi già applicati: si fa solo quando serve davvero.',
  ]);

  await s.scrivi();
});

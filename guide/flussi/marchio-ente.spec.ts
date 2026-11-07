import { expect, test } from '@playwright/test';
import path from 'node:path';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida B3 — «Il marchio delle email». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (9, in
 * tre capitoli da 2, 3 e 4): i testi scritti in `testi/marchio-ente.md` sono
 * numerati come i passi.
 *
 * ⚠️ Serve l'**Admin**: la pagina sta dietro `unita_organizzativa.update`.
 *
 * ⚠️ Il logo caricato è il PNG di Easy Lab, che è già nel repository: serve un
 * file vero perché l'anteprima mostri qualcosa, e non ne serve uno di scena.
 *
 * ⚠️ Il copione SCRIVE (salva colore e logo): `azzera.sh` prima di ogni giratura.
 */
test('marchio ente', async ({ page }) => {
  const s = new Scenografo(page, 'marchio-ente', 'Il marchio delle email', 'Logo e colore con cui il tuo Ente si presenta');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/laboratori');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: a che cosa serve ───────────────────────────────────────────────────
  s.cartello('A che cosa serve', 'Parte 1 di 3');

  const pulsante = page.getByRole('link', { name: 'Marchio email' });

  await s.movimento(
    "Si arriva da «Laboratori», col pulsante «Marchio email»: riguarda l'Ente, non il tuo account.",
    async () => {
      await pulsante.click();
      await page.waitForURL('**/laboratori/marchio');
      await expect(page.getByRole('heading', { name: 'Marchio email' })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: pulsante, zoom: 1.5, coda: 1.2 },
  );

  await s.panoramica(
    'Logo e colore finiscono in testa alle email che Easy Lab manda al posto tuo: il riepilogo delle scadenze, gli avvisi, gli inviti.',
    8,
  );

  // ── Due: il colore ──────────────────────────────────────────────────────────
  s.cartello('Il colore', 'Parte 2 di 3');

  const esadecimale = page.locator('input[type=text][maxlength="7"]');
  const coppia = page.locator('#colore').locator('..');

  await s.scatto('Il colore si sceglie dalla tavolozza o si scrive in esadecimale, se il tuo marchio ne ha uno preciso.', {
    su: coppia,
    zoom: 2.2,
    durata: 7,
  });

  const anteprima = page.getByRole('heading', { name: /Anteprima della testata/i }).locator('..');

  await s.movimento(
    "L'anteprima qui sotto si aggiorna mentre scegli, ed è la testata vera dell'email.",
    async () => {
      await esadecimale.click();
      await esadecimale.pressSequentially('#1f7a5c', { delay: 160 });
      await page.waitForTimeout(1200);
    },
    { su: esadecimale, zoom: 1.05, coda: 1.2, suono: 'tastiera' },
  );

  await expect(page.locator('#colore')).toHaveValue('#1f7a5c');

  await s.scatto('Lasciandolo vuoto si usa il blu di Easy Lab: è un valore predefinito, non un errore.', {
    su: page.getByText(/Lascia vuoto per usare il blu/),
    zoom: 2.2,
    durata: 7,
  });

  // ── Tre: il logo ────────────────────────────────────────────────────────────
  s.cartello('Il logo', 'Parte 3 di 3');

  const logo = page.locator('#logo');

  await s.movimento(
    'Il logo si carica dal proprio computer, in PNG o JPEG.',
    async () => {
      const scelta = page.waitForEvent('filechooser');
      await logo.click();
      await (await scelta).setFiles(path.resolve(process.cwd(), '..', 'public', 'brand', 'easylab-logo.png'));
      await expect(anteprima.locator('img')).toBeVisible({ timeout: 10_000 });
      await page.waitForTimeout(600);
    },
    { su: logo, zoom: 1.6, coda: 1 },
  );

  await s.scatto("Anche qui l'anteprima mostra il risultato prima di salvare, alla larghezza che avrà nella posta.", {
    su: anteprima,
    zoom: 1.5,
    durata: 7,
  });

  const salva = page.getByRole('button', { name: 'Salva' }).first();

  await s.movimento(
    '«Salva» rende il marchio effettivo: la prossima email esce così.',
    async () => {
      await salva.click();
      await expect(page.getByRole('button', { name: 'Rimuovi il logo' })).toBeVisible({ timeout: 10_000 });
      await page.waitForTimeout(500);
    },
    { su: salva, zoom: 1.8, coda: 1.2 },
  );

  await s.scatto('Da qui in avanti compare anche «Rimuovi il logo», per tornare al solo nome scritto.', {
    su: page.getByRole('button', { name: 'Rimuovi il logo' }),
    zoom: 2,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    "Il marchio è dell'Ente e vale per tutti: non è una preferenza personale come il tema.",
    "Si vede solo nelle email che Easy Lab manda, non dentro l'applicazione.",
    "L'anteprima mostra la testata vera: il colore va giudicato lì, non sulla tavolozza.",
  ]);

  await s.scrivi();
});

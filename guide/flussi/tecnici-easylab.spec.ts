import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M8 — «I tecnici EasyLab». Guida INTERNA. Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (6, in due
 * capitoli da 2 e 4): i testi scritti in `testi/tecnici-easylab.md` sono numerati come i passi.
 *
 * La pagina oggi si chiama «Tecnici e gestori» e il portafoglio si sceglie SEDE
 * per sede, dalla finestra «Clienti e accessi». Il copione apre una sede in più
 * a un tecnico e poi la richiude: si vede accadere ciò che prima si diceva soltanto.
 *
 * ⚠️ Il copione SCRIVE: `gira-in-fila.sh` azzera prima di ogni giratura.
 */
test('tecnici easylab', async ({ page }) => {
  const s = new Scenografo(page, 'tecnici-easylab', 'I tecnici EasyLab', 'Chi lavora su quali clienti, e che cosa comporta');

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma/tecnici');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const riga = page.locator('tbody tr', { hasText: 'Federico Esposito' });
  const apri = riga.getByRole('button', { name: 'Clienti e accessi' });
  const finestra = page.getByRole('dialog');
  const padova = finestra.getByLabel('Policlinico Universitario di Padova');
  const salva = finestra.getByRole('button', { name: 'Salva il portafoglio' });

  // ── Uno: il portafoglio ─────────────────────────────────────────────────────
  s.cartello('Il portafoglio', 'Parte 1 di 2');

  await s.scatto('«Tecnici e gestori» elenca le persone di EasyLab che lavorano sulle macchine dei clienti.', {
    su: page.getByRole('heading', { name: 'Tecnici e gestori' }),
    zoom: 1.2,
    durata: 6,
  });

  await expect(riga).toContainText('1 sede');

  await s.scatto("Il portafoglio è dichiarato, non implicito: ognuno lavora sulle sedi che gli sono state assegnate, e su nessun'altra.", {
    su: riga,
    zoom: 1.15,
    durata: 7.5,
  });

  // ── Due: non è una nota organizzativa ───────────────────────────────────────
  s.cartello('Non è una nota organizzativa', 'Parte 2 di 2');

  await s.movimento(
    '🔴 «Clienti e accessi» apre il portafoglio: spuntare una sede apre a quella persona TUTTE le macchine della sede.',
    async () => {
      await apri.click();
      await expect(padova).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: apri, zoom: 1.2, coda: 1.4 },
  );

  await s.movimento(
    'Non è un promemoria di chi segue chi: è un conferimento di accesso, e da quel momento è anche fra gli assegnatari del cliente.',
    async () => {
      await padova.check();
      await page.waitForTimeout(700);
      await salva.click();
      await expect(finestra).toHaveCount(0);
      await expect(riga).toContainText('2 sedi');
      await page.waitForTimeout(700);
    },
    { su: padova, zoom: 1.3, coda: 1.2 },
  );

  await s.movimento(
    "Togliere la spunta revoca l'accesso subito, e lascia intatto quello che il tecnico ha registrato: lo storico resta suo.",
    async () => {
      await apri.click();
      await expect(padova).toBeChecked();
      await page.waitForTimeout(600);
      await padova.uncheck();
      await page.waitForTimeout(600);
      await salva.click();
      await expect(finestra).toHaveCount(0);
      await expect(riga).toContainText('1 sede');
      await page.waitForTimeout(700);
    },
    { su: apri, zoom: 1.2, coda: 1.2 },
  );

  await s.scatto('Va rivisto quando qualcuno cambia zona o lascia: un portafoglio dimenticato è un accesso ancora aperto.', {
    su: page.locator('table').first(),
    zoom: 1.3,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    'Il portafoglio dice su quali sedi dei clienti lavora una persona di EasyLab.',
    '🔴 È un conferimento di accesso, non una nota: spuntare una sede apre tutte le sue macchine.',
    'Va rivisto quando qualcuno cambia zona o lascia.',
  ]);

  await s.scrivi();
});

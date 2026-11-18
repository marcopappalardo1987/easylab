import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi, superaIl2FA } from '../lib/accesso';
import { artisan } from '../lib/ambiente';
import { fotocameraFinta, mettiDavanti, qrDellaMacchina, qrEstraneo } from '../lib/camera';

/**
 * Guida J2 — «Dall'adesivo alla scheda». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (9, in
 * tre capitoli da 3): i testi scritti in `testi/scansione-qr.md` sono numerati
 * come i passi.
 *
 * L'inquadratura ora è VERA: lo scanner di «Campo» legge da una fotocamera
 * finta (🔗 `lib/camera.ts`) il QR che l'applicazione stampa sull'etichetta, e
 * porta alla scheda da sé. Anche il codice estraneo del terzo capitolo passa
 * dallo scanner, che lo mostra e non lo segue.
 *
 * ⚠️ L'indirizzo del QR è **firmato** e nessuna schermata lo produce: per la
 * parte senza accesso il copione se lo fa coniare da artisan, come fa la pagina
 * dell'etichetta (`App\Support\QrStrumento::url()`).
 */
const camera = fotocameraFinta('scansione-qr');
test.use({ launchOptions: { args: camera.argomenti } });

test('scansione qr', async ({ page, browser }) => {
  const s = new Scenografo(page, 'scansione-qr', "Dall'adesivo alla scheda", 'Che cosa succede quando inquadri il codice');

  const indirizzo = artisan(
    'echo \\App\\Support\\QrStrumento::url(\\App\\Models\\Strumento::withoutGlobalScopes()->find(55));',
  );
  await mettiDavanti(browser, camera.file, qrDellaMacchina(55));

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/campo');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: il codice porta qui ────────────────────────────────────────────────
  s.cartello('Il codice porta qui', 'Parte 1 di 3');

  const scansiona = page.getByRole('button', { name: /Scansiona QR/ });

  await s.movimento(
    "Inquadrando l'adesivo, il telefono apre la scheda di quella macchina: la macchina è quella, senza cercarla.",
    async () => {
      await scansiona.click();
      await page.waitForURL('**/strumenti/55', { timeout: 15_000 });
      await expect(page.getByRole('heading', { name: 'Autoclave' })).toBeVisible();
      await page.waitForTimeout(900);
    },
    { su: scansiona, zoom: 1.2, coda: 1.4 },
  );

  await s.panoramica("Non è una pagina speciale: è la stessa scheda che si apre dall'elenco, con le stesse linguette e le stesse azioni.", 7);

  await s.scatto('Quello che puoi farci dipende dal tuo ruolo, esattamente come sempre: il QR non aggiunge permessi.', {
    su: page.getByRole('button', { name: 'Forza semaforo' }).locator('..'),
    zoom: 1.6,
    alone: false,
    durata: 7,
  });

  // ── Due: se non hai fatto accesso ───────────────────────────────────────────
  s.cartello('Se non hai fatto accesso', 'Parte 2 di 3');

  await page.context().clearCookies();

  await s.movimento(
    "Il codice da solo non mostra niente: chi non è entrato in Easy Lab finisce sulla pagina di accesso.",
    async () => {
      await page.goto(indirizzo);
      await page.waitForURL('**/login');
      await expect(page.locator('#email')).toBeVisible();
      await page.waitForTimeout(700);
    },
    { coda: 1.4, durata: 6 },
  );

  const accedere = page.getByRole('button', { name: /accedi/i });

  await s.movimento(
    "Fatto l'accesso si arriva sulla macchina che si era inquadrata, senza dover ricominciare.",
    async () => {
      await page.locator('#email').click();
      await page.locator('#email').pressSequentially('maria.conti@aurora.test', { delay: 45 });
      await page.fill('#password', 'guida-demo');
      await accedere.click();
      await superaIl2FA(page);
      await page.waitForURL('**/strumenti/55', { timeout: 15_000 });
      await expect(page.getByRole('heading', { name: 'Autoclave' })).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: page.locator('#email'), zoom: 1.2, coda: 1.4, suono: 'tastiera' },
  );

  await s.scatto("Un adesivo fotografato da un estraneo, quindi, non è un problema: la scheda la vede solo chi entra, e col ruolo giusto.", {
    su: page.getByRole('heading', { name: 'Autoclave' }),
    zoom: 1.7,
    durata: 7,
  });

  // ── Tre: quando un codice non funziona ──────────────────────────────────────
  s.cartello('Quando un codice non funziona', 'Parte 3 di 3');

  await page.goto('/strumenti/55/qr');
  await page.waitForLoadState('networkidle');

  await s.scatto("Se l'etichetta è stata rigenerata, il vecchio adesivo non porta più a niente: si stampa quello nuovo.", {
    su: page.getByRole('button', { name: 'Rigenera codice' }),
    zoom: 2.2,
    durata: 7,
  });

  // Un QR che non è di Easy Lab: quello del costruttore, che rimanda al suo sito.
  await mettiDavanti(browser, camera.file, qrEstraneo('https://www.costruttore.example/manuali/steri-200'));
  await page.goto('/campo');
  await page.waitForLoadState('networkidle');

  await s.movimento(
    "Se il codice è di qualcun altro, come l'etichetta del costruttore, la pagina lo dice e si ferma.",
    async () => {
      await page.getByRole('button', { name: /Scansiona QR/ }).click();
      await expect(page.getByText('Questo codice non porta a Easy Lab.')).toBeVisible({ timeout: 15_000 });
      await expect(page).toHaveURL(/\/campo$/);
      await page.waitForTimeout(1200);
    },
    { su: page.getByRole('button', { name: /Scansiona QR/ }), zoom: 1.2, coda: 1.6 },
  );

  await page.goto('/strumenti/55/qr');
  await page.waitForLoadState('networkidle');

  await s.scatto("Il collegamento non scade nel tempo: un'etichetta vale quanto la macchina su cui è attaccata.", {
    su: page.getByText(/Il collegamento è firmato e non scade/),
    zoom: 1.8,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    "Il QR porta alla scheda della macchina, che è la stessa che si apre dall'elenco.",
    'Senza accesso non mostra niente: prima si entra, poi si arriva alla macchina inquadrata.',
    'Il codice non scade, ma rigenerarlo invalida gli adesivi già stampati.',
  ]);

  await s.scrivi();
});

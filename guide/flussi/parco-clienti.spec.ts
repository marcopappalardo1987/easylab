import { expect, test, type Page } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M2 — «Il parco di tutti i clienti». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Guida INTERNA, girata col Superadmin di piattaforma. Stessi otto passi del
 * copione precedente, in tre capitoli da 3, 2 e 3: `testi/parco-clienti.md` è
 * numerato come i passi.
 *
 * Il filtro «Clienti» non sceglie un cliente solo: restringe a un piano o ai
 * preferiti. Il copione mostra il piano. La finestra «Impersona» si apre e si
 * chiude senza entrare: l'ingresso è la guida «Impersonare un cliente».
 */
/**
 * ⚠️ Lo screencast di Chrome smette di mandare fotogrammi a metà di una
 * transizione (la finestra che sfuma, la tabella che si ridisegna): la clip
 * finirebbe sullo stato di prima. Un ritocco invisibile al body forza un
 * fotogramma dello stato finale.
 */
const ridipingi = async (page: Page): Promise<void> => {
  await page.waitForTimeout(350);
  await page.evaluate(() => {
    const b = document.body;
    b.style.outline = b.style.outline ? '' : '1px solid transparent';
  });
  await page.waitForTimeout(250);
};

test('parco clienti', async ({ page }) => {
  const s = new Scenografo(page, 'parco-clienti', 'Il parco di tutti i clienti', 'Leggere la strumentazione di tutti, in sola lettura');

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma/parco');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: una vista sola su tutti ────────────────────────────────────────────
  s.cartello('Una vista sola su tutti', 'Parte 1 di 3');

  await s.scatto("Il parco mette insieme gli strumenti di tutti i clienti: è l'unica vista che attraversa i confini fra Enti.", {
    su: page.getByRole('heading', { name: 'Strumenti di tutti i clienti' }),
    zoom: 1.7,
    durata: 6.5,
  });

  await s.panoramica("Serve al supporto: quando un cliente telefona, la macchina si trova senza chiedergli di dettarne la matricola.", 7);

  await s.scatto('Ogni riga porta il cliente e la sede a cui appartiene, oltre a stato, macchina, matricola e prossima scadenza.', {
    su: page.locator('tbody tr').first(),
    zoom: 1.4,
    durata: 7,
  });

  // ── Due: filtrare ───────────────────────────────────────────────────────────
  s.cartello('Filtrare', 'Parte 2 di 3');

  const modo = page.locator('#modo');

  await s.movimento(
    'Il perimetro si stringe a un piano o ai propri preferiti; accanto, i filtri per stato e per obsolescenza.',
    async () => {
      await modo.selectOption('piano');
      await expect(page.locator('#piano')).toBeVisible();
      await page.waitForTimeout(600);
      await page.locator('#piano').selectOption('free');
      await expect(page.getByRole('heading', { name: /sul piano Comodato d'uso/ })).toBeVisible();
      await page.waitForTimeout(700);
      await ridipingi(page);
    },
    { su: modo, zoom: 1.3, coda: 1 },
  );

  const cerca = page.locator('#search');

  await s.movimento(
    'La ricerca guarda nome, modello e matricola: al telefono, la matricola trova una macchina sola.',
    async () => {
      await cerca.click();
      await cerca.pressSequentially('AUT-974700', { delay: 90 });
      await expect(page.locator('tbody tr')).toHaveCount(1);
      await page.waitForTimeout(600);
      await ridipingi(page);
    },
    { su: cerca, zoom: 1.3, coda: 1, suono: 'tastiera' },
  );

  // ── Tre: sola lettura ───────────────────────────────────────────────────────
  s.cartello('Sola lettura, e perché', 'Parte 3 di 3');

  const riga = page.locator('tbody tr').first();

  await s.scatto("Da qui si legge soltanto: sulla riga l'unica azione è «Impersona», e non è una dimenticanza.", {
    su: riga,
    zoom: 1.4,
    durata: 6.5,
  });

  const impersona = riga.getByRole('button', { name: /Impersona/ });

  await s.movimento(
    "Per scrivere si impersona una persona del cliente: l'ingresso resta registrato, col nome di chi c'era davvero dietro.",
    async () => {
      await impersona.click();
      await expect(page.getByText(/Impersona un membro di Gruppo Sanitario Demo/)).toBeVisible();
      await page.waitForTimeout(900);
      await ridipingi(page);
    },
    { su: impersona, zoom: 1.2, coda: 1.4 },
  );

  await page.keyboard.press('Escape');
  const titolo = page.getByText(/Impersona un membro di/);
  if (await titolo.isVisible()) await page.locator('[role=dialog]:visible button').first().click();
  await expect(titolo).toBeHidden();

  await s.scatto('Serve a tenere distinte due cose: guardare per aiutare, e agire al posto di qualcuno.', {
    su: page.getByText(/Sola lettura: per intervenire si entra come una persona del cliente/),
    zoom: 1.8,
    durata: 6,
  });

  s.chiusura('Da ricordare', [
    "È l'unica vista che attraversa i clienti, e serve al supporto.",
    'È in sola lettura per scelta: per scrivere si impersona, e resta traccia.',
    'Il perimetro si stringe per piano o ai preferiti; la matricola trova la macchina.',
  ]);

  await s.scrivi();
});

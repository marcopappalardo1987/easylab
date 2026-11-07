import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida I2 — «Chi vede che cosa». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (9, in
 * tre capitoli da 3): i testi scritti in `testi/chi-vede-cosa.md` sono numerati
 * come i passi.
 *
 * ⚠️ Il terzo ruolo è il **Tenant** e non il Tecnico: l'unico Tecnico del seme
 * è l'utente invitato di `primo-accesso`, che nel seme la password non ce l'ha.
 *
 * ⚠️ Non c'è una pagina dei ruoli da mostrare a un cliente: la matrice dei
 * permessi è di piattaforma. La guida mostra la **stessa applicazione** vista da
 * tre ruoli diversi.
 *
 * ⚠️ Oggi Responsabile e Tenant hanno lo STESSO menù: la differenza fra loro sta
 * nel perimetro (tre laboratori contro la sede intera) e nelle azioni sulle
 * schede, ed è quella che si mostra. La versione precedente diceva che il Tenant
 * aveva il menù più corto, e non era più vero.
 *
 * ⚠️ AGM-234200 è una macchina di Radiologia, che Giulia Ferrari non segue: la
 * sua ricerca deve tornare vuota.
 */
test('chi vede cosa', async ({ page }) => {
  const s = new Scenografo(page, 'chi-vede-cosa', 'Chi vede che cosa', 'La stessa applicazione, vista da tre ruoli diversi');

  await accedi(page, 'maria.conti@aurora.test');
  await s.avvia();

  // ── Uno: l'Amministratore ───────────────────────────────────────────────────
  s.cartello("L'Amministratore", 'Parte 1 di 3');

  await s.scatto("Il ruolo non è una qualifica: è l'insieme delle cose che una persona può vedere e fare.", {
    su: page.getByRole('button', { name: /Maria Conti/ }),
    zoom: 1.9,
    durata: 8,
  });

  await s.scatto("L'Amministratore ha una voce in più, «Persone»: è chi decide chi entra nell'Ente e con quale ruolo.", {
    su: page.getByRole('navigation', { name: 'Menù principale' }),
    zoom: 1,
    durata: 8,
  });

  await page.goto('/strumenti/1700');
  await page.waitForLoadState('networkidle');

  await s.scatto('Sulle schede vede tutte le azioni: modificare, spostare, forzare il semaforo, eliminare.', {
    su: page.getByRole('button', { name: 'Forza semaforo' }).locator('..'),
    zoom: 1.8,
    durata: 8,
  });

  // ── Due: il Responsabile ────────────────────────────────────────────────────
  s.cartello('Il Responsabile di reparto', 'Parte 2 di 3');

  await accedi(page, 'giulia.ferrari@aurora.test');

  const laboratori = page.getByRole('navigation', { name: 'Menù principale' }).getByRole('link', { name: 'Laboratori', exact: true });

  await s.movimento(
    'Il Responsabile vede solo i laboratori che gli sono assegnati: non è un menù più corto, è un parco più piccolo.',
    async () => {
      await laboratori.click();
      await page.waitForURL('**/laboratori');
      await expect(page.getByRole('button', { name: /Ematologia/ })).toBeVisible();
      await expect(page.getByRole('button', { name: /Radiologia/ })).toHaveCount(0);
      await page.waitForTimeout(700);
    },
    { su: laboratori, zoom: 1.2, coda: 1.4 },
  );

  await page.goto('/strumenti');
  await page.waitForLoadState('networkidle');

  const cerca = page.getByPlaceholder(/Cerca per nome/);

  await s.movimento(
    'Le macchine degli altri laboratori non compaiono nei suoi elenchi, e non le trova nemmeno cercandone la matricola.',
    async () => {
      await cerca.click();
      await cerca.pressSequentially('AGM-234200', { delay: 120 });
      await expect(page.locator('tbody tr a')).toHaveCount(0, { timeout: 5000 });
      await page.waitForTimeout(900);
    },
    { su: cerca, zoom: 1.3, coda: 1.4, suono: 'tastiera' },
  );

  await page.goto('/strumenti');
  await page.waitForLoadState('networkidle');
  await page.locator('tbody tr a').first().click();
  await page.waitForURL('**/strumenti/*');
  await page.waitForLoadState('networkidle');
  await expect(page.getByRole('button', { name: 'Forza semaforo' })).toBeVisible();

  await s.scatto('Sulle sue macchine invece lavora per intero: interventi, scadenze, ricambi, documenti, spostamenti.', {
    su: page.getByRole('button', { name: 'Forza semaforo' }).locator('..'),
    zoom: 1.8,
    durata: 8,
  });

  // ── Tre: il referente del cliente ───────────────────────────────────────────
  s.cartello('Il referente del cliente', 'Parte 3 di 3');

  await accedi(page, 'paolo.greco@aurora.test');

  await s.movimento(
    'Il referente del cliente ha lo stesso menù, ma vede tutti i laboratori della sede: il parco intero.',
    async () => {
      await laboratori.click();
      await page.waitForURL('**/laboratori');
      await expect(page.getByRole('button', { name: /Radiologia/ })).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: laboratori, zoom: 1.2, coda: 1.4 },
  );

  await page.goto('/strumenti/1700');
  await page.waitForLoadState('networkidle');
  await expect(page.getByRole('heading', { name: 'Agitatore magnetico', level: 1 })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Forza semaforo' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Modifica' })).toHaveCount(0);

  await s.scatto('Sulle schede però resta solo lo storico in PDF, da leggere: guarda il parco, non lo amministra.', {
    su: page
      .getByRole('heading', { name: 'Agitatore magnetico', level: 1 })
      .locator('xpath=ancestor::div[.//a[contains(., "Storico PDF")]][1]'),
    zoom: 1.6,
    durata: 8,
  });

  await s.scatto("Se ti aspetti un pulsante e non c'è, la risposta è quasi sempre il ruolo scritto sotto il tuo nome, in alto a destra.", {
    su: page.getByRole('button', { name: /Paolo Greco/ }),
    zoom: 2,
    durata: 8,
  });

  s.chiusura('Da ricordare', [
    'Due colleghi con ruoli diversi vedono due Easy Lab diversi: è voluto, non un difetto.',
    'Il Responsabile è limitato ai laboratori assegnati: le altre macchine non esistono per lui.',
    "Quando manca un pulsante che ti aspettavi, la spiegazione è il ruolo: si chiede a chi amministra l'Ente.",
  ]);

  await s.scrivi();
});

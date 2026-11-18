import { expect, test, type Page } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M4 — «I ricambi su tutto il parco». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Guida INTERNA, girata col Superadmin di piattaforma. Stessi sei passi del
 * copione precedente, in due capitoli da 3: `testi/parco-ricambi.md` è numerato
 * come i passi.
 *
 * La pagina è il CATALOGO dei pezzi di ogni sede, non il conteggio dei
 * montaggi: la colonna «Presso» dice quanti clienti hanno lo stesso pezzo. I
 * testi che parlavano di pezzi montati e di modelli sono stati riscritti.
 */
const ridipingi = async (page: Page): Promise<void> => {
  // ⚠️ Lo screencast si ferma a metà di una transizione: senza un ritocco
  // invisibile la clip finisce sullo stato di prima.
  await page.waitForTimeout(350);
  await page.evaluate(() => {
    const b = document.body;
    b.style.outline = b.style.outline ? '' : '1px solid transparent';
  });
  await page.waitForTimeout(250);
};

test('parco ricambi', async ({ page }) => {
  const s = new Scenografo(page, 'parco-ricambi', 'I ricambi su tutto il parco', 'Quali pezzi usano i clienti, e dove');

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma/parco/ricambi');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: i pezzi di tutti ───────────────────────────────────────────────────
  s.cartello('I pezzi di tutti', 'Parte 1 di 2');

  await s.scatto('È il catalogo dei ricambi esteso a tutti i clienti: ogni pezzo che una sede ha a catalogo, col cliente a cui appartiene.', {
    su: page.getByRole('heading', { name: 'Ricambi di tutti i clienti' }),
    zoom: 1.7,
    durata: 7,
  });

  await s.scatto('La differenza con la vista del cliente è la scala: «Presso» conta quanti clienti hanno lo stesso pezzo.', {
    su: page.locator('tbody tr').first(),
    zoom: 1.4,
    durata: 6.5,
  });

  await s.panoramica("È l'informazione con cui si tratta con un fornitore: un pezzo usato da molti clienti è un volume, non una stima.", 7);

  // ── Due: a che cosa serve davvero ───────────────────────────────────────────
  s.cartello('A che cosa serve davvero', 'Parte 2 di 2');

  const modo = page.locator('#parco-modo');

  await s.movimento(
    'Il perimetro si stringe a un piano o ai propri preferiti, per guardare una parte del parco alla volta.',
    async () => {
      await modo.selectOption('piano');
      await expect(page.locator('#parco-piano')).toBeVisible();
      await page.waitForTimeout(600);
      await page.locator('#parco-piano').selectOption('saas');
      await page.waitForTimeout(800);
      await expect(page.locator('tbody tr', { hasText: 'Gruppo Sanitario Demo' })).toHaveCount(0);
      await ridipingi(page);
    },
    { su: modo, zoom: 1.3, coda: 1 },
  );

  // Torna a tutti i clienti fuori campo: la ricerca del lotto guarda tutto il parco.
  await modo.selectOption('tutti');
  await page.waitForTimeout(800);

  const cerca = page.locator('#parco-cerca');

  await s.movimento(
    'Il giorno di un lotto difettoso si cerca il pezzo per nome o codice: restano i clienti e le sedi che lo hanno a catalogo.',
    async () => {
      await cerca.click();
      await cerca.pressSequentially('Filtro HEPA', { delay: 90 });
      await page.waitForTimeout(900);
      await expect(page.locator('tbody tr').first()).toContainText('Filtro HEPA');
      await expect(page.locator('tbody tr', { hasText: 'Cinghia' })).toHaveCount(0);
      await ridipingi(page);
    },
    { su: cerca, zoom: 1.3, coda: 1, suono: 'tastiera' },
  );

  await s.scatto('Anche qui si legge soltanto: correggere un pezzo è un gesto del cliente, e per farlo si impersona.', {
    su: page.locator('tbody tr').first().getByRole('button', { name: /Impersona/ }),
    zoom: 1.8,
    durata: 6.5,
  });

  s.chiusura('Da ricordare', [
    'È il catalogo dei ricambi alla scala della piattaforma, con il cliente su ogni riga.',
    '«Presso» dice quanti clienti usano lo stesso pezzo: è il volume da portare a un fornitore.',
    'Il giorno di un lotto difettoso, la ricerca dice quali clienti hanno quel pezzo.',
  ]);

  await s.scrivi();
});

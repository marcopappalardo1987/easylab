import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';
import { artisan } from '../lib/ambiente';
import { fotocameraFinta, mettiDavanti, qrDellaMacchina, qrEstraneo } from '../lib/camera';

/**
 * Guida J1 — «Campo: lavorare col telefono». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (8, in
 * tre capitoli da 2, 3 e 3): i testi scritti in `testi/vista-campo.md` sono
 * numerati come i passi.
 *
 * ⚠️ Girata alla larghezza dello studio, non su un telefono finto: la pagina è
 * responsiva e il copione ne ritrae il contenuto, non il formato. Lo
 * `Scenografo` non ha una finestra da telefono, e inventarla qui vorrebbe dire
 * provare il montaggio su proporzioni che nessuna guida ha mai usato.
 *
 * Lo scanner è VERO: legge da una fotocamera finta (🔗 `lib/camera.ts`) il QR
 * che l'applicazione stampa sull'etichetta.
 *
 * ⚠️ Nel seme Giulia non ha interventi assegnati, e «I miei interventi» era
 * vuoto mentre la frase lo raccontava pieno. Il copione gliene assegna quattro,
 * sulle macchine che lei vede: SCRIVE, quindi `azzera.sh` prima di girare.
 */
const camera = fotocameraFinta('vista-campo');
test.use({ launchOptions: { args: camera.argomenti } });

test('vista campo', async ({ page, browser }) => {
  const s = new Scenografo(page, 'vista-campo', 'Campo: lavorare col telefono', 'Il QR sulla macchina e i propri interventi, in reparto');

  await accedi(page, 'giulia.ferrari@aurora.test');

  // Le macchine che Giulia vede davvero: le legge l'elenco, non una query che
  // potrebbe non sapere dei suoi laboratori.
  await page.goto('/strumenti');
  const ids = await page
    .locator('tbody tr a[href*="/strumenti/"]')
    .evaluateAll((a) => [...new Set(a.map((x) => Number((x as HTMLAnchorElement).pathname.split('/')[2])))].slice(0, 12));
  expect(ids.length).toBeGreaterThan(0);

  const assegnati = artisan(
    `echo \\App\\Models\\Intervento::withoutGlobalScopes()->whereIn("strumento_id",[${ids.join(',')}])` +
      '->where("stato","non_fatto")->orderBy("data_scadenza")->limit(4)->get()' +
      '->each(fn($i) => $i->forceFill(["tecnico_id" => \\App\\Models\\User::where("email","giulia.ferrari@aurora.test")->value("id")])->save())->count();',
  );
  expect(Number(assegnati)).toBeGreaterThan(0);

  await page.goto('/campo');
  await page.waitForLoadState('networkidle');
  const righe = page.locator('a[wire\\:key^="int-"]');
  await expect(righe.first()).toBeVisible();
  const primaMacchina = Number((await righe.first().getAttribute('href'))!.split('/').pop());
  await mettiDavanti(browser, camera.file, qrDellaMacchina(primaMacchina));
  await s.avvia();

  // ── Uno: a che cosa serve ───────────────────────────────────────────────────
  s.cartello('A che cosa serve', 'Parte 1 di 3');

  await s.scatto('«Campo» è la pagina pensata per il telefono, da usare stando davanti alla macchina invece che alla scrivania.', {
    su: page.getByRole('heading', { name: 'Campo', level: 1 }),
    zoom: 1.7,
    durata: 7,
  });

  await s.panoramica('Fa due cose sole, ed è il punto: inquadrare un QR, oppure aprire i propri interventi.', 6.5);

  // ── Due: inquadrare il QR ───────────────────────────────────────────────────
  s.cartello('Inquadrare il QR', 'Parte 2 di 3');

  const scansiona = page.getByRole('button', { name: /Scansiona QR/ });

  await s.scatto("Il primo pulsante accende la fotocamera e legge l'adesivo attaccato sulla macchina.", {
    su: scansiona,
    zoom: 1.8,
    durata: 6.5,
  });

  await s.movimento(
    'Letto il codice, si apre la scheda di quella macchina: senza cercarla per nome, senza matricole da digitare.',
    async () => {
      await scansiona.click();
      await page.waitForURL(`**/strumenti/${primaMacchina}`, { timeout: 15_000 });
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(900);
    },
    { su: scansiona, zoom: 1.2, coda: 1.4 },
  );

  await mettiDavanti(browser, camera.file, qrEstraneo('https://www.costruttore.example/manuali/steri-200'));
  await page.goto('/campo');
  await page.waitForLoadState('networkidle');

  await s.movimento(
    'Se il codice non è di Easy Lab, la pagina lo dice invece di provarci: non porta da nessuna parte per sbaglio.',
    async () => {
      await page.getByRole('button', { name: /Scansiona QR/ }).click();
      await expect(page.getByText('Questo codice non porta a Easy Lab.')).toBeVisible({ timeout: 15_000 });
      await expect(page).toHaveURL(/\/campo$/);
      await page.waitForTimeout(1200);
    },
    { su: page.getByRole('button', { name: /Scansiona QR/ }), zoom: 1.2, coda: 1.6 },
  );

  // ── Tre: i miei interventi ──────────────────────────────────────────────────
  s.cartello('I miei interventi', 'Parte 3 di 3');

  await s.scatto('Sotto ci sono i tuoi interventi aperti, quelli assegnati a te: è la stessa lista dello Scadenzario con «Solo i miei».', {
    su: page.getByRole('heading', { name: /I miei interventi/i }),
    zoom: 1.5,
    alone: false,
    durata: 7.5,
  });

  const prima = righe.first();
  const nomeMacchina = (await prima.locator('p').first().innerText()).trim();

  await s.movimento(
    'Ogni riga porta alla macchina, e da lì si segna il lavoro come fatto mentre lo si sta facendo.',
    async () => {
      await prima.click();
      await page.waitForURL(`**/strumenti/${primaMacchina}`);
      await expect(page.getByRole('heading', { name: nomeMacchina }).first()).toBeVisible();
      await page.getByRole('button', { name: /^Interventi/ }).first().click();
      await expect(page.getByRole('button', { name: 'Fatto' }).first()).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: prima, zoom: 1.3, coda: 1.4 },
  );

  await s.scatto("È la differenza fra annotare in reparto e annotare a fine giornata: la seconda è quella in cui si dimentica qualcosa.", {
    su: page.getByRole('button', { name: 'Fatto' }).first(),
    zoom: 2.2,
    durata: 7.5,
  });

  s.chiusura('Da ricordare', [
    'Campo fa due cose: legge il QR di una macchina e mostra i tuoi interventi aperti.',
    'Serve a lavorare stando davanti alla macchina, col telefono in mano.',
    'Annotare mentre si lavora è il modo per non perdere pezzi a fine giornata.',
  ]);

  await s.scrivi();
});

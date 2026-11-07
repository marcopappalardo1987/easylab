import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C3 — «Registrare una macchina nuova». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Due passi in più del copione precedente (12 → 14): il referente dello
 * strumento (ADR-054) e la garanzia scritta alla nascita (ADR-059) sono campi
 * nuovi del modulo, e la guida che registra una macchina deve mostrarli.
 * `testi/nuovo-strumento.md` è rinumerato di conseguenza.
 *
 * ⚠️ Si parte da «Laboratori» e non dall'elenco: lo strumento nasce dentro un
 * laboratorio, e il modulo prende l'ubicazione dal nodo in cui ti trovi.
 *
 * ⚠️ Il «Salva» va preso DENTRO il form dello strumento: la pagina monta anche
 * la modale del nodo, che ha un «Salva» suo.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
// ⚠️ Senza `--lang` Chromium disegna i campi data all'americana (11/07/2026 per
// il 7 novembre): `locale` del config non basta. Gli altri argomenti ripetono
// quelli del config, che `launchOptions` qui sostituisce per intero.
test.use({ launchOptions: { args: ['--force-color-profile=srgb', '--font-render-hinting=none', '--lang=it-IT'] } });

test('nuovo strumento', async ({ page }) => {
  const s = new Scenografo(page, 'nuovo-strumento', 'Registrare una macchina nuova', 'Nasce dentro il laboratorio in cui si trova');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/laboratori');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: il posto giusto ────────────────────────────────────────────────────
  s.cartello('Si parte dal posto giusto', 'Parte 1 di 3');

  const genetica = page.getByText('Genetica Medica').first();

  await s.movimento(
    "Una macchina si registra da «Laboratori», non dall'elenco: si scende nel laboratorio in cui si trova.",
    async () => {
      await genetica.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(500);
      await page.getByText('Camera Bianca').first().click();
      await expect(page.getByRole('navigation', { name: 'Percorso' })).toContainText('Camera Bianca');
      await page.waitForTimeout(500);
    },
    { su: genetica, zoom: 1.3, coda: 1 },
  );

  await s.scatto('Il percorso in alto è la conferma: quello che stai per creare nascerà qui dentro.', {
    su: page.getByRole('navigation', { name: 'Percorso' }),
    zoom: 2.3,
    durata: 6,
  });

  const aggiungi = page.getByRole('button', { name: /Aggiungi strumento/ }).first();
  const nome = page.locator('#strumentoForm\\.nome');

  await s.movimento(
    '«Aggiungi strumento» sta sopra l\'elenco delle macchine già presenti, accanto al loro numero.',
    async () => {
      await aggiungi.click();
      await expect(nome).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: aggiungi, zoom: 1.8, coda: 1 },
  );

  // ── Due: i campi ────────────────────────────────────────────────────────────
  s.cartello('I campi che contano', 'Parte 2 di 3');

  const scrivi = async (campo: string, testo: string, ritardo = 80) => {
    const el = page.locator(campo);
    await el.click();
    await el.pressSequentially(testo, { delay: ritardo });
    await page.waitForTimeout(400);
  };

  await s.movimento(
    'Il nome è quello con cui la macchina viene chiamata in reparto: è il primo campo su cui si cerca.',
    () => scrivi('#strumentoForm\\.nome', 'Microscopio confocale'),
    { su: nome, zoom: 1.8, coda: 0.8, suono: 'tastiera' },
  );

  await s.movimento(
    'Il modello è quello del costruttore, e serve anche alla vista «Per modello».',
    () => scrivi('#strumentoForm\\.modello', 'Zeiss LSM 900'),
    { su: page.locator('#strumentoForm\\.modello'), zoom: 1.8, coda: 0.8, suono: 'tastiera' },
  );

  await s.movimento(
    "La matricola identifica questa unità e nessun'altra: è quella incisa sulla targhetta.",
    () => scrivi('#strumentoForm\\.matricola', 'MIC-004512'),
    { su: page.locator('#strumentoForm\\.matricola'), zoom: 1.8, coda: 0.8, suono: 'tastiera' },
  );

  const installazione = page.locator('#strumentoForm\\.data_installazione');

  await s.movimento(
    "La data di installazione è da quando la macchina è in servizio: da lì si conta l'obsolescenza.",
    async () => {
      await installazione.click();
      await page.waitForTimeout(300);
      await installazione.fill('2026-06-15');
      await page.waitForTimeout(700);
    },
    { su: installazione, zoom: 1.8, coda: 0.8 },
  );

  const selettore = page.locator('[data-selettore-fornitore="strumento"]');

  await s.movimento(
    'Il fornitore è obbligatorio: si apre la tendina, si scrive il nome e si sceglie. È a chi telefonare al guasto.',
    async () => {
      await selettore.click();
      const cerca = page.locator('#fornitore-strumento-cerca');
      await expect(cerca).toBeVisible();
      await cerca.pressSequentially('VWR', { delay: 120 });
      const voce = page.getByRole('option').getByRole('button', { name: 'VWR International' });
      await expect(voce).toBeVisible();
      await page.waitForTimeout(500);
      await voce.click();
      await expect(selettore).toContainText('VWR International');
      await page.waitForTimeout(500);
    },
    { su: selettore, zoom: 1.6, coda: 1 },
  );

  const referente = page.locator('[data-referente-strumento]');

  await s.movimento(
    'Il referente è facoltativo, ma con la sua email le comunicazioni sulla macchina arrivano anche a lui.',
    async () => {
      await scrivi('#strumentoForm\\.referente_nome', 'Paolo', 70);
      await scrivi('#strumentoForm\\.referente_cognome', 'Ricci', 70);
      await scrivi('#strumentoForm\\.referente_email', 'paolo.ricci@aurora.test', 45);
    },
    { su: referente, zoom: 1.5, coda: 0.8, suono: 'tastiera' },
  );

  const garanzia = page.locator('[data-garanzia-nuova]');
  await garanzia.scrollIntoViewIfNeeded();

  await s.movimento(
    'Anche la garanzia si scrive qui. Basta la durata in mesi: senza inizio vale la data di installazione.',
    () => scrivi('#garanziaNuova\\.durata_mesi', '24', 200),
    { su: garanzia, zoom: 1.6, coda: 1, suono: 'tastiera' },
  );

  const salva = page.locator('form:has(#strumentoForm\\.nome)').getByRole('button', { name: 'Salva' });

  await s.movimento(
    'Si salva: la finestra si chiude, e gli strumenti del laboratorio passano da 42 a 43.',
    async () => {
      await salva.click();
      // La modale che resta aperta significa validazione fallita.
      await expect(nome).toHaveCount(0, { timeout: 10_000 });
      await expect(page.getByText('Microscopio confocale').first()).toBeVisible();
      await expect(page.getByText('Strumenti (43)')).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: salva, zoom: 1.8, coda: 1.2 },
  );

  // ── Tre: e adesso ───────────────────────────────────────────────────────────
  s.cartello('E adesso', 'Parte 3 di 3');

  const nuova = page.getByText('Microscopio confocale').first();

  await s.scatto("La macchina nuova è nell'elenco del laboratorio, fra le altre in ordine alfabetico.", {
    su: nuova,
    zoom: 1.6,
    durata: 6,
  });

  await s.movimento(
    "Nella scheda nasce verde, perché non ha ancora scadenze. La garanzia di 24 mesi c'è già, contata dall'installazione.",
    async () => {
      await nuova.click();
      await page.waitForURL('**/strumenti/**');
      await expect(page.getByText('Microscopio confocale').first()).toBeVisible();
      await expect(page.getByText('Scade il 15/06/2028')).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: nuova, zoom: 1.4, coda: 1.4 },
  );

  await s.scatto(
    'Il primo gesto utile è pianificare la prima manutenzione da «Interventi»: senza scadenze non comparirà mai nello Scadenzario.',
    {
      su: page.locator('[data-tab="interventi"]'),
      zoom: 2.2,
      durata: 7.5,
    },
  );

  s.chiusura('Da ricordare', [
    'Una macchina nasce dentro un laboratorio: si naviga prima nel posto giusto, poi si aggiunge.',
    "Nome, modello e matricola sono i campi della ricerca; l'email del referente fa arrivare a lui gli avvisi.",
    'Appena creata è verde perché non ha scadenze: la prima manutenzione va pianificata subito, o resterà invisibile.',
  ]);

  await s.scrivi();
});

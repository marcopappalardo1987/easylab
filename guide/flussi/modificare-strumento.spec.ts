import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C4 — «Correggere i dati di una macchina». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stesso numero di passi del copione precedente (10), ma la terza parte è
 * rimontata: il passo 7 è il referente (ADR-054), nuovo nel modulo, e il
 * «+ Aggiungi» dei parametri ora si vede funzionare (ADR-059). Il salvataggio
 * e il registro sono un passo solo, e la linguetta «Anagrafica» chiude, perché
 * è lì che i parametri compaiono dopo il salvataggio. `testi/modificare-strumento.md`
 * segue lo stesso ordine.
 *
 * ⚠️ Il «Salva» va preso dentro il form dello strumento: la scheda monta anche
 * altre modali con un «Salva» proprio.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
// ⚠️ Senza `--lang` Chromium disegna i campi data all'americana (11/07/2026 per
// il 7 novembre): `locale` del config non basta. Gli altri argomenti ripetono
// quelli del config, che `launchOptions` qui sostituisce per intero.
test.use({ launchOptions: { args: ['--force-color-profile=srgb', '--font-render-hinting=none', '--lang=it-IT'] } });

test('modificare strumento', async ({ page }) => {
  const s = new Scenografo(page, 'modificare-strumento', 'Correggere i dati di una macchina', 'Che cosa è, e a chi si telefona quando si guasta');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: che cosa si modifica ───────────────────────────────────────────────
  s.cartello('Che cosa si modifica', 'Parte 1 di 3');

  const modifica = page.getByRole('button', { name: 'Modifica' }).first();
  const nome = page.locator('#strumentoForm\\.nome');

  await s.movimento(
    '«Modifica» cambia che cosa è la macchina: nome, modello, matricola, data, fornitore, referente, parametri.',
    async () => {
      await modifica.click();
      await expect(nome).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: modifica, zoom: 1.8, coda: 1 },
  );

  await s.scatto("Non cambia dove sta, per quello c'è «Sposta», e non cambia il suo stato, che resta calcolato dalle scadenze.", {
    su: page.getByRole('heading', { name: 'Modifica strumento' }),
    zoom: 1.2,
    alone: false,
    durata: 7,
  });

  // ── Due: i campi ────────────────────────────────────────────────────────────
  s.cartello('I campi', 'Parte 2 di 3');

  await s.scatto('Nome, modello e matricola sono i tre campi su cui lavora la ricerca: correggerli qui li corregge ovunque.', {
    su: nome,
    zoom: 1.8,
    durata: 7,
  });

  const matricola = page.locator('#strumentoForm\\.matricola');

  await s.movimento(
    'La matricola si corregge quando la targhetta dice altro: succede spesso sulle macchine ereditate.',
    async () => {
      await matricola.click();
      await matricola.fill('');
      await page.waitForTimeout(300);
      await matricola.pressSequentially('AUT-770814-B', { delay: 90 });
      await page.waitForTimeout(400);
    },
    { su: matricola, zoom: 1.8, coda: 0.8, suono: 'tastiera' },
  );

  await s.scatto("La data di installazione governa l'obsolescenza: sbagliarla di due anni sposta la macchina dentro o fuori dagli obsoleti.", {
    su: page.locator('#strumentoForm\\.data_installazione'),
    zoom: 1.8,
    durata: 7.5,
  });

  const selettore = page.locator('[data-selettore-fornitore="strumento"]');

  await s.movimento(
    "Il fornitore è a chi telefonare quando si guasta: si sceglie scrivendone il nome, e si aggiorna quando cambia l'assistenza.",
    async () => {
      await selettore.click();
      const cerca = page.locator('#fornitore-strumento-cerca');
      await expect(cerca).toBeVisible();
      await cerca.pressSequentially('Binder', { delay: 110 });
      const voce = page.getByRole('option').getByRole('button', { name: 'Binder Italia' });
      await expect(voce).toBeVisible();
      await page.waitForTimeout(500);
      await voce.click();
      await expect(selettore).toContainText('Binder Italia');
      await page.waitForTimeout(500);
    },
    { su: selettore, zoom: 1.6, coda: 1 },
  );

  const referente = page.locator('[data-referente-strumento]');

  await s.movimento(
    'Il referente è la persona del laboratorio a cui la macchina fa capo: con la sua email, gli avvisi arrivano anche a lui.',
    async () => {
      for (const [campo, testo, ritardo] of [
        ['#strumentoForm\\.referente_nome', 'Anna', 70],
        ['#strumentoForm\\.referente_cognome', 'Galli', 70],
        ['#strumentoForm\\.referente_email', 'anna.galli@aurora.test', 45],
      ] as const) {
        await page.locator(campo).click();
        await page.locator(campo).fill('');
        await page.locator(campo).pressSequentially(testo, { delay: ritardo });
        await page.waitForTimeout(300);
      }
    },
    { su: referente, zoom: 1.5, coda: 0.8, suono: 'tastiera' },
  );

  // ── Tre: i parametri tecnici ────────────────────────────────────────────────
  s.cartello('I parametri tecnici', 'Parte 3 di 3');

  const aggiungi = page.getByRole('button', { name: '+ Aggiungi' });
  await aggiungi.scrollIntoViewIfNeeded();

  await s.movimento(
    '«+ Aggiungi» apre una riga libera: volume, potenza, temperatura, o quello che serve a quella macchina.',
    async () => {
      // ⚠️ La riga nuova arriva dopo il giro di Livewire: senza aspettare che il
      // conteggio salga, `.last()` pesca l'ultima riga VECCHIA e ci scrive sopra.
      const righe = await page.getByPlaceholder('Chiave').count();
      await aggiungi.click();
      await expect(page.getByPlaceholder('Chiave')).toHaveCount(righe + 1);
      const chiave = page.getByPlaceholder('Chiave').last();
      await expect(chiave).toHaveValue('');
      await chiave.click();
      await chiave.pressSequentially('Volume della camera', { delay: 70 });
      const valore = page.getByPlaceholder('Valore').last();
      await valore.click();
      await valore.pressSequentially('80 L', { delay: 110 });
      await page.waitForTimeout(500);
    },
    { su: aggiungi, zoom: 1.6, coda: 1, suono: 'tastiera' },
  );

  const salva = page.locator('form:has(#strumentoForm\\.nome)').getByRole('button', { name: 'Salva' });

  await s.movimento(
    'Si salva. La modifica è immediata, e resta nel registro: si sa sempre chi ha cambiato che cosa.',
    async () => {
      await salva.click();
      // La modale che resta aperta significa validazione fallita.
      await expect(nome).toHaveCount(0, { timeout: 10_000 });
      await page.waitForTimeout(600);
    },
    { su: salva, zoom: 1.8, coda: 1.2 },
  );

  const linguetta = page.locator('[data-tab="anagrafica"]');

  await s.movimento(
    'Matricola, referente e parametri compaiono nella linguetta «Anagrafica»: i dati di targa, senza aprire il manuale.',
    async () => {
      await linguetta.click();
      await expect(page.getByText('AUT-770814-B').first()).toBeVisible();
      await expect(page.getByText('anna.galli@aurora.test')).toBeVisible();
      await expect(page.getByText('80 L', { exact: true })).toBeVisible();
      // Una chiave che c'è già sovrascrive in silenzio quella vecchia: si
      // aggiunge una voce nuova, e le tre del seme devono restare.
      await expect(page.getByText('400 V')).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: linguetta, zoom: 1.2, coda: 1.6 },
  );

  s.chiusura('Da ricordare', [
    '«Modifica» cambia che cosa è la macchina; «Sposta» cambia dove sta. Lo stato non si tocca da qui.',
    'Nome, modello e matricola sono i campi della ricerca: se sono sbagliati, la macchina non si trova.',
    "La data di installazione decide l'obsolescenza, quindi vale la pena metterla giusta anche a distanza di anni.",
  ]);

  await s.scrivi();
});

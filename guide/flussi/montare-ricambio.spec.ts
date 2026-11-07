import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida F1 — «Annotare un ricambio montato». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Il ricambio si registra DENTRO l'intervento che lo ha montato: non esiste
 * un «aggiungi ricambio» a sé, ed è la cosa che la guida deve far capire.
 *
 * ⚠️ Un passo in più del copione precedente (12 invece di 11, il nono): il
 * fornitore del pezzo, scelto da una tendina in cui si scrive (ADR-051). Il
 * copione vecchio inoltre non salvava mai, e la terza parte raccontava un
 * pezzo che non esisteva: qui si salva davvero, e lo si ritrova.
 *
 * ⚠️ Il campo del nome è una `x-ui.combobox`, che porta l'`id` ma NON il
 * `name`: si punta con `#ricambiNuovi.0.nome`, con le barre rovesciate.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('montare ricambio', async ({ page }) => {
  const s = new Scenografo(page, 'montare-ricambio', 'Annotare un ricambio montato', "Il pezzo si registra dentro l'intervento che lo ha montato");

  await accedi(page, 'giulia.ferrari@aurora.test');

  await page.goto('/ricambi');
  await page.locator('#search').pressSequentially('Guarnizione di tenuta', { delay: 20 });
  const conteggio = page.getByText(/\d+ macchine/).first();
  await expect(conteggio).toBeVisible();
  const macchinePrima = Number(((await conteggio.textContent()) ?? '0').replace(/\D/g, ''));

  await page.goto('/scadenzario');
  await page.waitForLoadState('networkidle');
  const indirizzo = await page.locator('tbody tr a').first().getAttribute('href');
  await page.goto(indirizzo!);
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: dove si annota ─────────────────────────────────────────────────────
  s.cartello('Dove si annota', 'Parte 1 di 3');

  const interventi = page.getByRole('button', { name: /^Interventi/ }).first();
  await s.movimento(
    "Un ricambio non si aggiunge da solo: si annota dentro l'intervento in cui è stato montato.",
    async () => {
      await interventi.click();
      await expect(page.getByText('+ Nuovo intervento').first()).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: interventi, zoom: 1.5, coda: 1, durata: 6.5 },
  );

  const nuovo = page.getByText('+ Nuovo intervento').first();
  await s.movimento(
    'Si parte quindi da un intervento, nuovo o esistente.',
    async () => {
      await nuovo.click();
      await expect(page.locator('#interventoForm\\.descrizione')).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: nuovo, zoom: 1.6, coda: 1 },
  );

  // Il resto dell'intervento non è l'argomento: si compila fuori campo.
  await page.locator('#interventoForm\\.descrizione').fill('Sostituita la guarnizione della porta');
  await page.locator('#interventoTipo').selectOption('manutenzione_straordinaria');
  await page.locator('#interventoTecnico').selectOption({ index: 1 });
  await page.getByLabel('Già eseguito').check();
  await page.waitForTimeout(500);

  // ── Due: la spunta e il pezzo ───────────────────────────────────────────────
  s.cartello('La spunta e il pezzo', 'Parte 2 di 3');

  const spunta = page.getByText('Ricambio effettuato');
  await s.scatto("Nel modulo dell'intervento c'è «Ricambio effettuato»: finché è spenta, il pezzo non esiste.", {
    su: spunta,
    zoom: 1.8,
    durata: 6.5,
  });

  const aggiungi = page.getByRole('button', { name: /Aggiungi ricambio/ });
  const nome = page.locator('#ricambiNuovi\\.0\\.nome');
  await s.movimento(
    'Accendendola compare la sezione dei ricambi, con «+ Aggiungi ricambio».',
    async () => {
      await spunta.click();
      await expect(aggiungi).toBeVisible();
      await page.waitForTimeout(500);
      await aggiungi.click();
      await expect(nome).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: spunta, zoom: 1.4, coda: 1, durata: 6 },
  );

  const rigaPezzo = page.locator('div').filter({ has: nome }).filter({ has: page.locator('[data-selettore-fornitore="ricambio.0"]') }).last();
  await s.scatto('Ogni riga è un pezzo: il nome, la sua garanzia e il fornitore da cui è stato comprato.', {
    su: rigaPezzo,
    zoom: 1.5,
    durata: 6.5,
  });

  const suggerimento = page.locator('[id="ricambiNuovi.0.nome-lista"]').getByText('Guarnizione di tenuta');
  await s.movimento(
    'Il nome si scrive per esteso, come lo si direbbe a voce: sarà la voce del catalogo dei ricambi.',
    async () => {
      await nome.click();
      await nome.pressSequentially('Guarniz', { delay: 130 });
      await expect(suggerimento).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: nome, zoom: 1.5, coda: 1, suono: 'tastiera', durata: 7 },
  );

  await s.movimento(
    'Mentre scrivi, Easy Lab propone i nomi già usati: sceglierne uno evita di creare una seconda grafia dello stesso pezzo.',
    async () => {
      await suggerimento.click();
      await expect(nome).toHaveValue('Guarnizione di tenuta');
      await page.waitForTimeout(600);
    },
    { su: suggerimento, zoom: 1.5, coda: 1, durata: 7.5 },
  );

  const garanzia = page.locator('#ricambiNuovi\\.0\\.scadenza_garanzia');
  await s.movimento(
    "La scadenza di garanzia del pezzo è facoltativa, ma se c'è conta per il semaforo come quella della macchina.",
    async () => {
      await garanzia.fill('2028-11-07');
      await page.waitForTimeout(700);
    },
    { su: garanzia, zoom: 1.6, coda: 0.8, durata: 7 },
  );

  const fornitore = page.locator('[data-selettore-fornitore="ricambio.0"]');
  await s.movimento(
    'Il fornitore del pezzo si sceglie da una tendina: si scrive una parte del nome e si prende quello giusto.',
    async () => {
      await fornitore.click();
      const cerca = page.locator('#fornitore-ricambio-0-cerca');
      await expect(cerca).toBeVisible();
      await cerca.pressSequentially('Binder', { delay: 120 });
      const voce = page.getByRole('option').getByRole('button', { name: 'Binder Italia' });
      await expect(voce).toBeVisible();
      await page.waitForTimeout(500);
      await voce.click();
      await expect(fornitore).toContainText('Binder Italia');
      await page.waitForTimeout(500);
    },
    { su: fornitore, zoom: 1.3, coda: 1, durata: 7 },
  );

  // ── Tre: dove si ritrova ────────────────────────────────────────────────────
  s.cartello('Dove si ritrova', 'Parte 3 di 3');

  const salva = page.getByRole('button', { name: 'Salva' });
  const linguettaRicambi = page.getByRole('button', { name: /^Ricambi/ }).first();
  await s.movimento(
    "Salvato l'intervento, il pezzo compare nella linguetta «Ricambi» della macchina.",
    async () => {
      await salva.click();
      await expect(nome).toBeHidden({ timeout: 10_000 });
      await page.waitForTimeout(600);
      await linguettaRicambi.click();
      await expect(page.locator('tbody tr').filter({ visible: true }).filter({ hasText: 'Guarnizione di tenuta' })).toContainText('Binder Italia');
      await page.waitForTimeout(700);
    },
    { su: salva, zoom: 1.2, coda: 1.2, durata: 7 },
  );

  const menuRicambi = page.getByRole('link', { name: 'Ricambi', exact: true });
  await s.movimento(
    'E nel catalogo dei ricambi, che raccoglie tutti i montaggi di quel pezzo su tutte le macchine.',
    async () => {
      await menuRicambi.click();
      await page.waitForURL('**/ricambi');
      await page.locator('#search').pressSequentially('Guarnizione di tenuta', { delay: 60 });
      await page.waitForTimeout(1200);
      await expect(page.getByText(`${macchinePrima + 1} macchine`).first()).toBeVisible();
    },
    { su: menuRicambi, zoom: 1.2, coda: 1.2, durata: 7 },
  );

  await s.scatto('Da lì si risponde alla domanda pratica: quante guarnizioni sono montate, su quali macchine e in quali laboratori.', {
    su: page.getByText(`${macchinePrima + 1} macchine`).first(),
    zoom: 1.3,
    durata: 7.5,
  });

  s.chiusura('Da ricordare', [
    "Il ricambio si annota dentro l'intervento che lo ha montato: è quello che lega il pezzo alla data e alla persona.",
    'Il nome diventa una voce del catalogo: conviene accettare il suggerimento invece di inventare una grafia nuova.',
    'La garanzia del pezzo, se la metti, accende il semaforo della macchina come quella della macchina stessa.',
  ]);

  await s.scrivi();
});

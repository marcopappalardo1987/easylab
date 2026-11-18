import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';

/**
 * Guida 01 — «Registrare un intervento su uno strumento».
 * Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (18, in
 * tre capitoli da 4, 5 e 9): i testi scritti in `testi/intervento.md` sono
 * numerati come i passi.
 *
 * ⚠️ Il copione SCRIVE (registra un intervento): `azzera.sh` prima di ogni giratura.
 */
test('registrare un intervento', async ({ page }) => {
  const s = new Scenografo(
    page,
    'intervento',
    'Registrare un intervento',
    'Dalla ricerca dello strumento alla manutenzione annotata',
  );

  await page.goto('/login');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: entrare ────────────────────────────────────────────────────────────
  s.cartello('Entrare e orientarsi', 'Parte 1 di 3');

  await s.movimento(
    'Si entra con le proprie credenziali di lavoro.',
    async () => {
      await page.locator('#email').click();
      await page.locator('#email').pressSequentially('giulia.ferrari@aurora.test', { delay: 45 });
      await page.locator('#password').click();
      await page.locator('#password').pressSequentially('guida-demo', { delay: 70 });
      await page.waitForTimeout(300);
    },
    { su: page.locator('#email'), zoom: 1.5, coda: 0.6, suono: 'tastiera' },
  );

  const accedi = page.getByRole('button', { name: /accedi/i });
  await s.movimento(
    'Un clic su «Accedi» e siamo dentro.',
    async () => {
      await accedi.click();
      await page.waitForURL('**/dashboard');
      await page.waitForLoadState('networkidle');
    },
    { su: accedi, zoom: 1.5, coda: 1 },
  );

  await s.panoramica(
    'La Dashboard apre sullo stato del parco: quante macchine sono a posto e quante chiedono attenzione.',
    6,
  );

  const menuStrumenti = page.getByRole('link', { name: 'Strumenti', exact: true });
  await s.movimento(
    "Il menù «Strumenti» porta all'elenco completo.",
    async () => {
      await menuStrumenti.click();
      await page.waitForURL('**/strumenti');
      await expect(page.locator('tbody tr').first()).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: menuStrumenti, zoom: 1.5, coda: 1 },
  );

  // ── Due: trovare la macchina ────────────────────────────────────────────────
  s.cartello('Trovare la macchina giusta', 'Parte 2 di 3');

  await s.scatto('Ogni riga porta il proprio semaforo: verde a posto, giallo in scadenza, rosso fermo.', {
    su: page.locator('tbody tr').first(),
    zoom: 1.5,
    durata: 6,
  });

  const ricerca = page.getByPlaceholder(/Cerca per nome/i);
  await s.scatto('La ricerca lavora su nome, modello e matricola insieme.', {
    su: ricerca,
    zoom: 2,
    durata: 4.5,
  });

  await s.movimento(
    "Basta digitare: l'elenco si restringe mentre si scrive.",
    async () => {
      await ricerca.click();
      await ricerca.pressSequentially('Autoclave', { delay: 110 });
      await page.waitForTimeout(1200);
      await expect(page.locator('tbody tr').first()).toContainText(/Autoclave/i);
    },
    { su: ricerca, zoom: 1.2, coda: 1, suono: 'tastiera' },
  );

  const riga = page.locator('tbody tr td:nth-child(2) a').first();
  const nomeStrumento = (await riga.textContent())?.trim() ?? '';

  await s.movimento(
    `Si apre la scheda di «${nomeStrumento}».`,
    async () => {
      await riga.click();
      await page.waitForURL(/\/strumenti\/\d+/);
      await page.waitForLoadState('networkidle');
    },
    { su: riga, zoom: 1.5, coda: 1 },
  );

  await s.panoramica(
    'La scheda raccoglie tutto quel che si sa della macchina: dove sta, da quando, e la prossima scadenza.',
    6.5,
  );

  // ── Tre: annotare ───────────────────────────────────────────────────────────
  s.cartello('Annotare che cosa è stato fatto', 'Parte 3 di 3');

  const linguetta = page.getByRole('button', { name: /^Interventi/ }).first();
  await s.movimento(
    'La linguetta «Interventi» apre lo storico della manutenzione.',
    async () => {
      await linguetta.click();
      await expect(page.getByText('+ Nuovo intervento').first()).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: linguetta, zoom: 1.6, coda: 1 },
  );

  await s.scatto('Qui restano gli interventi fatti e quelli in programma, con data, tipo e chi li esegue.', {
    su: page.locator('table').filter({ has: page.getByRole('columnheader', { name: /Tecnico/i }) }),
    zoom: 1.3,
    durata: 5.5,
  });

  const nuovo = page.getByText('+ Nuovo intervento').first();
  await s.movimento(
    'Per annotarne uno nuovo: «+ Nuovo intervento».',
    async () => {
      await nuovo.click();
      await expect(page.locator('#interventoForm\\.descrizione')).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: nuovo, zoom: 1.6, coda: 1 },
  );

  const descrizione = page.locator('#interventoForm\\.descrizione');
  await s.movimento(
    'Si descrive che cosa è stato fatto.',
    async () => {
      await descrizione.click();
      await descrizione.pressSequentially('Sostituita la guarnizione della porta, tenuta verificata a freddo', { delay: 55 });
      await page.waitForTimeout(300);
    },
    { su: descrizione, zoom: 1.6, coda: 0.7, suono: 'tastiera' },
  );

  const tipo = page.locator('#interventoTipo');
  await s.movimento(
    'Il tipo distingue ordinaria, straordinaria, taratura e full risk.',
    async () => {
      await tipo.selectOption('manutenzione_ordinaria');
      await page.waitForTimeout(600);
    },
    { su: tipo, zoom: 1.8, coda: 0.8 },
  );

  const scadenza = page.locator('#interventoForm\\.data_scadenza');
  await s.movimento(
    'La data di scadenza è quella che accenderà il semaforo quando si avvicina.',
    async () => {
      await scadenza.fill('2027-01-20');
      await page.waitForTimeout(600);
    },
    { su: scadenza, zoom: 1.8, coda: 0.8 },
  );

  const tecnico = page.locator('#interventoTecnico');
  await s.movimento(
    "L'assegnatario è obbligatorio: l'intervento è sempre di qualcuno.",
    async () => {
      await tecnico.selectOption({ index: 1 });
      await page.waitForTimeout(600);
    },
    { su: tecnico, zoom: 1.8, coda: 0.8 },
  );

  const salva = page.getByRole('button', { name: 'Salva' });
  await s.movimento(
    'Si salva.',
    async () => {
      await salva.click();
      // La finestra che resta aperta significa validazione fallita.
      await expect(salva).toBeHidden({ timeout: 10_000 });
      await expect(page.getByRole('cell', { name: /Sostituita la guarnizione/ }).first()).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: salva, zoom: 1.8, coda: 1.2 },
  );

  const panoramica = page.getByRole('button', { name: 'Panoramica' });
  await s.movimento(
    "L'intervento è nello storico, e in «Panoramica» la prossima scadenza della macchina è già quella nuova.",
    async () => {
      await panoramica.click();
      await expect(page.getByText('20/01/2027').first()).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: panoramica, zoom: 1.3, coda: 1.4, durata: 6.5 },
  );

  s.chiusura('In tre mosse', [
    'Si cerca la macchina per nome, modello o matricola: la ricerca guarda i tre campi insieme.',
    'La scheda raccoglie stato, ubicazione, garanzie e storico in un posto solo.',
    'Ogni intervento vuole un assegnatario e una data di scadenza: è la data che accende il semaforo.',
  ]);

  await s.scrivi();
});

import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida D2 — «Segnare un intervento come fatto». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (10, in
 * tre capitoli da 3, 4 e 3): i testi scritti in `testi/completare-intervento.md`
 * sono numerati come i passi.
 *
 * Si chiude una TARATURA scaduta, non la prima riga qualunque: è l'unico tipo
 * che nel modulo mostra la pianificazione della prossima, e il passo 6 la
 * racconta. Il numerino della linguetta (ADR-055) si vede prima e dopo.
 *
 * ⚠️ Il copione SCRIVE (chiude un intervento e ne pianifica il successivo):
 * `azzera.sh` prima di ogni giratura.
 */
test('completare intervento', async ({ page }) => {
  const s = new Scenografo(page, 'completare-intervento', 'Segnare un intervento come fatto', 'Chiudere il giro, e pianificare il prossimo');

  await accedi(page, 'giulia.ferrari@aurora.test');

  // ⚠️ Si arriva dallo Scadenzario e non da un id fisso: un Responsabile vede
  // solo i laboratori che gli sono assegnati. Si legge l'indirizzo e si va con
  // `goto`: dopo `wire:navigate` le linguette restano inerti per un istante.
  await page.goto('/scadenzario');
  await page.waitForLoadState('networkidle');
  const rigaScadenzario = page.locator('tbody tr', { hasText: 'Taratura' }).first();
  const descrizione = ((await rigaScadenzario.locator('td').nth(5).textContent()) ?? '').trim();
  const indirizzo = await rigaScadenzario.locator('a').first().getAttribute('href');
  await page.goto(indirizzo!);
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: dove si chiude ─────────────────────────────────────────────────────
  s.cartello('Dove si chiude', 'Parte 1 di 3');

  const linguetta = page.getByRole('button', { name: /^Interventi/ }).first();
  await expect(linguetta).toContainText(/\d/);

  await s.movimento(
    'Si chiude dalla linguetta «Interventi» della macchina; il numerino accanto conta le scadenze ancora da gestire.',
    async () => {
      await linguetta.click();
      await expect(page.getByText('+ Nuovo intervento').first()).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: linguetta, zoom: 1.6, coda: 1, durata: 7 },
  );

  const riga = page.locator('tbody tr', { hasText: descrizione }).filter({ hasText: 'Scaduto' }).first();

  await s.scatto("Le righe ancora aperte portano «Scaduto» o «Pianificato»: sono quelle su cui c'è qualcosa da fare.", {
    su: riga,
    zoom: 1.5,
    durata: 7,
  });

  const fatto = riga.getByRole('button', { name: 'Fatto', exact: true });
  await s.movimento(
    '«Fatto» è il gesto che chiude: non cancella la riga, la completa.',
    async () => {
      await fatto.click();
      await expect(page.locator('#dataEsecuzione')).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: fatto, zoom: 1.8, coda: 1 },
  );

  // ── Due: che cosa chiede ────────────────────────────────────────────────────
  s.cartello('Che cosa chiede', 'Parte 2 di 3');

  const data = page.locator('#dataEsecuzione');
  await s.movimento(
    'La data di esecuzione è quando il lavoro è stato fatto davvero, non quando lo stai registrando.',
    async () => {
      await data.fill('2026-11-05');
      await page.waitForTimeout(700);
    },
    { su: data, zoom: 1.8, coda: 0.8, durata: 7 },
  );

  const report = page.locator('#reportFineLavoro');
  await s.movimento(
    'Il report di fine lavoro è il posto in cui scrivere che cosa si è trovato: lo leggerà chi tornerà su questa macchina.',
    async () => {
      await report.click();
      await report.pressSequentially('Calibrazione verificata con masse campione, scarti entro tolleranza. Certificato allegato.', { delay: 35 });
      await page.waitForTimeout(400);
    },
    { su: report, zoom: 1.6, coda: 0.8, suono: 'tastiera' },
  );

  const pianifica = page.getByLabel('Pianifica la prossima taratura');
  const mesi = page.locator('#mesiProssimaTaratura');
  await s.movimento(
    'Su una taratura compare anche la pianificazione della prossima: si dice fra quanti mesi, e la scadenza nasce da sola.',
    async () => {
      if (!(await pianifica.isChecked())) await pianifica.check();
      await expect(mesi).toBeVisible();
      await mesi.click();
      await mesi.pressSequentially('12', { delay: 200 });
      await page.waitForTimeout(500);
    },
    { su: pianifica, zoom: 1.6, coda: 0.9, durata: 7.5 },
  );

  const conferma = page.locator('form:has(#dataEsecuzione)').getByRole('button', { name: 'Conferma' });
  await s.movimento(
    'Si conferma.',
    async () => {
      await conferma.click();
      await expect(page.locator('#dataEsecuzione')).toBeHidden({ timeout: 10_000 });
      await page.waitForTimeout(600);
    },
    { su: conferma, zoom: 1.8, coda: 1.2 },
  );

  // ── Tre: che cosa cambia ────────────────────────────────────────────────────
  s.cartello('Che cosa cambia', 'Parte 3 di 3');

  const chiusa = page.locator('tbody tr', { hasText: descrizione }).filter({ hasText: 'Eseguito il 05/11/2026' });
  await expect(chiusa).toHaveCount(1);

  await s.scatto('La riga è «Fatto», con la data di esecuzione accanto a quella di scadenza: restano scritte tutte e due.', {
    su: chiusa,
    zoom: 1.5,
    durata: 7.5,
  });

  await expect(linguetta).not.toContainText(/\d/);
  await expect(page.getByText('Strumentazione idonea').first()).toBeVisible();

  await s.scatto("Era l'unica scadenza aperta: il numerino accanto a «Interventi» sparisce e il semaforo torna verde, da solo.", {
    su: linguetta,
    zoom: 1.3,
    durata: 7.5,
  });

  await s.scatto("«Riapri» esiste per gli errori: se hai chiuso la riga sbagliata, si torna indietro senza perdere niente.", {
    su: chiusa.getByRole('button', { name: 'Riapri' }),
    zoom: 2,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    'Segnare «Fatto» completa la riga, non la cancella: data di scadenza e data di esecuzione restano entrambe.',
    'La data è quella del lavoro vero, e il report di fine lavoro è quello che leggerà il collega dopo di te.',
    'Chiudere una scadenza ricalcola il semaforo: è il gesto che riporta la macchina al verde.',
  ]);

  await s.scrivi();
});

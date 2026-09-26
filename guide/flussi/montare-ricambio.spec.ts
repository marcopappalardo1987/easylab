import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida F1 — «Annotare un ricambio montato».
 *
 * ⚠️ Il ricambio si registra DENTRO l'intervento che lo ha montato: non esiste
 * un «aggiungi ricambio» a sé, ed è la cosa che la guida deve far capire.
 *
 * ⚠️ Il campo del nome è una `x-ui.combobox`, che porta l'`id` ma NON il
 * `name`: si punta con `#ricambiNuovi.0.nome`, e il selettore vuole le barre
 * rovesciate perché il punto in CSS è un'altra cosa.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('montare ricambio', async ({ page }) => {
  const g = new Regista(page, 'montare-ricambio', 'Annotare un ricambio montato', "Il pezzo si registra dentro l'intervento che lo ha montato");

  await accedi(page, 'giulia.ferrari@aurora.test');

  g.capitolo('Parte 1 di 3', 'Dove si annota');

  await page.goto('/scadenzario');
  await page.waitForLoadState('networkidle');
  const indirizzo = await page.locator('tbody tr a').first().getAttribute('href');
  await page.goto(indirizzo!);
  await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: 'Interventi' }).first().click();
  await page.waitForTimeout(600);

  await g.passo("Un ricambio non si aggiunge da solo: si annota dentro l'intervento in cui è stato montato.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('Si parte quindi da un intervento, nuovo o esistente.', {
    su: page.getByRole('button', { name: /Nuovo intervento/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  await page.getByRole('button', { name: /Nuovo intervento/ }).first().click();
  await page.waitForTimeout(900);

  g.capitolo('Parte 2 di 3', 'La spunta e il pezzo');

  await g.passo("Nel modulo dell'intervento c'è «Ricambio effettuato»: finché è spenta, il pezzo non esiste.", {
    su: page.getByText('Ricambio effettuato'),
    zoom: 2.1,
    durata: 8,
  });

  await page.getByText('Ricambio effettuato').click();
  await page.waitForTimeout(800);

  await g.passo('Accendendola compare la sezione dei ricambi, con «+ Aggiungi ricambio».', {
    su: page.getByRole('button', { name: /Aggiungi ricambio/ }).first(),
    zoom: 2.1,
    durata: 8,
  });

  // ⚠️ La riga non nasce con la spunta: va aggiunta. La prima stesura cercava
  // il campo del nome subito dopo aver acceso «Ricambio effettuato», e non
  // c'era ancora.
  await page.getByRole('button', { name: /Aggiungi ricambio/ }).first().click();
  await page.waitForTimeout(700);

  await g.passo('Ogni riga è un pezzo: il nome e, se serve, la sua garanzia.', {
    zoom: 1,
    durata: 7,
  });

  await page.locator('#ricambiNuovi\\.0\\.nome').fill('Guarnizione di tenuta');
  await page.waitForTimeout(700);

  await g.passo("Il nome si scrive per esteso, come lo si direbbe a voce: sarà la voce del catalogo dei ricambi.", {
    su: page.locator('#ricambiNuovi\\.0\\.nome'),
    zoom: 2,
    durata: 8,
  });

  await g.passo("Mentre scrivi, Easy Lab propone i nomi già usati: sceglierne uno evita di creare una seconda grafia dello stesso pezzo.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('La scadenza di garanzia del pezzo è facoltativa, ma se c\'è conta per il semaforo come quella della macchina.', {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Dove si ritrova');

  await g.passo("Salvato l'intervento, il pezzo compare nella linguetta «Ricambi» della macchina.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("E nel catalogo dei ricambi, che raccoglie tutti i montaggi di quel pezzo su tutte le macchine.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Da lì si risponde alla domanda pratica: quante guarnizioni monta questo laboratorio in un anno, e su quali macchine.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "Il ricambio si annota dentro l'intervento che lo ha montato: è quello che lega il pezzo alla data e alla persona.",
    'Il nome diventa una voce del catalogo: conviene accettare il suggerimento invece di inventare una grafia nuova.',
    'La garanzia del pezzo, se la metti, accende il semaforo della macchina come quella della macchina stessa.',
  ]);

  g.scrivi();
});

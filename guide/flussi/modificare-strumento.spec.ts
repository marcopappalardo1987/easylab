import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C4 — «Correggere i dati di una macchina».
 *
 * ⚠️ Il «Salva» va preso dentro il form dello strumento: la scheda monta anche
 * altre modali con un «Salva» proprio.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('modificare strumento', async ({ page }) => {
  const g = new Regista(page, 'modificare-strumento', 'Correggere i dati di una macchina', 'Che cosa è, e a chi si telefona quando si guasta');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Che cosa si modifica');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo("«Modifica» cambia che cosa è la macchina: nome, modello, matricola, data, fornitore, parametri.", {
    su: page.getByRole('button', { name: 'Modifica' }).first(),
    click: true,
    zoom: 2.1,
  });

  await page.waitForLoadState('networkidle');

  await g.passo("Non cambia dove sta — per quello c'è «Sposta» — e non cambia il suo stato, che resta calcolato dalle scadenze.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'I campi');

  await g.passo('Nome, modello e matricola sono i tre campi su cui lavora la ricerca: correggerli qui li corregge ovunque.', {
    su: page.locator('#strumentoForm\\.nome'),
    zoom: 2,
    durata: 8,
  });

  await page.fill('#strumentoForm\\.matricola', 'AUT-770814-B');

  await g.passo('La matricola si corregge quando la targhetta dice altro: succede spesso sulle macchine ereditate.', {
    su: page.locator('#strumentoForm\\.matricola'),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("La data di installazione governa l'obsolescenza: sbagliarla di due anni sposta la macchina dentro o fuori dagli obsoleti.", {
    su: page.locator('#strumentoForm\\.data_installazione'),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo('Il fornitore è a chi telefonare quando si guasta, e va tenuto aggiornato quando cambia il contratto di assistenza.', {
    su: page.locator('#strumentoForm\\.fornitore_id'),
    zoom: 2,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'I parametri tecnici');

  await g.passo("I parametri tecnici sono liberi: alimentazione, potenza, temperatura, o quello che serve a quella macchina.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Compaiono nella linguetta Anagrafica, ed è il posto in cui cercare i dati di targa senza aprire il manuale.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('Si salva.', {
    su: page.locator('form:has(#strumentoForm\\.nome)').getByRole('button', { name: 'Salva' }),
    click: true,
    zoom: 2.3,
  });

  await page.waitForLoadState('networkidle');

  await g.passo("La modifica è immediata, e resta nel registro: si sa sempre chi ha cambiato che cosa.", {
    su: page.getByRole('heading', { name: 'Autoclave' }),
    zoom: 1.9,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    '«Modifica» cambia che cosa è la macchina; «Sposta» cambia dove sta. Lo stato non si tocca da qui.',
    'Nome, modello e matricola sono i campi della ricerca: se sono sbagliati, la macchina non si trova.',
    "La data di installazione decide l'obsolescenza, quindi vale la pena metterla giusta anche a distanza di anni.",
  ]);

  g.scrivi();
});

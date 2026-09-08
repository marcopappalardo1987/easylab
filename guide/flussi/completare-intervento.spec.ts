import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida D2 — «Segnare un intervento come fatto».
 *
 * ⚠️ Il copione SCRIVE (chiude un intervento e ne pianifica il successivo):
 * `azzera.sh` prima di ogni giratura.
 */
test('completare intervento', async ({ page }) => {
  const g = new Regista(page, 'completare-intervento', 'Segnare un intervento come fatto', 'Chiudere il giro, e pianificare il prossimo');

  await accedi(page, 'giulia.ferrari@aurora.test');

  g.capitolo('Parte 1 di 3', 'Dove si chiude');

  // ⚠️ Si arriva dallo Scadenzario e non da un id fisso: un Responsabile vede
  // solo i reparti che gli sono assegnati, e uno strumento scelto a mano
  // potrebbe non essere fra i suoi.
  await page.goto('/scadenzario');
  await page.waitForLoadState('networkidle');

  // ⚠️ Si legge l'indirizzo e si va con `goto` invece di cliccare: il link
  // porta `wire:navigate`, e dopo quella navigazione Alpine si rimonta — le
  // linguette della scheda restano inerti per qualche istante, quindi il click
  // sulla linguetta scattava prima e il contenuto restava nascosto.
  const indirizzo = await page.locator('tbody tr a').first().getAttribute('href');
  await page.goto(indirizzo!);
  await page.waitForLoadState('networkidle');

  await page.getByRole('button', { name: 'Interventi' }).first().click();
  await page.waitForTimeout(600);

  await g.passo("Un intervento si chiude dalla linguetta «Interventi» della macchina, dove sta il suo storico.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo("Le righe ancora aperte portano «Scaduto» o «Pianificato»: sono quelle su cui c'è qualcosa da fare.", {
    su: page.locator('tbody tr').first(),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("«Fatto» è il gesto che chiude: non cancella la riga, la completa.", {
    su: page.getByRole('button', { name: 'Fatto' }).first(),
    click: true,
    zoom: 2.2,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 2 di 3', 'Che cosa chiede');

  await g.passo("La data di esecuzione è quando il lavoro è stato fatto davvero, non quando lo stai registrando.", {
    su: page.locator('#dataEsecuzione'),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("Il report di fine lavoro è il posto in cui scrivere che cosa si è trovato: lo leggerà chi tornerà su questa macchina.", {
    su: page.locator('#reportFineLavoro'),
    zoom: 1.9,
    durata: 8,
  });

  await page.locator('#reportFineLavoro').fill('Sostituita guarnizione della camera e verificata la tenuta a freddo. Ciclo di prova a 134 °C completato senza allarmi.');
  await page.waitForTimeout(400);

  await g.passo("Su una taratura compare anche la pianificazione della prossima: si dice fra quanti mesi, e la scadenza nasce da sola.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('Si conferma.', {
    su: page.locator('form:has(#dataEsecuzione)').getByRole('button', { name: /Conferma|Salva|Segna/ }).first(),
    click: true,
    zoom: 2.2,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 3 di 3', 'Che cosa cambia');

  await g.passo("La riga è «Fatto», con la data di esecuzione accanto a quella di scadenza: restano scritte tutte e due.", {
    su: page.locator('tbody tr').first(),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("Il semaforo della macchina si ricalcola da solo: se quella era l'unica scadenza aperta, torna verde.", {
    su: page.locator('h1').first(),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("«Riapri» esiste per gli errori: se hai chiuso la riga sbagliata, si torna indietro senza perdere niente.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Segnare «Fatto» completa la riga, non la cancella: data di scadenza e data di esecuzione restano entrambe.',
    'La data è quella del lavoro vero, e il report di fine lavoro è quello che leggerà il collega dopo di te.',
    'Chiudere una scadenza ricalcola il semaforo: è il gesto che riporta la macchina al verde.',
  ]);

  g.scrivi();
});

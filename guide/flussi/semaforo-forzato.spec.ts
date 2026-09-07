import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C6 — «Forzare il semaforo».
 *
 * ⚠️ Lo stato NON si punta con `getByText('Non idoneo')`: quel testo è anche
 * un `<option>` della tendina appena chiusa, che Livewire stacca dal DOM mentre
 * Playwright ci sta puntando. Si punta il titolo, che resta.
 *
 * ⚠️ La motivazione si punta col suo `name`, non con `textarea` generico: la
 * scheda monta in pagina anche le textarea delle altre modali (descrizione
 * intervento, report di fine lavoro), e `.first()` scriveva in quella sbagliata
 * — la forzatura falliva la validazione e lo stato non cambiava.
 *
 * ⚠️ Il copione SCRIVE (forza lo stato dello strumento 55 e lascia la riga di
 * audit): `azzera.sh` prima di ogni giratura.
 */
test('semaforo forzato', async ({ page }) => {
  const g = new Regista(page, 'semaforo-forzato', 'Forzare il semaforo', "Quando lo stato calcolato non dice la verità");

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Perché esiste');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo('Il semaforo di solito lo calcola Easy Lab dalle scadenze aperte: è una conseguenza, non una scelta.', {
    su: page.getByText('Azione richiesta').first(),
    zoom: 2.3,
    durata: 8,
  });

  await g.passo("Ma una macchina può essere ferma per ragioni che nessuna scadenza descrive: un pezzo che non arriva, una verifica esterna andata male.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo('«Forza semaforo» serve a quei casi, e a nessun altro.', {
    su: page.getByRole('button', { name: 'Forza semaforo' }),
    click: true,
    zoom: 2.1,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 2 di 3', 'Stato e motivazione');

  await g.passo('Si sceglie lo stato che la macchina deve mostrare da adesso.', {
    su: page.locator('#forzaStato'),
    zoom: 2.1,
    durata: 6,
  });

  await page.locator('#forzaStato').selectOption('rosso');
  await page.waitForTimeout(400);

  await g.passo("La motivazione è obbligatoria, ed è il punto della funzione: senza, resterebbe uno stato senza spiegazione.", {
    su: page.locator('[name="forzaForm.motivo"]'),
    zoom: 2,
    durata: 8,
  });

  await page.locator('[name="forzaForm.motivo"]').fill('Guarnizione della camera da sostituire, pezzo ordinato al fornitore. Macchina non utilizzabile fino al montaggio.');
  await page.waitForTimeout(400);

  await g.passo('Conviene scrivere quello che serve a chi leggerà: che cosa è successo, e che cosa si sta aspettando.', {
    su: page.locator('[name="forzaForm.motivo"]'),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo('Si forza.', {
    su: page.getByRole('button', { name: 'Forza', exact: true }),
    click: true,
    zoom: 2.3,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 3 di 3', 'Come si vede, e come si torna indietro');

  await g.passo('Il semaforo ora è quello che hai deciso tu, e la macchina compare così in ogni elenco.', {
    su: page.getByRole('heading', { name: 'Autoclave' }),
    zoom: 2.3,
    durata: 7,
  });

  await g.passo('La Panoramica dichiara che lo stato è forzato e riporta il motivo: non si confonde mai con uno stato calcolato.', {
    su: page.getByText(/Semaforo forzato/).first(),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("Per tornare al calcolo automatico si riapre la stessa finestra e si toglie la forzatura.", {
    su: page.getByRole('button', { name: 'Forza semaforo' }),
    zoom: 2.1,
    durata: 7,
  });

  g.chiusura('Da ricordare', [
    'Il semaforo normalmente è calcolato: forzarlo è l\'eccezione, per stati che le scadenze non sanno descrivere.',
    'La motivazione è obbligatoria e resta visibile in Panoramica: uno stato deciso da una persona non deve sembrare calcolato.',
    'La forzatura non cancella le scadenze: quando si toglie, il semaforo torna a dire quello che i dati dicono.',
  ]);

  g.scrivi();
});

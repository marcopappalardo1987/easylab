import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C5 — «Spostare una macchina».
 *
 * ⚠️ Il copione SCRIVE (sposta davvero lo strumento 55 e lascia una riga nello
 * storico): `azzera.sh` prima di ogni giratura, o alla seconda passata lo
 * storico mostrerebbe due spostamenti identici.
 */
test('spostare strumento', async ({ page }) => {
  const g = new Regista(page, 'spostare-strumento', 'Spostare una macchina', 'Il trasloco, e la traccia che lascia');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Dove sta adesso');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo("L'ubicazione completa è sotto il nome: sede, laboratorio, sotto-laboratorio.", {
    su: page.getByText(/Sede di Milano ›/).first(),
    zoom: 2,
    durata: 7,
  });

  await g.passo('«Sposta» apre il trasloco, e non va confuso con «Modifica»: qui non si cambia la macchina, si cambia dove sta.', {
    su: page.getByRole('button', { name: 'Sposta' }).first(),
    click: true,
    zoom: 2.1,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 2 di 3', 'La destinazione');

  await g.passo("La destinazione si sceglie dall'albero dei laboratori: un laboratorio o un sotto-laboratorio, non un indirizzo scritto a mano.", {
    su: page.locator('#destinazioneId'),
    zoom: 2.1,
    durata: 8,
  });

  await page.locator('#destinazioneId').selectOption({ index: 3 });
  await page.waitForTimeout(500);

  await g.passo('La data serve a datare il trasloco, non a programmarlo: è quando la macchina si è spostata davvero.', {
    su: page.locator('#dataSpostamento'),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo('Si conferma.', {
    su: page.getByRole('button', { name: 'Sposta' }).last(),
    click: true,
    zoom: 2.3,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 3 di 3', 'La traccia');

  await g.passo("L'ubicazione in cima è cambiata, e con lei gli elenchi filtrati per reparto.", {
    su: page.getByText(/Sede di Milano ›/).first(),
    zoom: 2,
    durata: 7,
  });

  await page.getByRole('button', { name: 'Anagrafica' }).first().click();
  await page.waitForLoadState('networkidle');

  await g.passo("Lo spostamento non sostituisce il passato: resta scritto nello storico, con la data e il posto da cui veniva.", {
    su: page.getByText('Spostamenti'),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("È la risposta alla domanda «dov'era prima», che serve più spesso di quanto si creda quando una macchina comincia a dare problemi.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    '«Sposta» cambia dove sta la macchina; «Modifica» cambia che cosa è. Sono due gesti diversi.',
    "La destinazione si sceglie dall'albero: se il laboratorio non c'è, va prima creato in «Laboratori».",
    'Ogni trasloco resta nello storico con la sua data: il passato non si sovrascrive.',
  ]);

  g.scrivi();
});

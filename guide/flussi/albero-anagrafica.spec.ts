import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida B1 — «L'albero dell'anagrafica».
 *
 * ⚠️ Girata con l'**Admin**: un Responsabile Reparto vede solo i nodi che gli
 * sono assegnati, quindi l'albero che ritrarrebbe sarebbe un ramo e non la
 * struttura. La guida parla della struttura.
 */
test('albero anagrafica', async ({ page }) => {
  const g = new Regista(page, 'albero-anagrafica', "L'albero dell'anagrafica", 'Dove sta ogni macchina: sede, dipartimento, laboratorio');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'La sede come è fatta davvero');

  await page.goto('/anagrafica');
  await page.waitForLoadState('networkidle');

  await g.passo("«Anagrafica» apre la sede alla sua radice: è la struttura del laboratorio, non un elenco di macchine.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 6,
  });

  await g.passo('Al primo livello ci sono i dipartimenti.', {
    su: page.getByText('Genetica Medica').first(),
    zoom: 2.1,
    durata: 5,
  });

  await g.passo('Ogni riga porta con sé quante macchine contiene, contando anche quelle dei livelli sotto.', {
    su: page.getByText('Radiologia').first(),
    zoom: 2.1,
    durata: 7,
  });

  g.capitolo('Parte 2 di 3', 'Scendere un livello alla volta');

  await g.passo('Si apre un dipartimento cliccandolo.', {
    su: page.getByText('Genetica Medica').first(),
    click: true,
    zoom: 2.2,
  });

  await page.waitForLoadState('networkidle');

  await g.passo('Dentro ci sono i suoi laboratori, e la pagina si è spostata: adesso stai guardando il dipartimento.', {
    su: page.getByRole('heading').first(),
    zoom: 1.8,
    durata: 7,
  });

  await g.passo("Il percorso in alto dice sempre dove sei, e ogni pezzo è cliccabile.", {
    su: page.getByRole('navigation', { name: 'Percorso' }),
    zoom: 2.4,
    durata: 6,
  });

  await g.passo('Si scende ancora, fino al laboratorio.', {
    su: page.getByText('Camera Bianca').first(),
    click: true,
    zoom: 2.2,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 3 di 3', 'In fondo ci sono le macchine');

  await g.passo("In fondo all'albero non ci sono più laboratori: ci sono gli strumenti.", {
    zoom: 1,
    durata: 6,
  });

  await g.passo('Da qui si apre la scheda di una macchina, senza passare dall\'elenco generale.', {
    su: page.getByRole('link').filter({ hasText: /./ }).last(),
    zoom: 2,
    durata: 6,
  });

  await g.passo('E il percorso riporta su di un livello, o alla radice, senza ricominciare.', {
    su: page.getByRole('navigation', { name: 'Percorso' }),
    zoom: 2.4,
    durata: 6,
  });

  g.chiusura('Da ricordare', [
    "L'anagrafica risponde alla domanda «dove», l'elenco degli strumenti alla domanda «quale».",
    'Il conteggio di una riga comprende tutto quello che sta sotto, non solo il livello immediato.',
    'Il percorso in alto è la via di ritorno: dice dove sei e riporta indietro di un passo.',
  ]);

  g.scrivi();
});

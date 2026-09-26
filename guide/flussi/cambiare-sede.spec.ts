import { expect, test } from '@playwright/test';
import { Regista } from '../lib/regista';

/**
 * Guida A5 — «Passare da una sede all'altra» (ADR-032).
 *
 * ⚠️ Girata con un utente **Tenant**, non col Responsabile Reparto delle altre
 * guide, e non è una scelta di comodo: per un Responsabile lo switcher non
 * compare affatto. `SwitcherEnte::mount()` legge il nome dell'Ente dal modello
 * coi global scope attivi, e `DepartmentScope` esclude il nodo radice — che non
 * è fra i reparti assegnati né loro discendente — quindi `nomeEnte` resta null
 * e la vista è tutta dietro quel controllo. Difetto segnalato il 6 Set 2026.
 */
test('cambiare sede', async ({ page }) => {
  const g = new Regista(page, 'cambiare-sede', 'Passare da una sede all\'altra', 'Quando il tuo contratto ne copre più di una');

  const barra = page.getByRole('banner');
  const switcher = barra.getByRole('button', { name: /Sede di/ });

  await page.goto('/login');
  await page.fill('#email', 'paolo.greco@aurora.test');
  await page.fill('#password', 'guida-demo');
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');

  g.capitolo('Parte 1 di 3', 'Dove sei adesso');

  await g.passo('Un contratto può coprire più sedi. Easy Lab te ne mostra una alla volta.', { durata: 6 });

  await g.passo('Il nome della sede in cui sei sta sempre in alto a sinistra.', {
    su: switcher,
    zoom: 2.6,
    durata: 5,
  });

  await g.passo('E i numeri della Dashboard sono soltanto i suoi.', { durata: 5 });

  g.capitolo('Parte 2 di 3', 'Le tue sedi');

  await g.passo('Se le sedi sono più di una, il nome è cliccabile.', {
    su: switcher,
    click: true,
    zoom: 2.6,
  });

  await page.waitForTimeout(700);

  await g.passo('La tendina elenca le altre sedi del tuo contratto. Solo quelle: non si esce mai dal proprio.', {
    su: page.getByText('Le tue sedi'),
    zoom: 2.2,
    durata: 7,
  });

  await g.passo('Si sceglie, e si cambia.', {
    su: page.getByRole('button', { name: 'Sede di Bologna' }),
    click: true,
    zoom: 2.6,
  });

  await page.waitForTimeout(1200);

  // Se il nome in barra non è cambiato lo switch non è avvenuto, e tutto ciò
  // che il video racconta da qui in poi sarebbe falso.
  await expect(barra.getByRole('button', { name: 'Sede di Bologna' })).toBeVisible({ timeout: 10_000 });

  g.capitolo('Parte 3 di 3', 'Che cosa cambia');

  await g.passo('Da questo momento tutto parla di Bologna: la Dashboard, gli elenchi, le scadenze.', {
    durata: 6,
  });

  await g.passo('Il nome in alto lo conferma.', { su: switcher, zoom: 2.6, durata: 4 });

  await g.passo('Anche gli strumenti sono i suoi.', {
    su: page.getByRole('link', { name: 'Strumenti', exact: true }),
    click: true,
    zoom: 3,
  });

  await page.waitForURL('**/strumenti');
  await expect(page.locator('tbody tr').first()).toBeVisible();

  await g.passo('Le macchine dell\'altra sede non compaiono, e non si mescolano mai.', { durata: 6 });

  await g.passo('Per tornare indietro si rifà la stessa cosa: il nome, e la sede di prima.', {
    su: switcher,
    zoom: 2.6,
    durata: 5.5,
  });

  g.chiusura('Da ricordare', [
    'La sede in cui ti trovi è sempre scritta in alto a sinistra.',
    'La tendina elenca soltanto le sedi del tuo contratto: da lì non si esce.',
    'Cambiando sede cambia tutto quello che vedi, e i dati delle due non si mescolano mai.',
  ]);

  g.scrivi();
});

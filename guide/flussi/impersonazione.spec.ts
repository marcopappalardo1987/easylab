import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida M5 — «Impersonare un cliente». Guida INTERNA.
 *
 * ⚠️ Il copione impersona DAVVERO e poi esce: il gesto lascia due righe di
 * audit, che è esattamente ciò che la guida racconta. `azzera.sh` prima.
 */
test('impersonazione', async ({ page }) => {
  const g = new Regista(page, 'impersonazione', 'Impersonare un cliente', 'Entrare come lui, e la traccia che resta');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 3', 'Perché esiste');

  await page.goto('/piattaforma/parco');
  await page.waitForLoadState('networkidle');

  await g.passo("Dal parco si LEGGE soltanto: nessuna azione sulle macchine dei clienti, per scelta.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Quando serve intervenire davvero — sistemare un dato, mostrare un gesto al telefono — si impersona.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Impersonare significa vedere Easy Lab esattamente come lo vede quella persona: stesso ruolo, stessi limiti.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'I limiti');

  await g.passo("Non tutti sono impersonabili: il Developer non lo è mai, e nessuno può impersonare sé stesso.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Durante l'impersonazione non si accede alle pagine di piattaforma: si è quella persona, non due persone insieme.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("E alcune schermate restano chiuse anche così: la sicurezza del proprio account non si governa per conto d'altri.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'La traccia');

  await g.passo("L'apertura e la chiusura di un'impersonazione sono due righe di audit, con il tuo nome.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("E ogni scrittura fatta nel frattempo porta il timbro di chi c'era dietro: il registro non attribuisce al cliente ciò che hai fatto tu.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È la ragione per cui l'impersonazione è uno strumento accettabile: non è invisibile.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Dal parco si legge; per scrivere si impersona, e si vede esattamente ciò che vede il cliente.',
    'Il Developer non è impersonabile, e durante l\'impersonazione la piattaforma è chiusa.',
    'Apertura, chiusura e ogni scrittura restano nel registro col nome di chi c\'era dietro.',
  ]);

  g.scrivi();
});

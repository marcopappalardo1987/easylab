import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C1 — «L'elenco degli strumenti».
 *
 * Girata con l'Admin, che vede tutta la sede: la guida parla dei filtri, e con
 * un utente che ne vede un ramo solo i numeri non racconterebbero niente.
 */
test('elenco strumenti', async ({ page }) => {
  const g = new Regista(page, 'elenco-strumenti', "L'elenco degli strumenti", 'Cercare, filtrare e ordinare tutto il parco');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Cercare');

  await page.goto('/strumenti');
  await page.waitForLoadState('networkidle');

  await g.passo("«Strumenti» apre il parco intero della sede, senza filtri: è l'elenco da cui si parte quando si sa che cosa si cerca.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 7,
  });

  await g.passo('La ricerca guarda nome, modello e matricola insieme: non devi ricordare con quale dei tre è stata registrata.', {
    su: page.getByPlaceholder(/Cerca per nome/),
    zoom: 2.1,
    durata: 7,
  });

  await page.fill('#search', 'centrifuga');
  await page.waitForTimeout(800);

  await g.passo("L'elenco si restringe mentre scrivi, senza premere invio.", {
    zoom: 1,
    durata: 6,
  });

  await page.fill('#search', '');
  await page.waitForTimeout(800);

  g.capitolo('Parte 2 di 3', 'Filtrare');

  await g.passo("Il filtro per ubicazione segue l'albero dell'anagrafica: si sceglie un reparto e restano le sue macchine.", {
    su: page.locator('select').first(),
    zoom: 2,
    durata: 7,
  });

  await g.passo('Lo stato è il semaforo: in regola, azione richiesta, non idoneo.', {
    su: page.locator('select').nth(1),
    zoom: 2,
    durata: 6,
  });

  await page.locator('select').nth(1).selectOption('arancione');
  await page.waitForTimeout(900);

  await g.passo("Scelto uno stato, l'elenco mostra solo quelle: è la stessa cosa che fa un riquadro della Dashboard.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo("«Solo obsoleti» è a parte, e si somma agli altri filtri: risponde all'età, non alla manutenzione.", {
    su: page.getByText(/obsolet/i).first(),
    zoom: 2.2,
    durata: 7,
  });

  g.capitolo('Parte 3 di 3', 'Ordinare e sfogliare');

  await g.passo("Ogni intestazione di colonna ordina l'elenco, e cliccandola di nuovo lo rovescia.", {
    su: page.getByRole('button', { name: /Prossima scadenza/i }).first(),
    click: true,
    zoom: 2,
  });

  await page.waitForTimeout(900);

  await g.passo("Ordinando per «prossima scadenza» viene su per prima la macchina che chiede attenzione prima di tutte.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo('In fondo si scelgono quante righe per pagina: utile quando si stampa o si fa un giro completo.', {
    su: page.locator('#perPage'),
    zoom: 2.2,
    durata: 7,
  });

  g.chiusura('Da ricordare', [
    'La ricerca guarda nome, modello e matricola insieme, e basta una parte di uno dei tre.',
    'I filtri si sommano: ubicazione, stato e «solo obsoleti» restringono insieme.',
    'Le colonne ordinano, e «prossima scadenza» è quella che mette in cima ciò che scade prima.',
  ]);

  g.scrivi();
});

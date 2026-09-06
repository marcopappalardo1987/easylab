import { expect, test } from '@playwright/test';
import { Regista } from '../lib/regista';

/**
 * Guida 01 — «Registrare un intervento su uno strumento».
 *
 * Il flusso quotidiano: si cerca la macchina, si legge il semaforo, si apre la
 * scheda e si annota che cosa è stato fatto. Copre ricerca, scheda, schede a
 * linguetta e form: è il pezzo di piattaforma che tutti toccano ogni giorno.
 */
test('registrare un intervento', async ({ page }) => {
  const g = new Regista(
    page,
    'intervento',
    'Registrare un intervento',
    'Dalla ricerca dello strumento alla manutenzione annotata',
  );

  await page.goto('/login');

  g.capitolo('Parte 1 di 3', 'Entrare e orientarsi');

  await g.passo('Si entra con le proprie credenziali di lavoro.', {
    su: page.locator('#email'),
    zoom: 2.4,
  });

  await page.fill('#email', 'giulia.ferrari@aurora.test');
  await page.fill('#password', 'guida-demo');

  await g.passo('Un clic su «Accedi» e siamo dentro.', {
    su: page.getByRole('button', { name: /accedi/i }),
    click: true,
    zoom: 2.6,
  });

  await page.waitForURL('**/dashboard');
  await g.passo(
    'La Dashboard apre sullo stato del parco: quante macchine sono a posto e quante chiedono attenzione.',
    { durata: 5 },
  );

  await g.passo('Il menù «Strumenti» porta all\'elenco completo.', {
    su: page.getByRole('link', { name: 'Strumenti', exact: true }),
    click: true,
    zoom: 3,
  });

  await page.waitForURL('**/strumenti');

  g.capitolo('Parte 2 di 3', 'Trovare la macchina giusta');
  await g.passo(
    'Ogni riga porta il proprio semaforo: verde a posto, giallo in scadenza, rosso fermo.',
    { su: page.locator('tbody tr').first(), zoom: 1.8, durata: 5 },
  );

  const ricerca = page.getByPlaceholder(/Cerca per nome/i);
  await g.passo('La ricerca lavora su nome, modello e matricola insieme.', {
    su: ricerca,
    zoom: 2.4,
  });

  await ricerca.pressSequentially('Autoclave', { delay: 40 });
  await page.waitForTimeout(700);

  await g.passo('Basta digitare: l\'elenco si restringe mentre si scrive.', {
    su: page.locator('tbody'),
    zoom: 1.4,
    durata: 4,
  });

  const riga = page.locator('tbody tr td:nth-child(2) a').first();
  const nomeStrumento = (await riga.textContent())?.trim() ?? '';

  await g.passo(`Si apre la scheda di «${nomeStrumento}».`, {
    su: riga,
    click: true,
    zoom: 2.6,
  });

  await page.waitForURL(/\/strumenti\/\d+/);
  await g.passo(
    'La scheda raccoglie tutto quel che si sa della macchina: dove sta, da quando, e la prossima scadenza.',
    { durata: 5.5 },
  );

  g.capitolo('Parte 3 di 3', 'Annotare che cosa è stato fatto');

  await g.passo('La linguetta «Interventi» apre lo storico della manutenzione.', {
    su: page.getByRole('button', { name: 'Interventi' }).first(),
    click: true,
    zoom: 3,
  });

  await page.waitForTimeout(700);
  await g.passo('Ogni intervento passato resta qui, con data, tipo e chi l\'ha eseguito.', {
    durata: 5,
  });

  await g.passo('Per annotarne uno nuovo: «+ Nuovo intervento».', {
    su: page.getByText('+ Nuovo intervento').first(),
    click: true,
    zoom: 3,
  });

  await page.waitForTimeout(700);
  await g.passo('Si descrive che cosa è stato fatto.', {
    su: page.locator('#interventoForm\\.descrizione'),
    zoom: 2,
  });

  await page.fill('#interventoForm\\.descrizione', 'Sostituzione guarnizioni e controllo tenuta');

  await g.passo('Il tipo distingue ordinaria, straordinaria, taratura e full risk.', {
    su: page.locator('#interventoTipo'),
    zoom: 2.6,
  });

  await page.selectOption('#interventoTipo', 'manutenzione_ordinaria');

  await g.passo(
    'La data di scadenza è quella che accenderà il semaforo quando si avvicina.',
    { su: page.locator('#interventoForm\\.data_scadenza'), zoom: 2.6, durata: 5 },
  );

  await page.fill('#interventoForm\\.data_scadenza', '2027-03-15');

  await g.passo('L\'assegnatario è obbligatorio: l\'intervento è sempre di qualcuno.', {
    su: page.locator('#interventoTecnico'),
    zoom: 2.6,
  });

  await page.selectOption('#interventoTecnico', { index: 1 });

  await g.passo('Si salva.', {
    su: page.getByRole('button', { name: 'Salva' }),
    click: true,
    zoom: 2.6,
  });

  // La finestra che resta aperta significa validazione fallita. Senza questa
  // riga il montaggio produrrebbe una guida che racconta un salvataggio mai
  // avvenuto: è già successo alla prima passata, per l'assegnatario mancante.
  await expect(page.getByRole('button', { name: 'Salva' })).toBeHidden({ timeout: 10_000 });
  await page.waitForTimeout(800);
  await g.passo(
    'L\'intervento è in cima allo storico, e la prossima scadenza della macchina si è spostata di conseguenza.',
    { durata: 6 },
  );

  g.chiusura('In tre mosse', [
    'Si cerca la macchina per nome, modello o matricola: la ricerca guarda i tre campi insieme.',
    'La scheda raccoglie stato, ubicazione, garanzie e storico in un posto solo.',
    'Ogni intervento vuole un assegnatario e una data di scadenza: è la data che accende il semaforo.',
  ]);

  g.scrivi();
});

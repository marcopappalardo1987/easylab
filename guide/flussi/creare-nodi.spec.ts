import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida B2 — «Aggiungere e rinominare i reparti».
 *
 * ⚠️ Serve l'**Admin**: `unita_organizzativa.update` è di Admin, Superadmin e
 * Developer, quindi a un Responsabile questi pulsanti non compaiono affatto.
 * Il catalogo diceva «Admin, Responsabile» e sbagliava.
 *
 * ⚠️ Il nome del dipartimento nuovo va scelto fra quelli che NON esistono: il
 * seme ne ha già dodici, Microbiologia compresa, e la prima stesura di questo
 * copione ne creava un doppione — che è esattamente il gesto che la guida
 * insegnerebbe a fare.
 *
 * ⚠️ Il copione SCRIVE (crea un dipartimento): `azzera.sh` gira prima di ogni
 * giratura, o alla seconda passata l'albero avrebbe due «Microscopia».
 */
test('creare nodi', async ({ page }) => {
  const g = new Regista(page, 'creare-nodi', 'Aggiungere e rinominare i reparti', "Tenere l'anagrafica uguale al laboratorio vero");

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Un dipartimento nuovo');

  await page.goto('/anagrafica');
  await page.waitForLoadState('networkidle');

  await g.passo("Il pulsante in alto a destra aggiunge sempre un figlio del punto in cui ti trovi: alla radice, un dipartimento.", {
    su: page.getByRole('button', { name: /Aggiungi dipartimento/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  await page.getByRole('button', { name: /Aggiungi dipartimento/ }).first().click();
  await page.waitForLoadState('networkidle');

  await g.passo('Si apre un modulo, e il nome è la sola cosa obbligatoria.', {
    su: page.getByLabel('Nome'),
    zoom: 2,
    durata: 6,
  });

  await page.fill('#nome', 'Microscopia');

  await g.passo('Conviene scrivere il nome che il reparto ha davvero, non una sigla interna.', {
    su: page.getByLabel('Nome'),
    zoom: 2.2,
    durata: 6,
  });

  await g.passo('Le note e la soglia di obsolescenza si possono lasciare come sono: valgono da qui in giù, e si cambiano quando serve.', {
    zoom: 1,
    durata: 7,
  });

  await g.passo('Si salva.', {
    su: page.getByRole('button', { name: 'Salva' }),
    click: true,
    zoom: 2.4,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 2 di 3', 'Dentro il nuovo reparto');

  await g.passo("Il dipartimento è nell'elenco, vuoto: nessun laboratorio e nessuna macchina.", {
    su: page.getByText('Microscopia').first(),
    zoom: 2.2,
    durata: 6,
  });

  await page.getByText('Microscopia').first().click();
  await page.waitForLoadState('networkidle');

  await g.passo("Entrandoci, lo stesso pulsante ora propone un sotto-laboratorio: cambia con il livello in cui sei.", {
    su: page.getByRole('button', { name: /Aggiungi sotto-laboratorio/ }).first(),
    zoom: 2,
    durata: 7,
  });

  await page.getByRole('button', { name: /Aggiungi sotto-laboratorio/ }).first().click();
  await page.waitForLoadState('networkidle');
  await page.fill('#nome', 'Terreni di coltura');

  await g.passo('Un laboratorio dentro il dipartimento, con lo stesso modulo di prima.', {
    su: page.getByLabel('Nome'),
    zoom: 2.2,
    durata: 6,
  });

  await page.getByRole('button', { name: 'Salva' }).click();
  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 3 di 3', 'Correggere un nome');

  await g.passo("«Rinomina» cambia il nome del nodo in cui ti trovi, e non tocca nient'altro: le macchine restano dove sono.", {
    su: page.getByRole('button', { name: 'Rinomina' }).first(),
    click: true,
    zoom: 2.2,
  });

  await page.waitForLoadState('networkidle');
  await page.fill('#nome', 'Microscopia elettronica');

  await g.passo('Si corregge e si salva: il nome nuovo compare ovunque, briciole comprese.', {
    su: page.getByLabel('Nome'),
    zoom: 2.2,
    durata: 6,
  });

  await page.getByRole('button', { name: 'Salva' }).click();
  await page.waitForLoadState('networkidle');

  await g.passo("«Elimina» c'è, ma vale la pena ricordare che porta via anche quello che sta sotto.", {
    su: page.getByRole('button', { name: 'Elimina' }).first(),
    zoom: 2.2,
    durata: 7,
  });

  g.chiusura('Da ricordare', [
    "Il pulsante di aggiunta segue il livello in cui ti trovi: alla radice crea un dipartimento, dentro un dipartimento un laboratorio.",
    'Rinominare è sicuro: cambia solo l\'etichetta, e le macchine non si spostano.',
    "L'anagrafica va tenuta uguale al reparto vero: è da lì che le macchine ereditano dove sono, e chi le può vedere.",
  ]);

  g.scrivi();
});

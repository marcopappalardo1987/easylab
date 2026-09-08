import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida H1 — «I fornitori».
 *
 * ⚠️ Il copione SCRIVE (crea un fornitore): `azzera.sh` prima di ogni giratura.
 */
test('fornitori', async ({ page }) => {
  const g = new Regista(page, 'fornitori', 'I fornitori', 'A chi si telefona quando una macchina si guasta');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'A che cosa serve');

  await page.goto('/fornitori');
  await page.waitForLoadState('networkidle');

  await g.passo("«Fornitori» è l'elenco di chi vende e assiste le macchine della sede.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 7,
  });

  await g.passo("Ogni riga porta contatti e quante macchine gli sono legate: è il numero che dice quanto conta quel fornitore per te.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Non è una rubrica generica: un fornitore esiste qui perché una macchina lo indica, e serve il giorno del guasto.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Aggiungerne uno');

  await g.passo('Se ne aggiunge uno quando arriva una macchina di una marca nuova.', {
    su: page.getByRole('button', { name: /Nuovo|Aggiungi/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  await page.getByRole('button', { name: /Nuovo|Aggiungi/ }).first().click();
  await page.waitForTimeout(900);

  await page.fill('#form\\.ragione_sociale', 'Zeiss Italia');

  await g.passo('La ragione sociale è quella con cui emette fattura, non il nome commerciale abbreviato.', {
    su: page.locator('#form\\.ragione_sociale'),
    zoom: 2.1,
    durata: 8,
  });

  await page.fill('#form\\.telefono', '02 1234567');
  await page.fill('#form\\.email', 'assistenza@zeiss.example');

  await g.passo("Telefono ed email sono quelli dell'assistenza, non del commerciale: sono i contatti che servono quando la macchina è ferma.", {
    su: page.locator('#form\\.email'),
    zoom: 2,
    durata: 8,
  });

  await g.passo("Le note sono il posto per il numero di contratto, gli orari, o il nome della persona che risponde.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo('Si salva.', {
    su: page.locator('form:has(#form\\.ragione_sociale)').getByRole('button', { name: 'Salva' }),
    zoom: 2.3,
    durata: 5,
  });

  await page.locator('form:has(#form\\.ragione_sociale)').getByRole('button', { name: 'Salva' }).click();
  await page.waitForTimeout(1500);

  g.capitolo('Parte 3 di 3', 'Come si lega a una macchina');

  await g.passo("Il fornitore appena creato è in elenco, con zero macchine collegate.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo("Il legame si fa dalla scheda della macchina, nel campo «Fornitore»: è lì che si sceglie fra quelli in elenco.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Da quel momento il conteggio sale, e dalla macchina si arriva a chi chiamare senza cercare in rubrica.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "L'elenco esiste per rispondere a «a chi telefono»: i contatti da mettere sono quelli dell'assistenza.",
    'Il legame si fa dalla scheda della macchina, non da qui: qui si crea il fornitore, là lo si assegna.',
    'Il numero di macchine collegate dice quanto quel fornitore pesa sul tuo parco.',
  ]);

  g.scrivi();
});

import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C3 — «Registrare una macchina nuova».
 *
 * ⚠️ Si parte dall'ANAGRAFICA e non dall'elenco: lo strumento nasce dentro un
 * laboratorio, e il modulo prende l'ubicazione dal nodo in cui ti trovi. Non
 * esiste un «nuovo strumento» generico in cui scegliere il reparto da una
 * tendina, ed è la cosa che la guida deve far capire per prima.
 *
 * ⚠️ Il «Salva» va preso DENTRO il form dello strumento: la pagina monta anche
 * la modale del nodo, che ha un «Salva» suo, e `.first()` prendeva quello — il
 * salvataggio non avveniva e il copione proseguiva senza accorgersene.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('nuovo strumento', async ({ page }) => {
  const g = new Regista(page, 'nuovo-strumento', 'Registrare una macchina nuova', 'Nasce dentro il laboratorio in cui si trova');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Si parte dal posto giusto');

  await page.goto('/anagrafica');
  await page.waitForLoadState('networkidle');

  await g.passo("Una macchina si registra dall'anagrafica, non dall'elenco: prima si va nel laboratorio in cui si trova.", {
    su: page.getByText('Genetica Medica').first(),
    click: true,
    zoom: 2.1,
  });

  await page.waitForLoadState('networkidle');
  await page.getByText('Camera Bianca').first().click();
  await page.waitForLoadState('networkidle');

  await g.passo("Le briciole in alto sono la conferma: quello che stai per creare nascerà qui dentro.", {
    su: page.getByRole('navigation').first(),
    zoom: 2.3,
    durata: 7,
  });

  await g.passo('«Aggiungi strumento» sta sotto, accanto al numero di macchine già presenti.', {
    su: page.getByRole('button', { name: /Aggiungi strumento/ }).first(),
    click: true,
    zoom: 2.1,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 2 di 3', 'I campi che contano');

  await page.fill('#strumentoForm\\.nome', 'Microscopio confocale');

  await g.passo('Il nome è quello con cui la macchina viene chiamata in reparto: è il primo campo su cui si cerca.', {
    su: page.locator('#strumentoForm\\.nome'),
    zoom: 2.1,
    durata: 7,
  });

  await page.fill('#strumentoForm\\.modello', 'Zeiss LSM 900');

  await g.passo('Il modello è quello del costruttore, e serve anche alla vista «Per modello».', {
    su: page.locator('#strumentoForm\\.modello'),
    zoom: 2.1,
    durata: 7,
  });

  await page.fill('#strumentoForm\\.matricola', 'MIC-004512');

  await g.passo("La matricola identifica questa unità e nessun'altra: è quella incisa sulla targhetta.", {
    su: page.locator('#strumentoForm\\.matricola'),
    zoom: 2.1,
    durata: 7,
  });

  await page.fill('#strumentoForm\\.data_installazione', '2026-06-15');

  await g.passo("La data di installazione è da quando la macchina è in servizio: da lì si conta l'obsolescenza.", {
    su: page.locator('#strumentoForm\\.data_installazione'),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo('Il fornitore è obbligatorio, e si sceglie fra quelli in anagrafica: è a chi telefonare quando la macchina si guasta.', {
    su: page.locator('#strumentoForm\\.fornitore_id'),
    zoom: 2,
    durata: 8,
  });

  await page.locator('#strumentoForm\\.fornitore_id').selectOption({ index: 1 });
  await page.waitForTimeout(400);

  await g.passo('Si salva.', {
    su: page.locator('form:has(#strumentoForm\\.nome)').getByRole('button', { name: 'Salva' }),
    click: true,
    zoom: 2.3,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 3 di 3', 'E adesso');

  await g.passo("La macchina è nell'elenco del laboratorio, e il conteggio del reparto è salito.", {
    su: page.getByText('Microscopio confocale').first(),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo('Nasce verde perché non ha ancora scadenze: il semaforo dirà qualcosa quando ci sarà un intervento da fare.', {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Il primo gesto utile è aprirla e pianificare la prima manutenzione: una macchina senza scadenze non comparirà mai nello Scadenzario.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "Una macchina nasce dentro un laboratorio: si naviga prima nel posto giusto, poi si aggiunge.",
    'Nome, modello e matricola sono i tre campi su cui lavora la ricerca: vale la pena scriverli come sono davvero.',
    'Appena creata è verde perché non ha scadenze: la prima manutenzione va pianificata subito, o resterà invisibile.',
  ]);

  g.scrivi();
});

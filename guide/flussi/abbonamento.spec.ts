import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida K1 — «L'abbonamento».
 *
 * ⚠️ Il portale di fatturazione è **di Stripe**, su un dominio di terzi: il
 * copione si ferma prima e lo dice. Filmare il portale mostrerebbe
 * un'interfaccia che non è di Easy Lab e che Stripe cambia quando vuole.
 *
 * ⚠️ L'account dimostrativo NON ha un abbonamento Stripe, quindi la pagina
 * mostra «Nessun abbonamento» e al posto del pulsante c'è il messaggio che dice
 * a chi rivolgersi. Non è un difetto ed è lo stato che vede un cliente prima
 * dell'attivazione: le didascalie lo dicono invece di fingere il contrario.
 */
test('abbonamento', async ({ page }) => {
  const g = new Regista(page, 'abbonamento', "L'abbonamento", 'Il piano, i limiti, e dove stanno le fatture');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Che cosa dice la pagina');

  await page.goto('/abbonamento');
  await page.waitForLoadState('networkidle');

  await g.passo("«Abbonamento» riguarda il contratto della tua organizzazione, non il tuo account: la vede chi amministra.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("In cima c'è la ragione sociale con cui il contratto è intestato: è quella che comparirà sulle fatture.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Il piano e i limiti');

  await g.passo("Il piano dice che cosa è stato sottoscritto, e lo stato se è attivo, in prova o sospeso.", {
    su: page.getByText('Piano').first(),
    zoom: 2,
    durata: 8,
  });

  await g.passo("I limiti sono espressi in sedi: quante ne stai usando, e quante ne prevede il piano.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Sono i numeri da guardare prima di aprire una sede nuova: se il piano non la copre, va cambiato prima.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Le fatture e il metodo di pagamento');

  await g.passo("Con un abbonamento attivo qui compare «Apri il portale di fatturazione»; senza, la pagina dice a chi rivolgersi.", {
    su: page.getByText(/portale di fatturazione/i).first(),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("È un sito di terzi, e non è un difetto: i dati della carta non passano mai da Easy Lab, che non li conserva.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Da lì si scaricano le fatture, si cambia il metodo di pagamento e si aggiornano i dati di fatturazione.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Finita la visita si torna qui, e le modifiche fatte là si riflettono su questa pagina.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "L'abbonamento è dell'organizzazione: lo vede e lo governa chi amministra, non ogni utente.",
    'I limiti sono in sedi: si controllano prima di aprirne una nuova, non dopo.',
    'Fatture e metodo di pagamento stanno nel portale di Stripe: i dati della carta non passano da Easy Lab.',
  ]);

  g.scrivi();
});

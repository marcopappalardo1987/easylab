import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida J1 — «Campo: lavorare col telefono».
 *
 * ⚠️ Girata alla larghezza dello studio, non su un telefono finto: la pagina è
 * responsiva e il copione ne ritrae il contenuto, non il formato. Dire «è
 * pensata per il telefono» nel testo è più onesto che simulare uno schermo che
 * poi non è quello di nessuno.
 */
test('vista campo', async ({ page }) => {
  const g = new Regista(page, 'vista-campo', 'Campo: lavorare col telefono', 'Il QR sulla macchina e i propri interventi, in reparto');

  await accedi(page, 'giulia.ferrari@aurora.test');

  g.capitolo('Parte 1 di 3', 'A che cosa serve');

  await page.goto('/campo');
  await page.waitForLoadState('networkidle');

  await g.passo("«Campo» è la pagina pensata per il telefono, da usare stando davanti alla macchina invece che alla scrivania.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Fa due cose sole, ed è il punto: inquadrare un QR, oppure aprire i propri interventi.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Inquadrare il QR');

  await g.passo("Il primo pulsante accende la fotocamera e legge l'adesivo attaccato sulla macchina.", {
    su: page.getByRole('button').filter({ hasText: /./ }).first(),
    zoom: 2,
    durata: 8,
  });

  await g.passo("Letto il codice, si apre la scheda di quella macchina: senza cercarla per nome, senza matricole da digitare.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Se il codice non è di Easy Lab, la pagina lo dice invece di provarci: non porta da nessuna parte per sbaglio.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'I miei interventi');

  await g.passo("Sotto ci sono i tuoi interventi aperti, quelli assegnati a te: è la stessa lista dello Scadenzario con «Solo i miei».", {
    su: page.getByText(/I miei interventi/),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("Ogni riga porta alla macchina, e da lì si segna il lavoro come fatto mentre lo si sta facendo.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È la differenza fra annotare in reparto e annotare a fine giornata: la seconda è quella in cui si dimentica qualcosa.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Campo fa due cose: legge il QR di una macchina e mostra i tuoi interventi aperti.',
    'Serve a lavorare stando davanti alla macchina, col telefono in mano.',
    'Annotare mentre si lavora è il modo per non perdere pezzi a fine giornata.',
  ]);

  g.scrivi();
});

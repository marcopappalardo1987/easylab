import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida D3 — «Lo Scadenzario».
 *
 * Girata col **Responsabile Reparto**: è la vista con cui si pianifica la
 * settimana, e «solo i miei» ha senso solo per chi ha interventi assegnati.
 */
test('scadenzario', async ({ page }) => {
  const g = new Regista(page, 'scadenzario', 'Lo Scadenzario', 'Tutto quello che è aperto, su tutte le macchine');

  await accedi(page, 'giulia.ferrari@aurora.test');

  g.capitolo('Parte 1 di 3', 'Il punto di vista rovesciato');

  await page.goto('/scadenzario');
  await page.waitForLoadState('networkidle');

  await g.passo("Lo Scadenzario parte dalle scadenze invece che dalle macchine: le raccoglie tutte in un elenco solo.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo('In cima ci sono tre riquadri: scaduti, in scadenza, e più avanti nel tempo.', {
    su: page.getByText('Scaduti').first(),
    zoom: 1.9,
    durata: 7,
  });

  await g.passo("«Scaduti» è la colonna da guardare per prima: sono interventi la cui data è passata e che nessuno ha ancora segnato come fatti.", {
    su: page.getByText('Scaduti').first(),
    click: true,
    zoom: 2.1,
  });

  await page.waitForLoadState('networkidle');

  await g.passo("Cliccando un riquadro l'elenco si restringe a quelle righe, ed è il modo più rapido di costruirsi la lista del giorno.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Restringere');

  await g.passo('La ricerca guarda la macchina e la descrizione insieme: si trova per nome di strumento o per che cosa va fatto.', {
    su: page.getByPlaceholder(/Cerca per macchina/),
    zoom: 2,
    durata: 8,
  });

  await g.passo('Il tipo separa la manutenzione ordinaria dalle tarature, dalle straordinarie e dal resto.', {
    su: page.locator('select').first(),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo('«Solo i miei» lascia gli interventi assegnati a te: è la lista con cui si comincia la giornata.', {
    su: page.getByText(/Solo i miei|solo i miei/i).first(),
    zoom: 2.1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Le righe');

  await g.passo('Ogni riga porta lo stato, la data, la macchina, dove sta e a chi è assegnata.', {
    su: page.getByText('Assegnatario').first(),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("Il colore della data dice quanto manca: rossa se è passata, gialla se sta arrivando.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo('Dalla riga si apre la macchina, e da lì si segna l\'intervento come fatto: è il giro completo.', {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "L'elenco degli strumenti parte dalle macchine, lo Scadenzario parte dalle scadenze: stessa verità, due domande diverse.",
    'I tre riquadri in cima sono filtri già pronti: scaduti, in scadenza, più avanti.',
    '«Solo i miei» trasforma la vista di reparto nella propria lista di lavoro.',
  ]);

  g.scrivi();
});

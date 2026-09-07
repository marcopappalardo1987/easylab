import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C2 — «La scheda di una macchina».
 *
 * ⚠️ Le linguette sono `<button>` mossi da Alpine, non link: la scheda non
 * ricarica la pagina cambiando linguetta, e `getByRole('link')` non le trova.
 *
 * ⚠️ Girata con l'**Admin**: le linguette Garanzie e Ricambi hanno visibilità
 * diversa per ruolo (ADR-020/029), e la guida le nomina tutte e sei.
 */
test('scheda strumento', async ({ page }) => {
  const g = new Regista(page, 'scheda-strumento', 'La scheda di una macchina', 'Panoramica e le sei linguette');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'La panoramica');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo("La scheda apre sempre sulla Panoramica: è il riassunto, e serve a decidere se serve andare più a fondo.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 7,
  });

  await g.passo("«In sintesi» dice le tre cose che si cercano per prime: stato, dove sta, da quando è in servizio.", {
    su: page.getByText('In sintesi'),
    zoom: 1.9,
    durata: 7,
  });

  await g.passo('La prossima scadenza è quella che deciderà il colore del semaforo quando si avvicina.', {
    su: page.getByText('Prossimo intervento'),
    zoom: 1.9,
    durata: 7,
  });

  await g.passo('Le statistiche contano la vita della macchina: interventi fatti, scaduti non fatti, ricambi montati.', {
    su: page.getByText('Statistiche'),
    zoom: 1.8,
    durata: 7,
  });

  g.capitolo('Parte 2 di 3', 'Le linguette');

  await g.passo('Sopra la panoramica ci sono sei linguette: Panoramica, Anagrafica, Interventi, Ricambi, Documenti, Garanzie.', {
    su: page.getByRole('button', { name: 'Interventi' }).first(),
    zoom: 1.9,
    durata: 7,
  });

  await page.getByRole('button', { name: 'Interventi' }).first().click();
  await page.waitForLoadState('networkidle');

  await g.passo("«Interventi» è lo storico della manutenzione, dal più recente: è la fonte di verità, il semaforo ne è solo il riassunto.", {
    zoom: 1,
    durata: 8,
  });

  await page.getByRole('button', { name: 'Garanzie' }).first().click();
  await page.waitForLoadState('networkidle');

  await g.passo('«Garanzie» tiene la copertura della macchina e quella dei pezzi montati, che sono due cose distinte.', {
    zoom: 1,
    durata: 7,
  });

  await page.getByRole('button', { name: 'Documenti' }).first().click();
  await page.waitForLoadState('networkidle');

  await g.passo('«Documenti» raccoglie manuali, certificati e rapporti: si allegano qui e si ritrovano qui.', {
    zoom: 1,
    durata: 7,
  });

  await page.getByRole('button', { name: 'Anagrafica' }).first().click();
  await page.waitForLoadState('networkidle');

  await g.passo("«Anagrafica» tiene i dati fissi e lo storico degli spostamenti: dove la macchina è stata, non solo dov'è.", {
    zoom: 1,
    durata: 7,
  });

  g.capitolo('Parte 3 di 3', 'Quello che si può fare da qui');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo('In alto ci sono le azioni sulla macchina, e compaiono solo se il tuo ruolo può compierle.', {
    su: page.getByRole('button', { name: 'Modifica' }).first(),
    zoom: 2,
    durata: 7,
  });

  await g.passo("«Etichetta QR» stampa l'adesivo da attaccare sulla macchina, per ritrovarla col telefono.", {
    su: page.getByRole('link', { name: /Etichetta QR/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo('«Forza semaforo» esiste per i casi che la manutenzione non descrive, e chiede sempre una motivazione.', {
    su: page.getByRole('button', { name: /Forza/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  g.chiusura('Da ricordare', [
    'La Panoramica è il riassunto; la verità sta nelle linguette, e in particolare nello storico degli interventi.',
    'Le sei linguette sono aspetti della stessa macchina: anagrafica, interventi, ricambi, documenti, garanzie.',
    'Le azioni in alto compaiono solo se il tuo ruolo può compierle: due persone vedono due schede diverse.',
  ]);

  g.scrivi();
});

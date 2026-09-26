import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C10 — «Lo storico in PDF».
 *
 * ⚠️ Il PDF non si può filmare: la rotta lo restituisce come scaricamento, e il
 * browser non lo apre in pagina. Il copione ritrae quindi **la fonte** — la
 * scheda e la linguetta Interventi — dicendo che il documento contiene quello,
 * impaginato. È una scelta dichiarata, non una scorciatoia: filmare il visore
 * PDF del sistema operativo mostrerebbe una finestra che non è Easy Lab.
 */
test('storico pdf', async ({ page }) => {
  const g = new Regista(page, 'storico-pdf', 'Lo storico in PDF', 'Il documento con tutta la vita della macchina');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 2', 'Da dove si prende');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo('«Storico PDF» sta fra le azioni della scheda, e produce un documento sulla singola macchina.', {
    su: page.getByRole('link', { name: /Storico PDF/ }).first(),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("Non è un'esportazione da configurare: si clicca e il file arriva, già impaginato.", {
    zoom: 1,
    durata: 7,
  });

  g.capitolo('Parte 2 di 2', 'Che cosa contiene, e a chi serve');

  await page.getByRole('button', { name: 'Interventi' }).first().click();
  await page.waitForLoadState('networkidle');

  await g.passo('Dentro c\'è questo: l\'anagrafica della macchina e lo storico completo degli interventi, dal più recente.', {
    zoom: 1,
    durata: 8,
  });

  await g.passo('Ogni riga porta data, tipo, descrizione e chi ha eseguito: le stesse informazioni che leggi qui.', {
    su: page.getByText('Descrizione').first(),
    zoom: 1.8,
    durata: 8,
  });

  await g.passo("Serve quando la manutenzione va dimostrata a qualcun altro: un ispettore, un cliente, un ente certificatore.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È anche il documento da allegare quando una macchina cambia proprietario o esce dall'Ente: la sua storia esce con lei.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Il PDF fotografa il momento in cui lo chiedi: se domani registri un intervento, va rigenerato.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Il PDF contiene anagrafica e storico completo degli interventi di una macchina, impaginati.',
    'Serve a dimostrare la manutenzione fuori da Easy Lab: ispezioni, certificazioni, passaggi di proprietà.',
    'È una fotografia del momento: dopo un intervento nuovo va rigenerato.',
  ]);

  g.scrivi();
});

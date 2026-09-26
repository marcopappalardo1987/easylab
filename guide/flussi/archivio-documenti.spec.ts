import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida G2 — «L'archivio dei documenti». */
test('archivio documenti', async ({ page }) => {
  const g = new Regista(page, 'archivio-documenti', "L'archivio dei documenti", "Tutti gli allegati dell'Ente, in un posto solo");

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Tutto insieme');

  await page.goto('/documenti');
  await page.waitForLoadState('networkidle');

  await g.passo("«Documenti» nel menù raccoglie gli allegati di tutte le macchine della sede: la stessa roba delle schede, vista insieme.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Serve quando non sai su quale macchina sta il file che cerchi, o quando ne cerchi molti dello stesso tipo.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Trovare');

  await g.passo('La ricerca lavora sul nome del file.', {
    su: page.locator('#doc-cerca'),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo("Il filtro per tipo separa manuali, certificati e rapporti: è il modo di tirare fuori tutti i certificati di taratura dell'anno.", {
    su: page.locator('#doc-tipo'),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("E si può restringere a una macchina sola, quando si sa già quale.", {
    su: page.locator('#doc-macchina'),
    zoom: 2.1,
    durata: 7,
  });

  await g.passo("Ogni riga dice a che cosa è allegato il file: alla macchina, o a un intervento preciso.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Portarli fuori');

  await g.passo("«Scarica» prende il singolo file, e passa dall'applicazione: il permesso viene ricontrollato e lo scarico registrato.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("L'esportazione in PDF prende invece l'elenco: non i file, la loro lista con i filtri che hai applicato.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Serve a dimostrare che cosa si ha in archivio, per esempio in preparazione di una verifica.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "L'archivio è la stessa roba delle schede, raccolta: si usa quando non sai su quale macchina cercare.",
    'I filtri per tipo e per macchina si sommano alla ricerca sul nome del file.',
    "L'esportazione PDF dà l'elenco filtrato, non i file: serve a dimostrare che cosa c'è.",
  ]);

  g.scrivi();
});

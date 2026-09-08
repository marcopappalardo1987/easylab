import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida M9 — «L'error tracker». Guida INTERNA.
 *
 * ⚠️ Girata col **Developer** e non col Superadmin: `/piattaforma/errori` sta
 * dietro `system.logs.view`, che è del solo Developer — al Superadmin risponde
 * 403. È il gate più stretto del prodotto (🔗 ADR-017), e la guida lo dice.
 */
test('errori', async ({ page }) => {
  const g = new Regista(page, 'errori', "L'error tracker", 'Gli errori dell\'applicazione, senza mandarli fuori');

  await accedi(page, 'developer@easylab.test');

  g.capitolo('Parte 1 di 3', 'Perché è interno');

  await page.goto('/piattaforma/errori');
  await page.waitForLoadState('networkidle');

  await g.passo("Gli errori dell'applicazione si raccolgono qui dentro, non su un servizio esterno.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Un errore contiene messaggi, input di richiesta e identificativi: mandarlo fuori significherebbe mandare fuori dati dei clienti.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Da qui la scelta di costruirlo invece di comprarlo: un fornitore in più sarebbe stato un sub-responsabile in più.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Chi lo vede');

  await g.passo("È la pagina più chiusa del prodotto: la vede il solo Developer, e nemmeno il Superadmin.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Non è gerarchia: è che gli errori contengono dati dei clienti, e chi non deve leggerli non li legge.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Come si legge');

  await g.passo("Gli errori sono raggruppati per tipo: un problema che si ripete è una riga sola con un conteggio, non cento righe.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Aprendone uno si vede quando è comparso la prima volta, quante volte è successo, e la traccia tecnica.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Il contesto viene ripulito PRIMA di essere salvato: password e segreti non entrano nel database, non vengono mascherati dopo.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Gli errori restano dentro Easy Lab: mandarli fuori avrebbe significato mandare fuori dati dei clienti.',
    'La pagina è del solo Developer, Superadmin compreso fra chi non la vede.',
    'La ripulitura avviene prima del salvataggio: i segreti non entrano nel database.',
  ]);

  g.scrivi();
});

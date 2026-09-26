import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida J2 — «Dall'adesivo alla scheda».
 *
 * ⚠️ L'indirizzo del QR è **firmato** e nessuna schermata lo produce: il
 * copione se lo fa coniare da artisan, esattamente come fa la pagina
 * dell'etichetta (`App\Support\QrStrumento::url()`).
 */
test('scansione qr', async ({ page }) => {
  const g = new Regista(page, 'scansione-qr', "Dall'adesivo alla scheda", 'Che cosa succede quando inquadri il codice');

  const indirizzo = artisan(
    'echo \\App\\Support\\QrStrumento::url(\\App\\Models\\Strumento::withoutGlobalScopes()->find(55));',
  );

  g.capitolo('Parte 1 di 3', 'Il codice porta qui');

  await accedi(page, 'maria.conti@aurora.test');

  await page.goto(indirizzo);
  await page.waitForLoadState('networkidle');

  await g.passo("Inquadrando l'adesivo, il telefono apre questa scheda: la macchina è quella, senza cercarla.", {
    su: page.getByRole('heading').first(),
    zoom: 1.8,
    durata: 8,
  });

  await g.passo("Non è una pagina speciale: è la stessa scheda che si apre dall'elenco, con le stesse linguette e le stesse azioni.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Quello che puoi farci dipende dal tuo ruolo, esattamente come sempre: il QR non aggiunge permessi.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Se non hai fatto accesso');

  await g.passo("Il codice da solo non mostra niente: chi non è entrato in Easy Lab finisce sulla pagina di accesso.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Fatto l'accesso si arriva sulla macchina che si era inquadrata, senza dover ricominciare.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È il motivo per cui un adesivo fotografato da un estraneo non è un problema: non porta a nessun dato.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Quando un codice non funziona');

  await g.passo("Se l'etichetta è stata rigenerata, il vecchio adesivo non porta più a niente: si stampa quello nuovo.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Se il codice è di qualcun altro — l'etichetta del costruttore, un QR pubblicitario — la pagina lo dice e si ferma.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Il collegamento non scade nel tempo: un'etichetta vale quanto la macchina su cui è attaccata.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Il QR porta alla scheda della macchina, che è la stessa che si apre dall\'elenco.',
    'Senza accesso non mostra niente: prima si entra, poi si arriva alla macchina inquadrata.',
    "Il codice non scade, ma rigenerarlo invalida gli adesivi già stampati.",
  ]);

  g.scrivi();
});

import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida M6 — «I tecnici EasyLab». Guida INTERNA. */
test('tecnici easylab', async ({ page }) => {
  const g = new Regista(page, 'tecnici-easylab', 'I tecnici EasyLab', 'Chi lavora su quali clienti, e che cosa comporta');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 2', 'Il portafoglio');

  await page.goto('/piattaforma/tecnici');
  await page.waitForLoadState('networkidle');

  await g.passo("La pagina elenca i tecnici di EasyLab e i clienti su cui ciascuno lavora.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Il portafoglio è dichiarato, non implicito: un tecnico lavora sui clienti che gli sono stati assegnati, e su nessun altro.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 2', 'Non è una nota organizzativa');

  await g.passo("🔴 Aggiungere un cliente al portafoglio di un tecnico gli apre TUTTE le macchine di quella sede.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Non è quindi un promemoria di chi segue chi: è un conferimento di accesso, e va trattato come tale.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Toglierlo revoca l'accesso, e lascia intatto quello che il tecnico ha registrato: lo storico resta suo.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Vale la pena rivederlo quando qualcuno cambia zona o lascia: un portafoglio vecchio è un accesso aperto.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Il portafoglio dice su quali clienti lavora un tecnico.',
    "🔴 È un conferimento di accesso, non una nota: aggiungere un cliente apre tutte le macchine di quella sede.",
    'Va rivisto quando qualcuno cambia zona o lascia.',
  ]);

  g.scrivi();
});

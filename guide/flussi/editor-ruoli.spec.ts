import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida M7 — «L'editor dei ruoli». Guida INTERNA. */
test('editor ruoli', async ({ page }) => {
  const g = new Regista(page, 'editor-ruoli', "L'editor dei ruoli", 'La matrice ruolo→permesso, e le celle che raccontano una storia');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 3', 'La matrice');

  await page.goto('/piattaforma/ruoli');
  await page.waitForLoadState('networkidle');

  await g.passo("Una riga per permesso, una colonna per ruolo: la matrice dice chi può fare che cosa, in tutto il prodotto.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Le celle si accendono e si spengono, e la modifica vale da subito per tutti quelli che hanno quel ruolo.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È la pagina più potente della piattaforma, e per questo alcune celle non si toccano.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Le celle bloccate');

  await g.passo("Un permesso «bloccato» non è ridistribuibile dall'interfaccia: sono i vincoli di privacy e di sicurezza del prodotto.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Non è una svista: se fossero modificabili, un clic potrebbe aprire a un ruolo dati che per contratto non deve vedere.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Cambiarli richiede di passare dalla configurazione e da un rilascio, cioè da qualcuno che se ne assume la responsabilità.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', "«Personalizzato» e le orfane");

  await g.passo("Una cella «personalizzato» segnala che il database e la configurazione non dicono la stessa cosa, e mostra i due valori.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È il posto in cui si decide se riseminare: riseminare riporta tutto alla configurazione e cancella queste differenze.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("In fondo ci sono i permessi «orfani»: righe che il codice non usa più e che restano attaccate a qualche ruolo.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'La matrice governa i permessi di tutto il prodotto: si modifica a runtime e vale subito.',
    'I permessi bloccati non si ridistribuiscono dalla UI: sono vincoli, non dimenticanze.',
    'Le celle «personalizzato» mostrano dove il DB si è allontanato dalla configurazione: è da lì che si decide se riseminare.',
  ]);

  g.scrivi();
});

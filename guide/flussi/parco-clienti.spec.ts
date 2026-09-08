import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida M2 — «Il parco di tutti i clienti». Guida INTERNA. */
test('parco clienti', async ({ page }) => {
  const g = new Regista(page, 'parco-clienti', 'Il parco di tutti i clienti', 'Leggere la strumentazione di tutti, in sola lettura');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 3', 'Una vista sola su tutti');

  await page.goto('/piattaforma/parco');
  await page.waitForLoadState('networkidle');

  await g.passo("Il parco mette insieme gli strumenti di tutti i clienti: è l'unica vista che attraversa i confini fra Enti.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Serve al supporto: quando un cliente telefona, la macchina si trova senza chiedergli di dettarne la matricola.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Ogni riga porta il cliente a cui appartiene, oltre a stato, ubicazione e prossima scadenza.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Filtrare');

  await g.passo("Si filtra per cliente, per stato e per obsolescenza, come nell'elenco che vede il cliente stesso.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("La ricerca guarda nome, modello e matricola: le stesse tre colonne, su tutto il parco.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Sola lettura, e perché');

  await g.passo("Da qui si LEGGE soltanto: non ci sono azioni sulle macchine, e non è una dimenticanza.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Per scrivere si impersona il cliente, e allora la traccia porta il nome di chi c'era davvero dietro.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Serve a tenere distinte due cose: guardare per aiutare, e agire al posto di qualcuno.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "È l'unica vista che attraversa i clienti, e serve al supporto.",
    'È in sola lettura per scelta: per scrivere si impersona.',
    'I filtri sono gli stessi che vede il cliente, con in più la colonna del cliente.',
  ]);

  g.scrivi();
});

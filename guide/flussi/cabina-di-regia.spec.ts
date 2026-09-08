import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida M1 — «La cabina di regia».
 *
 * ⚠️ Guida INTERNA: non compare nella Guida dei clienti, che filtra per
 * permesso. Girata col Superadmin di piattaforma del seme
 * (`bin/pianta-piattaforma.sh`).
 */
test('cabina di regia', async ({ page }) => {
  const g = new Regista(page, 'cabina-di-regia', 'La cabina di regia', 'La home di piattaforma: clienti, numeri, provisioning');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 3', 'Che cosa si vede entrando');

  await page.goto('/piattaforma');
  await page.waitForLoadState('networkidle');

  await g.passo("La cabina è la home di chi gestisce EasyLab, non di chi lo usa: raccoglie i clienti e come stanno.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("In cima i numeri della piattaforma: quanti clienti, quanto fatturato ricorrente, che cosa richiede attenzione.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Sotto l'elenco dei clienti, con piano, stato dell'abbonamento e dimensione del parco.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Trovare un cliente');

  await g.passo("La ricerca lavora sulla ragione sociale: è il modo più rapido quando arriva una telefonata.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("I preferiti tengono in cima quelli che si guardano tutti i giorni, senza cercarli ogni volta.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Da ogni riga si entra nella scheda del cliente, che è il punto da cui partono tutte le azioni su di lui.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', "Che cosa si fa da qui");

  await g.passo("Da qui si segue la vita commerciale di un cliente: piano, stato dell'abbonamento, blocco per insoluto.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("«Nuovo cliente» apre il provisioning: crea il cliente con la sua prima sede e invita l'Amministratore per email.", {
    su: page.getByRole('button', { name: /Nuovo cliente/ }).first(),
    click: true,
    zoom: 2,
  });

  await page.waitForTimeout(900);

  await g.passo("Sullo stesso modulo si aggiunge anche una sede a un cliente che c'è già: cambia solo il titolo.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'La cabina è la vista di piattaforma: i clienti e come stanno, non le macchine di uno solo.',
    'I preferiti servono a chi guarda gli stessi clienti ogni giorno.',
    'Il provisioning di un cliente nuovo si fa da console, non da qui.',
  ]);

  g.scrivi();
});

import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida I1 — «Le persone del laboratorio».
 *
 * ⚠️ Serve l'**Admin**: invitare e cambiare ruolo sono gesti di amministrazione.
 * ⚠️ Il copione SCRIVE (invita una persona): `azzera.sh` prima di ogni giratura.
 */
test('persone ente', async ({ page }) => {
  const g = new Regista(page, 'persone-ente', 'Le persone del laboratorio', 'Invitare, dare un ruolo, togliere accesso');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Chi ha accesso');

  await page.goto('/utenti');
  await page.waitForLoadState('networkidle');

  await g.passo("«Persone» elenca chi ha accesso a questo Ente, con il suo ruolo e il suo stato.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Il ruolo non è un'etichetta: decide che cosa quella persona vede e può fare, quindi questa pagina è anche l'elenco dei permessi.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Invitare qualcuno');

  await g.passo("Si invita per email: non si crea una password per conto d'altri, e non si consegnano credenziali a voce.", {
    su: page.getByRole('button', { name: /Invita/ }).first(),
    zoom: 2.1,
    durata: 8,
  });

  await page.getByRole('button', { name: /Invita/ }).first().click();
  await page.waitForTimeout(900);

  await page.fill('#nome', 'Federico Sala');
  await page.fill('#email', 'federico.sala@aurora.test');

  await g.passo('Nome e indirizzo: il resto lo sceglie la persona quando accetta.', {
    su: page.locator('#email'),
    zoom: 2,
    durata: 8,
  });

  await g.passo("Il ruolo si assegna adesso, ed è la decisione che conta: da lì discende tutto quello che vedrà entrando.", {
    su: page.locator('#ruolo'),
    zoom: 2.1,
    durata: 8,
  });

  await page.locator('#ruolo').selectOption({ index: 1 });
  await page.waitForTimeout(400);

  await g.passo("Alla persona arriva un invito valido sette giorni, con cui sceglierà la propria password.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Cambiare ruolo, togliere accesso');

  await page.goto('/utenti');
  await page.waitForLoadState('networkidle');

  await g.passo("Il ruolo di una persona si cambia in qualsiasi momento, e ha effetto subito.", {
    su: page.locator('tbody tr').first(),
    zoom: 1.8,
    durata: 8,
  });

  await g.passo("«Cestina» toglie l'accesso senza cancellare il passato: gli interventi che quella persona ha registrato restano suoi.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È il gesto giusto quando qualcuno lascia il laboratorio: si toglie l'accesso, non la storia.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "Si invita per email: la password la sceglie la persona, e nessuno la conosce al posto suo.",
    'Il ruolo decide che cosa quella persona vede: è la scelta che conta, e si può correggere in qualsiasi momento.',
    "Cestinare toglie l'accesso e lascia intatto lo storico: il lavoro registrato resta attribuito a chi lo ha fatto.",
  ]);

  g.scrivi();
});

import { expect, test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { artisan } from '../lib/ambiente';

/**
 * Guida A1 — «Il primo accesso».
 *
 * L'invito è una URL FIRMATA che vive solo dentro un'email: nessuna schermata
 * dell'applicazione la produce, quindi il copione se la fa coniare da artisan
 * esattamente come fa la notifica (`InvitoUtente::toMail`).
 */
test('il primo accesso', async ({ page }) => {
  const g = new Regista(
    page,
    'primo-accesso',
    'Il primo accesso',
    "Dall'invito via email alla propria scrivania",
  );

  const invito = artisan(
    'echo \\Illuminate\\Support\\Facades\\URL::temporarySignedRoute("invito.mostra", now()->addDays(7),' +
      ' ["user" => \\App\\Models\\User::where("email","marta.villa@aurora.test")->value("id")]);',
  );

  g.capitolo('Parte 1 di 3', "L'invito arriva per email");

  await page.goto(invito);
  await g.passo(
    "Il link dell'email porta qui: la pagina esiste solo per chi l'ha ricevuto, e scade dopo sette giorni.",
    { durata: 6 },
  );

  await g.passo('Il nome è già quello con cui sei stato aggiunto: non c\'è nulla da registrare.', {
    su: page.getByRole('heading').first(),
    zoom: 2,
  });

  g.capitolo('Parte 2 di 3', 'Scegliere la password');

  await g.passo('La password la decidi tu, e non la conosce nessun altro.', {
    su: page.locator('#password'),
    zoom: 2.4,
  });

  await page.fill('#password', 'Prisma-Vetrina-2026');

  await g.passo('Dev\'essere lunga almeno otto caratteri: sotto, il modulo non la accetta.', {
    su: page.locator('#password'),
    zoom: 2.2,
    durata: 5,
  });

  await g.passo('Si ripete per sicurezza: due campi diversi non lasciano passare un errore di battitura.', {
    su: page.locator('#password_confirmation'),
    zoom: 2.4,
  });

  await page.fill('#password_confirmation', 'Prisma-Vetrina-2026');

  await g.passo('«Imposta la password» chiude l\'invito: da qui in poi il link non funziona più.', {
    su: page.getByRole('button', { name: /imposta la password/i }),
    click: true,
    zoom: 2.6,
    durata: 5,
  });

  // L'invito consumato riporta alla login con un messaggio. Restare sulla
  // pagina dell'invito significherebbe password rifiutata dalle regole di
  // robustezza, e la didascalia sopra racconterebbe una cosa mai avvenuta.
  await page.waitForURL('**/login');
  await expect(page.locator('#email')).toBeVisible();

  g.capitolo('Parte 3 di 3', 'Entrare');

  await g.passo("L'invito è accettato: adesso si entra come si farà tutti i giorni.", { durata: 4.5 });

  await g.passo('La conferma lo dice, ed è questa la pagina da mettere fra i preferiti.', {
    su: page.getByText(/Password impostata/),
    zoom: 2,
    durata: 5.5,
  });

  await page.fill('#email', 'marta.villa@aurora.test');
  await page.fill('#password', 'Prisma-Vetrina-2026');

  await g.passo("L'indirizzo è quello a cui è arrivato l'invito.", {
    su: page.locator('#email'),
    zoom: 2.4,
  });

  await g.passo('E si accede.', {
    su: page.getByRole('button', { name: /accedi/i }),
    click: true,
    zoom: 2.6,
  });

  await page.waitForURL('**/dashboard');

  await g.passo('La Dashboard apre sullo stato delle macchine della tua sede.', { durata: 5.5 });

  await g.passo('In alto a destra ci sono il tuo nome e il tuo ruolo: è il ruolo a decidere che cosa vedi.', {
    su: page.getByRole('banner').getByRole('button', { name: /Marta Villa/ }),
    zoom: 2.4,
    durata: 6,
  });

  g.chiusura('Da ricordare', [
    "Il link dell'invito vale una volta sola e scade dopo sette giorni: se è scaduto, chiedi che te ne rimandino uno.",
    'La password la scegli tu e non la vede nessuno, nemmeno chi ti ha invitato.',
    "Da lì in avanti si entra sempre dalla pagina di accesso, con l'indirizzo a cui è arrivato l'invito.",
  ]);

  g.scrivi();
});

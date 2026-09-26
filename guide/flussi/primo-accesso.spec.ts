import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { artisan } from '../lib/ambiente';

/**
 * Guida A1 — «Il primo accesso», nel formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * L'invito è una URL FIRMATA che vive solo dentro un'email: nessuna schermata
 * dell'applicazione la produce, quindi il copione se la fa coniare da artisan
 * esattamente come fa la notifica (`InvitoUtente::toMail`).
 *
 * ⚠️ Qui più che altrove le cose importanti sono MOVIMENTO, non schermate: una
 * password che si scrive, un modulo che si chiude, una pagina che cambia. Sono
 * riprese vere; restano fermi solo i due dettagli su cui l'occhio deve tornare.
 */
test('il primo accesso', async ({ page }) => {
  const s = new Scenografo(
    page,
    'primo-accesso',
    'Il primo accesso',
    "Dall'invito via email alla propria scrivania",
  );

  const invito = artisan(
    'echo \\Illuminate\\Support\\Facades\\URL::temporarySignedRoute("invito.mostra", now()->addDays(7),' +
      ' ["user" => \\App\\Models\\User::where("email","marta.villa@aurora.test")->value("id")]);',
  );

  await page.goto(invito);
  await s.avvia();

  // ── Uno: l'invito ───────────────────────────────────────────────────────────
  s.cartello("L'invito", 'Parte 1 di 3');

  await s.panoramica(
    "Il collegamento dell'email porta qui. Questa pagina esiste solo per chi l'ha ricevuta, e scade dopo sette giorni.",
    7,
  );

  // ⚠️ `su` è l'ACCOUNT, non il titolo della pagina: la didascalia parla di
  // quello che è già scritto, e l'alone deve illuminare la stessa cosa di cui
  // parlano le parole. Col titolo («Benvenuto su Easy Lab») si accendeva un
  // pezzo di schermo che con la frase non c'entrava niente.
  await s.scatto("L'account è già scritto: è l'indirizzo a cui è arrivato l'invito. Non c'è niente da registrare.", {
    su: page.getByText('marta.villa@aurora.test'),
    zoom: 1.8,
    durata: 6.5,
  });

  // ── Due: la password ────────────────────────────────────────────────────────
  s.cartello('La password', 'Parte 2 di 3');

  await s.movimento(
    'La password la decidi tu, e non la conosce nessun altro: nemmeno chi ti ha invitato.',
    async () => {
      await page.locator('#password').click();
      await page.locator('#password').pressSequentially('Prisma-Vetrina-2026', { delay: 95 });
      await page.waitForTimeout(500);
    },
    { su: page.locator('#password'), zoom: 1.2, coda: 0.9, suono: 'tastiera' },
  );

  await s.scatto("Deve essere lunga almeno otto caratteri: sotto, il modulo non l'accetta.", {
    su: page.locator('#password'),
    zoom: 1.9,
    durata: 5.5,
  });

  await s.movimento(
    'Si ripete per sicurezza: due campi diversi non lasciano passare un errore di battitura.',
    async () => {
      await page.locator('#password_confirmation').click();
      await page.locator('#password_confirmation').pressSequentially('Prisma-Vetrina-2026', { delay: 95 });
      await page.waitForTimeout(500);
    },
    { su: page.locator('#password_confirmation'), zoom: 1.2, coda: 0.9, suono: 'tastiera' },
  );

  await s.movimento(
    "«Imposta la password» chiude l'invito: da qui in avanti quel collegamento non funziona più.",
    async () => {
      await page.getByRole('button', { name: /imposta la password/i }).click();
      // L'invito consumato riporta alla pagina di accesso con un messaggio.
      // Restare sull'invito vorrebbe dire password rifiutata dalle regole di
      // robustezza, e la didascalia racconterebbe una cosa mai avvenuta.
      await page.waitForURL('**/login');
      await expect(page.locator('#email')).toBeVisible();
    },
    { su: page.getByRole('button', { name: /imposta la password/i }), zoom: 1.4, coda: 1.2 },
  );

  // ── Tre: entrare ────────────────────────────────────────────────────────────
  s.cartello('Entrare', 'Parte 3 di 3');

  await s.scatto('La conferma lo dice: da adesso si entra da questa pagina, ed è quella da mettere fra i preferiti.', {
    su: page.getByText(/Password impostata/),
    zoom: 1.7,
    durata: 7,
  });

  await s.movimento(
    "L'indirizzo è quello a cui è arrivato l'invito, e la password è quella appena scelta.",
    async () => {
      await page.locator('#email').click();
      await page.locator('#email').pressSequentially('marta.villa@aurora.test', { delay: 70 });
      await page.fill('#password', 'Prisma-Vetrina-2026');
      await page.waitForTimeout(400);
    },
    { su: page.locator('#email'), zoom: 1.2, coda: 0.8, suono: 'tastiera' },
  );

  await s.movimento(
    'E si entra.',
    async () => {
      await page.getByRole('button', { name: /accedi/i }).click();
      await page.waitForURL('**/dashboard');
      await page.waitForTimeout(700);
    },
    { su: page.getByRole('button', { name: /accedi/i }), zoom: 1.4, coda: 1.3, durata: 4 },
  );

  /*
   * ⚠️ La Dashboard di Marta è VUOTA, ed è giusto così: è una Tecnica appena
   * creata, e senza reparti assegnati non vede nessuna macchina (ADR-018, lo
   * scoping è fail-closed). La didascalia diceva «apre sullo stato delle
   * macchine della tua sede» sopra una pagina che diceva «Nessuno strumento
   * visibile»: una bugia a schermo. Si racconta quel che si vede — che è anche
   * la cosa più utile da sapere al primo accesso.
   */
  await s.panoramica(
    'La Dashboard è la tua scrivania. Al primo accesso può essere vuota: si riempie coi reparti che ti vengono assegnati.',
    7,
  );

  await s.scatto('In alto a destra ci sono il tuo nome e il tuo ruolo: è il ruolo a decidere che cosa vedi.', {
    su: page.getByRole('banner').getByRole('button', { name: /Marta Villa/ }),
    zoom: 2,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    "Il collegamento dell'invito vale una volta sola e scade dopo sette giorni: se è scaduto, chiedi che te ne rimandino uno.",
    'La password la scegli tu e non la vede nessuno, nemmeno chi ti ha invitato.',
    "Da lì in avanti si entra sempre dalla pagina di accesso, con l'indirizzo a cui è arrivato l'invito.",
  ]);

  await s.scrivi();
});

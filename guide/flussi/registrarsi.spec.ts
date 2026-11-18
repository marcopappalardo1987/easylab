import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { artisan } from '../lib/ambiente';

/**
 * Guida L1 — «Aprire un account Easy Lab». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stesso numero di passi del copione precedente** (10, in tre capitoli da
 * 4, 3 e 3): i testi scritti in `testi/registrarsi.md` sono numerati come i
 * passi. La STRADA però è cambiata (ADR-012): la conferma dell'email ora viene
 * PRIMA del pagamento, non dopo. Il secondo e il terzo capitolo raccontano
 * quindi la conferma e la pagina che porta a Stripe, e i testi scritti di
 * quei passi sono stati riscritti.
 *
 * ⚠️ È la sola guida che comincia da **non autenticati**.
 *
 * ⛔ Il copione si ferma prima di Stripe: «Vai al pagamento» apre una sessione
 * di checkout, cioè crea un oggetto su un servizio esterno. Non si preme.
 *
 * ⚠️ Il collegamento di conferma vive solo nell'email: il copione se lo fa
 * coniare da artisan esattamente come `VerificaEmailRegistrazione::toMail`.
 *
 * ⚠️ Il copione SCRIVE (una riga di `registrazioni`): `azzera.sh` prima di
 * ogni giratura. La pagina esiste solo con `REGISTRAZIONE_APERTA` vera, che
 * `bin/demo-env.sh` accende per l'ambiente dimostrativo.
 */
const EMAIL = 'elena.bianchi@analisibianchi.example';

test('registrarsi', async ({ page }) => {
  const s = new Scenografo(page, 'registrarsi', 'Aprire un account Easy Lab', 'Dal modulo pubblico alla propria sede attiva');

  await page.goto('/registrati');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: il modulo ──────────────────────────────────────────────────────────
  s.cartello('Il modulo', 'Parte 1 di 3');

  await s.scatto("Chi non ha ancora Easy Lab parte da qui: è l'unica pagina che si apre senza un invito.", {
    su: page.getByRole('heading', { name: 'Crea il tuo account', exact: true }),
    zoom: 1.8,
    durata: 6.5,
  });

  await s.movimento(
    "Il nome del laboratorio diventerà la tua prima sede, ed è quello che i colleghi vedranno in alto a sinistra.",
    async () => {
      await page.locator('#nome_ente').click();
      await page.locator('#nome_ente').pressSequentially('Laboratorio Analisi Bianchi', { delay: 75 });
      await page.waitForTimeout(400);
    },
    { su: page.locator('#nome_ente'), zoom: 1.3, coda: 0.9, suono: 'tastiera' },
  );

  await s.movimento(
    'Nome e indirizzo del referente: sarà il primo Amministratore, cioè chi potrà invitare gli altri.',
    async () => {
      await page.locator('#nome_referente').click();
      await page.locator('#nome_referente').pressSequentially('Elena Bianchi', { delay: 80 });
      await page.locator('#email').click();
      await page.locator('#email').pressSequentially(EMAIL, { delay: 45 });
      await page.waitForTimeout(400);
    },
    { su: page.locator('#nome_referente'), zoom: 1.3, coda: 0.9, suono: 'tastiera' },
  );

  await s.movimento(
    "La password la scegli adesso, e vale da subito: qui non c'è un invito da accettare.",
    async () => {
      await page.locator('#password').click();
      await page.locator('#password').pressSequentially('Bianchi-Analisi-2026', { delay: 70 });
      await page.locator('#password_confirmation').click();
      await page.locator('#password_confirmation').pressSequentially('Bianchi-Analisi-2026', { delay: 70 });
      await page.waitForTimeout(400);
    },
    { su: page.locator('#password'), zoom: 1.3, coda: 0.9, suono: 'tastiera' },
  );

  // ── Due: il piano e la conferma ─────────────────────────────────────────────
  s.cartello('Il piano e la conferma', 'Parte 2 di 3');

  const piano = page.locator('label', { has: page.locator('input[type=radio]') }).first();
  await expect(piano.locator('input[type=radio]')).toBeChecked();

  await s.scatto('Il piano si sceglie prima di pagare, e dice quante sedi e quanti strumenti potrai avere.', {
    su: piano,
    zoom: 1.9,
    durata: 6.5,
  });

  const continua = page.getByRole('button', { name: 'Continua' });

  await s.movimento(
    "«Continua» non apre ancora il pagamento: prima arriva un'email, per confermare che l'indirizzo sia davvero tuo.",
    async () => {
      await continua.click();
      await page.waitForURL('**/registrati/controlla-email');
      await expect(page.getByRole('heading', { name: 'Controlla la posta' })).toBeVisible();
      await expect(page.getByText(EMAIL)).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: continua, zoom: 1.3, coda: 1.6 },
  );

  const conferma = artisan(
    `$r = \\App\\Models\\Registrazione::where("email","${EMAIL}")->firstOrFail();` +
      ' echo \\Illuminate\\Support\\Facades\\URL::temporarySignedRoute("registrazione.verifica", now()->addHours(24),' +
      ' ["registrazione" => $r->getKey(), "impronta" => $r->improntaVerifica()]);',
  );

  await s.movimento(
    "Il collegamento dell'email conferma l'indirizzo e porta all'ultimo passo: il riepilogo prima del pagamento.",
    async () => {
      await page.goto(conferma);
      await page.waitForURL('**/pagamento**');
      await expect(page.getByRole('heading', { name: 'Indirizzo confermato' })).toBeVisible();
      await page.waitForTimeout(700);
    },
    { coda: 1.6, durata: 6.5 },
  );

  // ── Tre: il pagamento ───────────────────────────────────────────────────────
  s.cartello('Il pagamento', 'Parte 3 di 3');

  const paga = page.getByRole('button', { name: 'Vai al pagamento' });

  await s.scatto('«Vai al pagamento» porta su Stripe, non qui: i dati della carta non passano mai da Easy Lab.', {
    su: paga,
    zoom: 1.9,
    durata: 7,
  });

  await s.scatto("L'account nasce quando il pagamento va a buon fine: si torna indietro da soli, e non c'è nessuno da aspettare.", {
    su: page.getByText(/Manca solo il pagamento/),
    zoom: 2,
    durata: 7.5,
  });

  await s.scatto('Dentro, la sede sarà vuota: prima i laboratori, poi le macchine, poi i colleghi da «Persone», ciascuno col suo ruolo.', {
    su: page.locator('dl'),
    zoom: 1.8,
    alone: false,
    durata: 8,
  });

  s.chiusura('Da ricordare', [
    "È l'unica pagina che si apre senza invito: chi ha già un'organizzazione su Easy Lab deve farsi invitare, non registrarsi di nuovo.",
    "Prima si conferma l'email, poi si paga: l'account nasce solo a pagamento riuscito.",
    'Il pagamento sta su Stripe: Easy Lab non vede né conserva i dati della carta.',
  ]);

  await s.scrivi();
});

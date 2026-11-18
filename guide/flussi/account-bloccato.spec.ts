import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { superaIl2FA } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida K2 — «Quando l'accesso è sospeso». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (8, in
 * tre capitoli da 3, 2 e 3): i testi scritti in `testi/account-bloccato.md`
 * sono numerati come i passi.
 *
 * ⚠️ La sospensione va MESSA prima di girare: nessuna schermata la produce.
 * È quella **per insoluto** (`stripe_locked_at`, ADR-013), la sola che si
 * riapre da sé a pagamento registrato: il blocco manuale della console no, e la
 * pagina «Abbonamento» lo dice. La guida di prima scriveva `locked_at`, cioè il
 * blocco manuale, mentre raccontava la riapertura automatica.
 *
 * Si scrive con `forceFill` e non con `bloccaPerStripe()`: quello manda anche
 * l'email «accesso sospeso» a tutti i membri, e una guida non spedisce posta.
 *
 * ⚠️ Il copione SCRIVE nel DB dimostrativo: `azzera.sh` prima di ogni giratura.
 */
test('account bloccato', async ({ page }) => {
  const s = new Scenografo(page, 'account-bloccato', "Quando l'accesso è sospeso", 'Che cosa si vede, e come si torna a lavorare');

  artisan(
    '$a = \\App\\Models\\Account::whereHas("membri", fn($q) => $q->where("email","maria.conti@aurora.test"))->first();' +
      ' $a->forceFill(["is_locked" => true, "stripe_locked_at" => now(), "stripe_lock_reason" => "Fattura 2026/114 non saldata"])->save();' +
      ' echo "bloccato";',
  );

  // ⚠️ Non si usa `accedi()`: quello aspetta la dashboard, e un account sospeso
  // sulla dashboard non ci arriva mai.
  await page.goto('/login');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: che cosa succede ───────────────────────────────────────────────────
  s.cartello('Che cosa succede', 'Parte 1 di 3');

  const accedere = page.getByRole('button', { name: /accedi/i });

  await s.movimento(
    "Quando un pagamento resta insoluto, l'accesso alla sede viene sospeso: non si perde niente, si smette di poter entrare.",
    async () => {
      await page.locator('#email').click();
      await page.locator('#email').pressSequentially('maria.conti@aurora.test', { delay: 45 });
      await page.fill('#password', 'guida-demo');
      await accedere.click();
      await superaIl2FA(page);
      await page.waitForURL('**/bloccato');
      await expect(page.getByRole('heading', { name: 'Accesso sospeso' })).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: page.locator('#email'), zoom: 1.2, coda: 1.4, suono: 'tastiera' },
  );

  await s.movimento(
    "Qualunque pagina si provi ad aprire porta qui: la sospensione riguarda l'organizzazione, non il tuo account.",
    async () => {
      await page.goto('/strumenti');
      await page.waitForURL('**/bloccato');
      await expect(page.getByRole('heading', { name: 'Accesso sospeso' })).toBeVisible();
      await page.waitForTimeout(700);
    },
    { coda: 1.4, durata: 7 },
  );

  await s.scatto('La pagina dice quale sede è sospesa. Il motivo dettagliato non compare: è una questione fra chi amministra e Easy Lab.', {
    su: page.getByText(/è temporaneamente sospeso/),
    zoom: 2,
    durata: 7.5,
  });
  await expect(page.getByText('Fattura 2026/114')).toHaveCount(0);

  // ── Due: i dati non si toccano ──────────────────────────────────────────────
  s.cartello('I dati non si toccano', 'Parte 2 di 3');

  const dati = page.getByText(/I dati non sono stati toccati/);

  await s.scatto('Le macchine, gli interventi, i documenti restano dove sono: la sospensione chiude la porta, non svuota la stanza.', {
    su: dati,
    zoom: 2,
    durata: 7.5,
  });

  await s.panoramica("Nemmeno le email si fermano per sempre: riprendono quando l'accesso riprende.", 6);

  // ── Tre: come si esce ───────────────────────────────────────────────────────
  s.cartello('Come si esce', 'Parte 3 di 3');

  const altreSedi = page.getByText('Le tue altre sedi');
  await expect(altreSedi).toHaveCount(0);

  await s.scatto("La sospensione vale per tutte le sedi del contratto. Chi lavora anche per un altro cliente trova qui le sue sedi ancora aperte.", {
    su: page.locator('div.rounded-lg.border').filter({ has: dati }),
    zoom: 1.5,
    alone: false,
    durata: 8,
  });

  const abbonamento = page.getByRole('link', { name: 'Vai alla pagina Abbonamento' });

  await s.movimento(
    "Per riaprire si salda l'insoluto: chi amministra lo fa dalla pagina «Abbonamento», che resta raggiungibile.",
    async () => {
      await abbonamento.click();
      await page.waitForURL('**/abbonamento');
      await expect(page.getByText("L'accesso è sospeso")).toBeVisible();
      await page.waitForTimeout(800);
    },
    { su: abbonamento, zoom: 1.4, coda: 1.4 },
  );

  await s.scatto("A pagamento registrato l'accesso torna da solo, quando Stripe conferma l'incasso: senza che nessuno debba chiedere niente.", {
    su: page.getByText("L'accesso è sospeso").locator('..'),
    zoom: 1.6,
    durata: 8,
  });

  s.chiusura('Da ricordare', [
    "La sospensione riguarda l'organizzazione, non il tuo account: cambia chi può entrare, non che cosa c'è dentro.",
    'I dati restano intatti e tornano visibili appena la sospensione si chiude.',
    "Si esce saldando dalla pagina «Abbonamento», e la riapertura è automatica quando Stripe conferma l'incasso.",
  ]);

  await s.scrivi();
});

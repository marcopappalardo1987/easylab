import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';
import { codice2FA } from '../lib/totp';

/**
 * Guida A4 — «La verifica in due passaggi». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (12, in
 * tre capitoli da 3, 5 e 4): i testi scritti in `testi/due-fattori.md` sono
 * numerati come i passi.
 *
 * ⚠️ Girata con **Giulia (Responsabile Reparto)** e non con l'Admin: il ruolo
 * che la 2FA ce l'ha già obbligatoria non può mostrare il gesto di attivarla —
 * la sua pagina apre sullo stato «attivo». Serve qualcuno a cui `config/rbac.php`
 * NON la impone, che la accende perché vuole.
 *
 * ⚠️ Il codice non è finto: si legge dalla pagina la chiave che l'applicazione
 * ha appena generato e ci si calcola sopra il TOTP, come farebbe il telefono.
 */
test('due fattori', async ({ page }) => {
  const s = new Scenografo(page, 'due-fattori', 'La verifica in due passaggi', 'Un codice dal telefono, oltre alla password');

  await accedi(page, 'giulia.ferrari@aurora.test');
  await s.avvia();

  // ── Uno: dove si attiva ─────────────────────────────────────────────────────
  s.cartello('Dove si attiva', 'Parte 1 di 3');

  const nome = page.getByRole('banner').getByRole('button', { name: /Giulia Ferrari/ });

  await s.movimento(
    'La sicurezza del proprio account sta nel menù col tuo nome, sotto «Sicurezza».',
    async () => {
      await nome.click();
      const voce = page.getByRole('banner').getByText('Sicurezza', { exact: true });
      await expect(voce).toBeVisible();
      await page.waitForTimeout(600);
      await voce.click();
      await page.waitForURL('**/settings/security');
      await expect(page.getByRole('heading', { name: 'Sicurezza' })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: nome, zoom: 1.2, coda: 1.2 },
  );

  await s.scatto('Finché non la attivi, il riquadro dice «Non attivo».', {
    su: page.getByText('Non attivo'),
    zoom: 2.6,
    durata: 5,
  });

  const abilita = page.getByRole('button', { name: 'Abilita 2FA' });

  await s.movimento(
    'Si comincia da qui.',
    async () => {
      await abilita.click();
      await expect(page.locator('#code')).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: abilita, zoom: 1.6, coda: 1.2 },
  );

  // ── Due: il telefono e il codice ────────────────────────────────────────────
  s.cartello('Il telefono e il codice', 'Parte 2 di 3');

  await s.scatto("Compare un QR: si inquadra con l'app di autenticazione, quella che genera i codici a sei cifre.", {
    su: page.locator('main svg').first(),
    zoom: 1.9,
    durata: 7,
  });

  await s.scatto('Se la fotocamera non collabora, la stessa chiave si può digitare a mano.', {
    su: page.locator('code').first(),
    zoom: 2.6,
    durata: 6,
  });

  // La chiave che l'applicazione ha appena coniato: il codice si calcola su
  // quella, non sul segreto del seme, che è di un altro utente.
  const chiave = (await page.locator('code').first().innerText()).trim();
  const campo = page.locator('#code');

  await s.scatto("L'app mostra subito un codice, e ne mostra uno nuovo ogni trenta secondi.", {
    su: campo,
    zoom: 2.4,
    durata: 6,
  });

  await s.movimento(
    'Si trascrive quello che si vede in quel momento.',
    async () => {
      await campo.click();
      // ⚠️ Calcolato qui e non prima: la finestra TOTP dura trenta secondi.
      await campo.pressSequentially(codice2FA(chiave), { delay: 150 });
      await page.waitForTimeout(400);
    },
    { su: campo, zoom: 2, coda: 0.8, suono: 'tastiera' },
  );

  const conferma = page.getByRole('button', { name: 'Conferma e attiva' });

  await s.movimento(
    '«Conferma e attiva» chiude il giro: da adesso la protezione è accesa.',
    async () => {
      await conferma.click();
      await expect(page.getByText(/Conserva questi codici/)).toBeVisible({ timeout: 10_000 });
      await page.waitForTimeout(600);
    },
    { su: conferma, zoom: 1.6, coda: 1.4 },
  );

  // ── Tre: i codici di recupero ───────────────────────────────────────────────
  s.cartello('I codici di recupero', 'Parte 3 di 3');

  await s.scatto('Il riquadro ora dice «Attivo», e la password da sola non basta più per entrare.', {
    su: page.getByText('Attivo').first(),
    zoom: 2.6,
    durata: 6,
  });

  await s.scatto("Insieme alla conferma arrivano i codici di recupero: servono il giorno in cui il telefono non c'è.", {
    su: page.getByText(/Conserva questi codici/),
    zoom: 1.8,
    durata: 7,
  });

  await s.scatto('Si conservano fuori dal telefono, e ognuno vale una volta sola.', {
    su: page.locator('div.grid.font-mono'),
    zoom: 1.9,
    durata: 6,
  });

  await s.scatto('Se li perdi o li usi tutti, se ne generano di nuovi: i vecchi smettono di funzionare.', {
    su: page.getByRole('button', { name: 'Rigenera codici di recupero' }),
    zoom: 2.2,
    durata: 6,
  });

  s.chiusura('Da ricordare', [
    'La 2FA aggiunge un codice temporaneo alla password: chi ruba la password, da solo, non entra.',
    "Il codice lo genera l'app sul telefono e cambia ogni trenta secondi: non si conserva e non si condivide.",
    "I codici di recupero sono la via d'uscita se il telefono si perde: si tengono altrove, e valgono una volta sola.",
  ]);

  await s.scrivi();
});

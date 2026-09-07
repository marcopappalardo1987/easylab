import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';
import { codice2FA } from '../lib/totp';

/**
 * Guida A4 — «La verifica in due passaggi».
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
  const g = new Regista(page, 'due-fattori', 'La verifica in due passaggi', 'Un codice dal telefono, oltre alla password');

  await accedi(page, 'giulia.ferrari@aurora.test');

  g.capitolo('Parte 1 di 3', 'Dove si attiva');

  await page.goto('/settings/security');
  await page.waitForLoadState('networkidle');

  await g.passo('La sicurezza del proprio account sta nelle impostazioni, sotto «Sicurezza».', {
    su: page.getByRole('heading', { name: 'Sicurezza' }),
    zoom: 1.8,
    durata: 5,
  });

  await g.passo('Finché non la attivi, il riquadro dice «Non attivo».', {
    su: page.getByText('Non attivo'),
    zoom: 2.6,
    durata: 5,
  });

  await g.passo('Si comincia da qui.', {
    su: page.getByRole('button', { name: 'Abilita 2FA' }),
    click: true,
    zoom: 2.4,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 2 di 3', 'Il telefono e il codice');

  await g.passo("Compare un QR: si inquadra con l'app di autenticazione, quella che genera i codici a sei cifre.", {
    su: page.locator('svg').first(),
    zoom: 1.9,
    durata: 7,
  });

  await g.passo('Se la fotocamera non collabora, la stessa chiave si può digitare a mano.', {
    su: page.locator('code').first(),
    zoom: 2.6,
    durata: 6,
  });

  // La chiave che l'applicazione ha appena coniato: il codice si calcola su
  // quella, non sul segreto del seme, che è di un altro utente.
  const chiave = (await page.locator('code').first().innerText()).trim();

  await g.passo("L'app mostra subito un codice, e ne mostra uno nuovo ogni trenta secondi.", {
    su: page.locator('#code'),
    zoom: 2.4,
    durata: 6,
  });

  await page.fill('#code', codice2FA(chiave));

  await g.passo('Si trascrive quello che si vede in quel momento.', {
    su: page.locator('#code'),
    zoom: 2.6,
    durata: 5,
  });

  await g.passo('«Conferma e attiva» chiude il giro: da adesso la protezione è accesa.', {
    su: page.getByRole('button', { name: 'Conferma e attiva' }),
    click: true,
    zoom: 2.2,
  });

  await page.waitForLoadState('networkidle');

  g.capitolo('Parte 3 di 3', 'I codici di recupero');

  await g.passo('Il riquadro ora dice «Attivo», e la password da sola non basta più per entrare.', {
    su: page.getByText('Attivo').first(),
    zoom: 2.6,
    durata: 6,
  });

  await g.passo('Insieme alla conferma arrivano i codici di recupero: servono il giorno in cui il telefono non c\'è.', {
    su: page.getByText(/Conserva questi codici/),
    zoom: 1.8,
    durata: 7,
  });

  await g.passo('Si conservano fuori dal telefono, e ognuno vale una volta sola.', {
    su: page.locator('.font-mono').first(),
    zoom: 1.9,
    durata: 6,
  });

  await g.passo('Se li perdi o li usi tutti, se ne generano di nuovi: i vecchi smettono di funzionare.', {
    su: page.getByRole('button', { name: 'Rigenera codici di recupero' }),
    zoom: 2.2,
    durata: 6,
  });

  g.chiusura('Da ricordare', [
    'La 2FA aggiunge un codice temporaneo alla password: chi ruba la password, da solo, non entra.',
    'Il codice lo genera l\'app sul telefono e cambia ogni trenta secondi: non si conserva e non si condivide.',
    'I codici di recupero sono la via d\'uscita se il telefono si perde: si tengono altrove, e valgono una volta sola.',
  ]);

  g.scrivi();
});

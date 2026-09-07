import type { Page } from '@playwright/test';
import { codice2FA } from './totp';

/**
 * Entra nell'applicazione dimostrativa, superando la verifica in due passaggi
 * quando il ruolo la richiede.
 *
 * ⚠️ `config/rbac.php` la impone a Developer, Superadmin e **Admin**: un
 * copione che si limitasse a compilare email e password finirebbe fermo sulla
 * schermata del codice, e il primo scatto ritrarrebbe quella. Il codice lo
 * calcola `totp.ts` dal segreto piantato nel seme (`bin/pianta-2fa.sh`).
 *
 * ⚠️ **Il codice si calcola DOPO il submit**, non prima: la finestra TOTP dura
 * trenta secondi, e fra il caricamento della pagina di login e l'arrivo della
 * challenge può cambiare. Calcolarlo in anticipo dà un codice scaduto una volta
 * ogni tanto, cioè un copione che fallisce a caso.
 */
export const accedi = async (page: Page, email: string, password = 'guida-demo'): Promise<void> => {
  await page.goto('/login');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('button[type=submit]');

  await superaIl2FA(page);

  await page.waitForURL('**/dashboard');
};

/**
 * Compila la challenge, se è comparsa. Sta a parte perché i copioni che
 * **filmano** il login fanno da sé i propri passi e poi chiamano solo questa.
 */
export const superaIl2FA = async (page: Page): Promise<void> => {
  await page.waitForLoadState('networkidle');

  if (!page.url().includes('two-factor-challenge')) {
    return;
  }

  await page.fill('#code', codice2FA());
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
};

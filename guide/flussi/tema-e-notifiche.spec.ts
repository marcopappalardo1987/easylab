import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';

/**
 * Guida A3 — «Tema e notifiche». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (13, in
 * tre capitoli da 5, 5 e 3): i testi scritti in `testi/tema-e-notifiche.md` sono
 * numerati come i passi.
 *
 * ⚠️ Il tema è salvato sull'ACCOUNT, non nel browser: il copione lo rimette
 * chiaro in chiusura, dalla scheda «Tema» delle preferenze.
 */
test('tema e notifiche', async ({ page }) => {
  const s = new Scenografo(page, 'tema-e-notifiche', 'Tema e notifiche', 'Come vuoi vedere Easy Lab, e che cosa vuoi ricevere');

  // ⚠️ I pulsanti del tema stanno sia nel menù in alto sia nella pagina delle
  // preferenze: senza restringere, Playwright ne trova due e si ferma.
  const barra = page.getByRole('banner');
  const nome = barra.getByRole('button', { name: /Giulia Ferrari/ });

  await page.goto('/login');
  await page.fill('#email', 'giulia.ferrari@aurora.test');
  await page.fill('#password', 'guida-demo');
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');
  await s.avvia();

  // ── Uno: il tema ────────────────────────────────────────────────────────────
  s.cartello('Chiaro, scuro, o come il computer', 'Parte 1 di 3');

  await s.movimento(
    'Il tema si sceglie dal menù col tuo nome, in alto a destra.',
    async () => {
      await nome.click();
      await expect(barra.getByRole('button', { name: 'Tema scuro' })).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: nome, zoom: 2, coda: 1 },
  );

  const scuro = barra.getByRole('button', { name: 'Tema scuro' });

  await s.scatto('Tre possibilità: chiaro, scuro, o «di sistema», che segue il tuo dispositivo.', {
    su: scuro.locator('..'),
    zoom: 2.4,
    alone: false,
    durata: 6,
  });

  await s.movimento(
    'Si prova: «Tema scuro».',
    async () => {
      await scuro.click();
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
      await page.waitForTimeout(700);
    },
    { su: scuro, zoom: 2.2, coda: 1.2 },
  );

  // Il menù si chiude con un clic fuori, prima dello scatto d'insieme.
  await page.locator('main').click({ position: { x: 5, y: 5 } });
  await page.waitForTimeout(400);

  await s.scatto('Cambia subito, senza salvare nulla.', { durata: 4.5 });

  const preferenze = barra.getByText('Preferenze', { exact: true });

  await s.movimento(
    'La scelta viaggia con il tuo account: la ritrovi anche da un altro computer.',
    async () => {
      await nome.click();
      await expect(preferenze).toBeVisible();
      await page.waitForTimeout(500);
      await preferenze.click();
      await page.waitForURL('**/settings/notifiche');
      await expect(page.getByRole('heading', { name: 'Tema' })).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: nome, zoom: 1, coda: 1.4 },
  );

  // ── Due: l'email delle scadenze ─────────────────────────────────────────────
  s.cartello("L'email delle scadenze", 'Parte 2 di 3');

  await s.scatto("Nelle preferenze c'è un solo interruttore, e riguarda le email.", {
    su: page.getByRole('heading', { name: 'Riepilogo email delle scadenze' }).locator('xpath=ancestor::div[.//button[normalize-space()="Salva"]][1]'),
    zoom: 1.4,
    durata: 5,
  });

  const interruttore = page.getByRole('switch', { name: /Riepilogo email/ });

  await s.scatto("Acceso, ricevi un'email al giorno con le scadenze appena superate e quelle dei prossimi trenta giorni.", {
    su: interruttore,
    zoom: 2.4,
    durata: 7,
  });

  await s.scatto('Nei giorni in cui non cambia nulla, non arriva niente.', {
    su: page.getByText(/Nessuna email nei giorni/),
    zoom: 1.9,
    durata: 5,
  });

  await s.movimento(
    'Si spegne con un clic.',
    async () => {
      await interruttore.click();
      await expect(interruttore).toHaveAttribute('aria-checked', 'false');
      await page.waitForTimeout(500);
    },
    { su: interruttore, zoom: 2.4, coda: 1 },
  );

  const salva = page.getByRole('button', { name: 'Salva' });

  await s.movimento(
    'E si salva.',
    async () => {
      await salva.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(700);
    },
    { su: salva, zoom: 2, coda: 1.2 },
  );

  // ── Tre: quello che resta comunque ──────────────────────────────────────────
  s.cartello('Quello che resta comunque', 'Parte 3 di 3');

  await s.scatto("Le notifiche dentro l'applicazione non si spengono: sono la copia di ciò che vedi comunque entrando, e non lasciano Easy Lab.", {
    su: page.getByText(/restano sempre attive/),
    zoom: 1.8,
    durata: 8,
  });

  await s.scatto("Spegnere l'email non ti fa perdere niente: cambia solo dove lo leggi, qui nella campanella.", {
    su: barra.getByRole('button', { name: 'Notifiche' }),
    zoom: 2.2,
    durata: 6,
  });

  const chiaro = page.getByRole('main').getByRole('button', { name: 'Tema chiaro' });

  await s.movimento(
    'Si torna al tema chiaro quando si vuole: la scelta non è mai definitiva.',
    async () => {
      await chiaro.click();
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
      await page.waitForTimeout(800);
    },
    { su: chiaro, zoom: 1.5, coda: 1.2 },
  );

  // Dopo il salvataggio l'interruttore resta spento: la scelta è stata registrata.
  await page.reload();
  await expect(page.getByRole('switch', { name: /Riepilogo email/ })).toHaveAttribute('aria-checked', 'false');

  s.chiusura('Da ricordare', [
    'Il tema si applica subito e viaggia con il tuo account, non con il browser.',
    "L'interruttore delle preferenze riguarda solo le email: una al giorno, e nessuna quando non cambia nulla.",
    'Le notifiche in applicazione restano sempre attive, e non escono da Easy Lab.',
  ]);

  await s.scrivi();
});

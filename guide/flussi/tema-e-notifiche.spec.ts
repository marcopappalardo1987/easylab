import { expect, test } from '@playwright/test';
import { Regista } from '../lib/regista';

/**
 * Guida A3 — «Tema e notifiche».
 *
 * ⚠️ Il tema è salvato sull'ACCOUNT, non nel browser: il copione lo rimette
 * chiaro in chiusura, o l'ultimo scatto lascerebbe l'applicazione scura e le
 * guide girate dopo, sullo stesso seme, partirebbero da lì.
 */
test('tema e notifiche', async ({ page }) => {
  const g = new Regista(page, 'tema-e-notifiche', 'Tema e notifiche', 'Come vuoi vedere Easy Lab, e che cosa vuoi ricevere');

  // ⚠️ I pulsanti del tema stanno sia nel menù in alto sia nella pagina delle
  // preferenze: senza restringere alla barra, Playwright ne trova due e si ferma.
  const barra = page.getByRole('banner');

  await page.goto('/login');
  await page.fill('#email', 'giulia.ferrari@aurora.test');
  await page.fill('#password', 'guida-demo');
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');

  g.capitolo('Parte 1 di 3', 'Chiaro, scuro, o come il computer');

  await g.passo('Il tema si sceglie dal menù col tuo nome, in alto a destra.', {
    su: barra.getByRole('button', { name: /Giulia Ferrari/ }),
    click: true,
    zoom: 2.6,
  });

  await page.waitForTimeout(500);

  await g.passo('Tre possibilità: chiaro, scuro, o «di sistema», che segue il tuo dispositivo.', {
    su: barra.getByRole('button', { name: 'Tema scuro' }),
    zoom: 2.4,
    durata: 6,
  });

  await g.passo('Si prova: «Tema scuro».', {
    su: barra.getByRole('button', { name: 'Tema scuro' }),
    click: true,
    zoom: 2.8,
  });

  await page.waitForTimeout(900);

  await g.passo('Cambia subito, senza salvare nulla.', { durata: 4.5 });

  await g.passo('La scelta viaggia con il tuo account: la ritrovi anche da un altro computer.', {
    durata: 6,
  });

  g.capitolo('Parte 2 di 3', "L'email delle scadenze");

  await page.goto('/settings/notifiche');
  await page.waitForLoadState('networkidle');

  await g.passo('Nelle preferenze c\'è un solo interruttore, e riguarda le email.', { durata: 5 });

  const interruttore = page.getByRole('switch', { name: /Riepilogo email/ });

  await g.passo('Acceso, ricevi un\'email al giorno con le scadenze appena superate e quelle dei prossimi trenta giorni.', {
    su: interruttore,
    zoom: 2.4,
    durata: 7,
  });

  await g.passo('Nei giorni in cui non cambia nulla, non arriva niente.', {
    su: page.getByText(/Nessuna email nei giorni/),
    zoom: 1.9,
    durata: 5,
  });

  await g.passo('Si spegne con un clic.', {
    su: interruttore,
    click: true,
    zoom: 2.8,
  });

  await page.waitForTimeout(500);
  await expect(interruttore).toHaveAttribute('aria-checked', 'false');

  await g.passo('E si salva.', {
    su: page.getByRole('button', { name: 'Salva' }),
    click: true,
    zoom: 2.8,
  });

  await page.waitForTimeout(900);

  g.capitolo('Parte 3 di 3', 'Quello che resta comunque');

  await g.passo('Le notifiche dentro l\'applicazione non si spengono: sono la copia di ciò che vedi comunque entrando, e non lasciano Easy Lab.', {
    su: page.getByText(/restano sempre attive/),
    zoom: 1.8,
    durata: 8,
  });

  await g.passo('Spegnere l\'email non ti fa perdere niente: cambia solo dove lo leggi.', { durata: 5.5 });

  // Il tema torna chiaro: è sull'account, e resterebbe scuro per le guide successive.
  await barra.getByRole('button', { name: /Giulia Ferrari/ }).click();
  await page.waitForTimeout(400);
  await barra.getByRole('button', { name: 'Tema chiaro' }).click();
  await page.waitForTimeout(900);

  await g.passo('Si torna al tema chiaro quando si vuole: la scelta non è mai definitiva.', { durata: 5 });

  g.chiusura('Da ricordare', [
    'Il tema si applica subito e viaggia con il tuo account, non con il browser.',
    "L'interruttore delle preferenze riguarda solo le email: una al giorno, e nessuna quando non cambia nulla.",
    'Le notifiche in applicazione restano sempre attive, e non escono da Easy Lab.',
  ]);

  g.scrivi();
});

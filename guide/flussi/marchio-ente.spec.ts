import { test } from '@playwright/test';
import path from 'node:path';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida B3 — «Il marchio delle email».
 *
 * ⚠️ Serve l'**Admin**: la pagina sta dietro `unita_organizzativa.update`.
 *
 * ⚠️ Il logo caricato è il PNG di Easy Lab, che è già nel repository: serve un
 * file vero perché l'anteprima mostri qualcosa, e non ne serve uno di scena.
 */
test('marchio ente', async ({ page }) => {
  const g = new Regista(page, 'marchio-ente', 'Il marchio delle email', 'Logo e colore con cui il tuo Ente si presenta');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'A che cosa serve');

  await page.goto('/laboratori/marchio');
  await page.waitForLoadState('networkidle');

  await g.passo("Si arriva da «Laboratori», col pulsante «Marchio email»: riguarda l'Ente, non il tuo account.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 7,
  });

  await g.passo("Logo e colore finiscono in testa alle email che Easy Lab manda al posto tuo: il riepilogo delle scadenze, gli avvisi, gli inviti.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Il colore');

  await g.passo('Il colore si sceglie dalla tavolozza o si scrive in esadecimale, se il tuo marchio ne ha uno preciso.', {
    su: page.locator('#colore'),
    zoom: 2.2,
    durata: 7,
  });

  await page.fill('input[type=text][maxlength="7"]', '#1f7a5c');
  await page.waitForTimeout(700);

  await g.passo("L'anteprima qui sotto si aggiorna mentre scegli, ed è la testata vera dell'email.", {
    su: page.getByText('Anteprima della testata'),
    zoom: 1.8,
    durata: 7,
  });

  await g.passo("Lasciandolo vuoto si usa il blu di Easy Lab: è un valore predefinito, non un errore.", {
    su: page.locator('#colore'),
    zoom: 2.2,
    durata: 7,
  });

  g.capitolo('Parte 3 di 3', 'Il logo');

  await page.setInputFiles('#logo', path.resolve(process.cwd(), '..', 'public', 'brand', 'easylab-logo.png'));
  await page.waitForTimeout(1200);

  await g.passo('Il logo si carica dal proprio computer, in PNG o JPEG.', {
    su: page.locator('#logo'),
    zoom: 2.1,
    durata: 6,
  });

  await g.passo("Anche qui l'anteprima mostra il risultato prima di salvare, alla larghezza che avrà nella posta.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo('«Salva» rende il marchio effettivo: la prossima email esce così.', {
    su: page.getByRole('button', { name: 'Salva' }).first(),
    click: true,
    zoom: 2.2,
  });

  await page.waitForLoadState('networkidle');

  await g.passo("Da qui in avanti compare anche «Rimuovi il logo», per tornare al solo nome scritto.", {
    zoom: 1,
    durata: 7,
  });

  g.chiusura('Da ricordare', [
    "Il marchio è dell'Ente e vale per tutti: non è una preferenza personale come il tema.",
    "Si vede solo nelle email che Easy Lab manda, non dentro l'applicazione.",
    "L'anteprima mostra la testata vera: il colore va giudicato lì, non sulla tavolozza.",
  ]);

  g.scrivi();
});

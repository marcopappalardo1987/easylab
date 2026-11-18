import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C6 — «Forzare il semaforo». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (10, in
 * tre capitoli da 3, 4 e 3): i testi scritti in `testi/semaforo-forzato.md`
 * sono numerati come i passi.
 *
 * Il motivo è obbligatorio solo per «Strumenti non idonei» (l'etichetta del
 * campo lo dice appena si sceglie il rosso): la guida lo mostra accadere.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('semaforo forzato', async ({ page }) => {
  const s = new Scenografo(page, 'semaforo-forzato', 'Forzare il semaforo', 'Quando lo stato calcolato non dice la verità');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const titolo = page.getByRole('heading', { name: 'Autoclave' }).locator('..');
  const bottone = page.getByRole('button', { name: 'Forza semaforo' });
  const statoForzato = page.locator('#forzaStato');
  const motivo = page.locator('[name="forzaForm.motivo"]');

  // ── Uno: perché esiste ──────────────────────────────────────────────────────
  s.cartello('Perché esiste', 'Parte 1 di 3');

  await s.scatto('Il semaforo di solito lo calcola Easy Lab dalle scadenze aperte: è una conseguenza, non una scelta.', {
    su: titolo,
    zoom: 2.2,
    durata: 7,
  });

  await s.scatto(
    "Ma una macchina può essere ferma per ragioni che nessuna scadenza descrive: un pezzo che non arriva, una verifica esterna andata male.",
    { durata: 7.5 },
  );

  await s.movimento(
    '«Forza semaforo» serve a quei casi, e a nessun altro.',
    async () => {
      await bottone.click();
      await expect(statoForzato).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: bottone, zoom: 2, coda: 1.2 },
  );

  // ── Due: stato e motivazione ────────────────────────────────────────────────
  s.cartello('Stato e motivazione', 'Parte 2 di 3');

  await s.movimento(
    'Si sceglie lo stato che la macchina deve mostrare da adesso.',
    async () => {
      await statoForzato.focus();
      await page.waitForTimeout(300);
      await statoForzato.selectOption('rosso');
      await expect(page.getByText(/Motivo \(obbligatorio/)).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: statoForzato, zoom: 1.8, coda: 1 },
  );

  await s.scatto("Per «Strumento non idoneo» il motivo è obbligatorio, ed è il punto della funzione: senza, resterebbe uno stato senza spiegazione.", {
    su: page.getByText(/Motivo \(obbligatorio/),
    zoom: 2,
    durata: 7.5,
  });

  await s.movimento(
    'Conviene scrivere quello che serve a chi leggerà: che cosa è successo, e che cosa si sta aspettando.',
    async () => {
      await motivo.click();
      await motivo.pressSequentially('Guarnizione della camera da sostituire, pezzo ordinato al fornitore. Ferma fino al montaggio.', {
        delay: 30,
      });
      await page.waitForTimeout(500);
    },
    { su: motivo, zoom: 1.7, coda: 1, suono: 'tastiera' },
  );

  const forza = page.getByRole('button', { name: 'Forza', exact: true });

  await s.movimento(
    'Si forza.',
    async () => {
      await forza.click();
      await expect(statoForzato).toHaveCount(0, { timeout: 10_000 });
      await page.waitForTimeout(700);
    },
    { su: forza, zoom: 2, coda: 1.2 },
  );

  // ── Tre: come si vede, e come si torna indietro ─────────────────────────────
  s.cartello('Come si vede, e come si torna indietro', 'Parte 3 di 3');

  await expect(titolo).toContainText('Strumento non idoneo');

  await s.scatto('Il semaforo ora è quello che hai deciso tu, con la bandierina accanto, e la macchina compare così in ogni elenco.', {
    su: titolo,
    zoom: 2.2,
    durata: 7,
  });

  const cartaForzata = page.getByText('Stato forzato', { exact: true }).locator('..');
  await expect(cartaForzata).toContainText('Guarnizione della camera');

  await s.scatto('La Panoramica dichiara che lo stato è forzato, da chi e perché: non si confonde mai con uno stato calcolato.', {
    su: cartaForzata,
    zoom: 1.7,
    durata: 7.5,
  });

  const rimuovi = page.getByRole('button', { name: 'Rimuovi forzatura' });

  await s.movimento(
    'Per tornare al calcolo automatico si riapre la stessa finestra: «Rimuovi forzatura» toglie lo stato deciso a mano.',
    async () => {
      await bottone.click();
      await expect(rimuovi).toBeVisible();
      await page.waitForTimeout(1000);
    },
    { su: bottone, zoom: 1.4, coda: 1.4 },
  );

  s.chiusura('Da ricordare', [
    "Il semaforo normalmente è calcolato: forzarlo è l'eccezione, per stati che le scadenze non sanno descrivere.",
    'La motivazione resta visibile in Panoramica: uno stato deciso da una persona non deve sembrare calcolato.',
    'La forzatura non cancella le scadenze: quando si toglie, il semaforo torna a dire quello che i dati dicono.',
  ]);

  await s.scrivi();
});

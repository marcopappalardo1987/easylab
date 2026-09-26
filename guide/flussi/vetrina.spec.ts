import { test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Prova del formato «vetrina»: schermate vere e riprese vere.
 *
 * Non è una guida del catalogo: è il campione su cui si decide il formato. Sceglie
 * i momenti in cui la risposta dell'applicazione *è* il contenuto — l'elenco che
 * si restringe lettera per lettera, l'albero che scende di un livello, il semaforo
 * che filtra — e li filma invece di ritrarli. I dettagli da leggere restano fermi,
 * perché su quelli l'occhio deve poter tornare.
 *
 * Girata con l'Admin: vede l'intera sede, quindi i numeri raccontano qualcosa.
 */
test('vetrina', async ({ page }) => {
  const s = new Scenografo(page, 'vetrina', 'Easy Lab', 'Il parco strumenti, come si tiene in ordine');

  await accedi(page, 'maria.conti@aurora.test');
  await s.avvia();

  // ── Uno: la casa ────────────────────────────────────────────────────────────
  s.cartello('Dashboard', 'Dove si apre la giornata');

  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle');

  await s.panoramica(
    'La Dashboard non è un cruscotto da guardare: ogni riquadro è una domanda con la sua risposta, e si clicca.',
    8,
  );

  // ── Due: cercare ────────────────────────────────────────────────────────────
  s.cartello('Cercare', 'Tremila macchine, una riga di testo');

  await page.goto('/strumenti');
  await page.waitForLoadState('networkidle');

  await s.movimento(
    "L'elenco si restringe mentre scrivi: nome, modello e matricola insieme, senza premere invio.",
    async () => {
      await page.locator('#search').click();
      await page.locator('#search').pressSequentially('centrifuga', { delay: 130 });
      await page.waitForTimeout(900);
    },
    { su: page.locator('#search'), zoom: 1, coda: 1.1, suono: 'tastiera' },
  );

  await s.scatto('Quel che resta è già il risultato: matricola, ubicazione, stato.', {
    su: page.locator('table, [role=table]').first(),
    zoom: 1.5,
    durata: 5.5,
  });

  await page.fill('#search', '');
  await page.waitForTimeout(700);

  await s.movimento(
    'Scorrendo si vede tutto il parco: milleottocento macchine, una riga per ciascuna.',
    async () => {
      for (let i = 0; i < 9; i++) {
        await page.mouse.wheel(0, 150);
        await page.waitForTimeout(110);
      }
      await page.waitForTimeout(500);
      await page.mouse.wheel(0, -1350);
    },
    { coda: 0.9, suono: 'scorrimento' },
  );

  await s.movimento(
    'Il semaforo è un filtro come gli altri: «azione richiesta» lascia solo le macchine che stanno per scadere.',
    async () => {
      await page.locator('select').nth(1).selectOption('arancione');
      await page.waitForTimeout(900);
    },
    { su: page.locator('select').nth(1), zoom: 1.12, coda: 1.2 },
  );

  // ── Tre: la struttura ───────────────────────────────────────────────────────
  s.cartello('Struttura', 'Sede, dipartimento, laboratorio');

  await page.goto('/anagrafica');
  await page.waitForLoadState('networkidle');

  await s.scatto(
    "L'anagrafica è la sede come è fatta davvero, non un elenco: al primo livello ci sono i dipartimenti.",
    { su: page.getByText('Genetica Medica').first(), zoom: 1.35, durata: 7 },
  );

  await s.movimento(
    'Si scende cliccando, un livello alla volta, e la pagina si sposta con te.',
    async () => {
      await page.getByText('Genetica Medica').first().click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(600);
    },
    { su: page.getByText('Genetica Medica').first(), zoom: 1.12, coda: 1.2 },
  );

  await s.scatto('Il percorso in alto dice sempre dove sei, e ogni pezzo riporta indietro.', {
    su: page.getByRole('navigation', { name: 'Percorso' }),
    zoom: 2.4,
    durata: 6,
  });

  s.chiusura('Tre cose da portarsi via', [
    'La Dashboard apre la giornata: ogni riquadro è già un filtro.',
    "La ricerca guarda nome, modello e matricola insieme.",
    "L'anagrafica è la struttura vera della sede, e si percorre cliccando.",
  ]);

  await s.scrivi();
});

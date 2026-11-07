import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida C1 — «L'elenco degli strumenti». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (10, in
 * tre capitoli da 3, 4 e 3): i testi scritti in `testi/elenco-strumenti.md`
 * sono numerati come i passi.
 *
 * Qui il movimento è l'elenco che si restringe: mentre si scrive, scegliendo
 * uno stato, spuntando «obsoleti», cliccando un'intestazione.
 *
 * Il copione non scrive niente.
 */
test('elenco strumenti', async ({ page }) => {
  const s = new Scenografo(page, 'elenco-strumenti', "L'elenco degli strumenti", 'Cercare, filtrare e ordinare tutto il parco');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const righe = page.locator('tbody tr');
  const cerca = page.locator('#search');
  const stato = page.locator('select[wire\\:model\\.live="stato"]');
  const ubicazione = page.locator('select[wire\\:model\\.live="ubicazioneId"]');
  const obsoleti = page.getByLabel(/Solo strumenti obsoleti/);

  // ── Uno: cercare ────────────────────────────────────────────────────────────
  s.cartello('Cercare', 'Parte 1 di 3');

  await s.scatto("«Strumenti» apre il parco intero della sede, senza filtri: è l'elenco da cui si parte quando si sa che cosa si cerca.", {
    su: page.getByRole('heading', { name: 'Strumenti' }),
    zoom: 1.7,
    durata: 7,
  });

  await s.scatto('La ricerca guarda nome, modello e matricola insieme: non devi ricordare con quale dei tre è stata registrata.', {
    su: cerca,
    zoom: 2,
    durata: 7,
  });

  await s.movimento(
    "L'elenco si restringe mentre scrivi, senza premere invio.",
    async () => {
      await cerca.click();
      await cerca.pressSequentially('centrifuga', { delay: 110 });
      // L'elenco è paginato: il conteggio può restare uguale, il contenuto no.
      await expect(righe.filter({ hasNotText: /Centrifuga/i })).toHaveCount(0);
      await page.waitForTimeout(600);
    },
    { su: cerca, zoom: 1.3, coda: 1.4, suono: 'tastiera' },
  );

  await cerca.fill('');
  await expect(righe.filter({ hasNotText: /Centrifuga/i }).first()).toBeVisible();

  // ── Due: filtrare ───────────────────────────────────────────────────────────
  s.cartello('Filtrare', 'Parte 2 di 3');

  await s.scatto("Il filtro per ubicazione segue l'albero dei laboratori: si sceglie un laboratorio e restano le sue macchine.", {
    su: ubicazione,
    zoom: 2,
    durata: 7,
  });

  await s.scatto('Lo stato è il semaforo: strumentazione idonea, interventi necessari, strumenti non idonei.', {
    su: stato,
    zoom: 2,
    durata: 6,
  });

  await s.movimento(
    "Scelto uno stato, l'elenco mostra solo quelle: è la stessa cosa che fa un riquadro della Dashboard.",
    async () => {
      await stato.focus();
      await page.waitForTimeout(300);
      await stato.selectOption({ label: '◐ Interventi necessari' });
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(800);
    },
    { su: stato, zoom: 1.2, coda: 1.2 },
  );

  await s.movimento(
    "«Solo strumenti obsoleti» è a parte, e si somma agli altri filtri: risponde all'età, non alla manutenzione.",
    async () => {
      await obsoleti.check();
      await expect(righe.filter({ hasNotText: 'Strumento obsoleto' })).toHaveCount(0);
      await page.waitForTimeout(700);
    },
    { su: obsoleti, zoom: 1.2, coda: 1.4 },
  );

  // Si torna al parco intero: l'ordinamento si racconta meglio su tutte le righe.
  await obsoleti.uncheck();
  await stato.selectOption('');
  await page.waitForLoadState('networkidle');
  await expect(righe.filter({ hasNotText: 'Strumento obsoleto' }).first()).toBeVisible();

  // ── Tre: ordinare e sfogliare ───────────────────────────────────────────────
  s.cartello('Ordinare e sfogliare', 'Parte 3 di 3');

  const intestazione = page.getByRole('button', { name: /Prossima scadenza/i }).first();

  await s.movimento(
    "Ogni intestazione di colonna ordina l'elenco, e cliccandola di nuovo lo rovescia.",
    async () => {
      await intestazione.click();
      await expect(intestazione).toContainText(/[↑↓]/);
      await page.waitForTimeout(900);
    },
    { su: intestazione, zoom: 1.2, coda: 1.4 },
  );

  await s.scatto("Ordinando per «Prossima scadenza» viene su per prima la macchina che chiede attenzione prima di tutte.", {
    su: righe.first(),
    zoom: 1.4,
    durata: 7,
  });

  await s.scatto('In fondo si scelgono quante righe per pagina: utile quando si stampa o si fa un giro completo.', {
    su: page.locator('#perPage'),
    zoom: 2.2,
    durata: 6.5,
  });

  s.chiusura('Da ricordare', [
    'La ricerca guarda nome, modello e matricola insieme, e basta una parte di uno dei tre.',
    'I filtri si sommano: ubicazione, stato e «solo strumenti obsoleti» restringono insieme.',
    'Le colonne ordinano, e «prossima scadenza» è quella che mette in cima ciò che scade prima.',
  ]);

  await s.scrivi();
});

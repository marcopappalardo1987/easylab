import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida K1 — «L'abbonamento». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (9, in
 * tre capitoli da 2, 3 e 4): i testi scritti in `testi/abbonamento.md` sono
 * numerati come i passi.
 *
 * ⚠️ Il portale di fatturazione è **di Stripe**, su un dominio di terzi: il
 * copione si ferma prima e lo dice. Nessuna chiamata a Stripe parte da qui: la
 * pagina legge solo il database.
 *
 * ⚠️ L'account dimostrativo è sul piano SaaS **senza** abbonamento Stripe: la
 * pagina dice «Nessun abbonamento» e, al posto del portale, a chi rivolgersi.
 * È lo stato di un cliente prima dell'attivazione, e le didascalie lo dicono
 * invece di fingere il contrario. Non essendo in comodato d'uso, la scadenza
 * del comodato (ADR-058) qui non compare: la pagina la mostra solo su quel piano.
 */
test('abbonamento', async ({ page }) => {
  const s = new Scenografo(page, 'abbonamento', "L'abbonamento", 'Il piano, i limiti, e dove stanno le fatture');

  await accedi(page, 'maria.conti@aurora.test');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: che cosa dice la pagina ────────────────────────────────────────────
  s.cartello('Che cosa dice la pagina', 'Parte 1 di 3');

  const utente = page.getByRole('banner').getByRole('button', { name: /Maria Conti/ });

  await s.movimento(
    "«Abbonamento», nel menù del tuo nome, riguarda il contratto della tua organizzazione: la vede chi amministra.",
    async () => {
      await utente.click();
      const voce = page.getByRole('link', { name: 'Abbonamento' });
      await expect(voce).toBeVisible();
      await page.waitForTimeout(700);
      await voce.click();
      await page.waitForURL('**/abbonamento');
      await expect(page.getByRole('heading', { name: 'Abbonamento', level: 1 })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: utente, zoom: 1.4, coda: 1.2 },
  );

  await s.scatto("Sotto il titolo c'è la ragione sociale con cui il contratto è intestato: è quella che comparirà sulle fatture.", {
    su: page.getByText('Laboratori Aurora', { exact: true }),
    zoom: 2.2,
    durata: 7,
  });

  // ── Due: il piano e i limiti ────────────────────────────────────────────────
  s.cartello('Il piano e i limiti', 'Parte 2 di 3');

  const voce = (etichetta: string) => page.locator('dt', { hasText: new RegExp(`^${etichetta}$`, 'i') }).locator('..');

  await expect(voce('Abbonamento').locator('dd')).toHaveText('Nessun abbonamento');

  await s.scatto("Il piano dice che cosa è previsto, lo stato se l'abbonamento è attivo o sospeso: qui non è ancora stato attivato.", {
    su: voce('Piano').locator('..'),
    zoom: 1.6,
    alone: false,
    durata: 7.5,
  });

  await expect(page.locator('[data-strumenti]')).toBeVisible();

  await s.scatto('I limiti sono due: le sedi, quante ne usi su quante ne prevede il piano, e gli strumenti, che su questo piano sono illimitati.', {
    su: voce('Sedi').locator('..'),
    zoom: 1.6,
    alone: false,
    durata: 8,
  });

  await s.scatto('Sono i numeri da guardare prima di aprire una sede nuova: se il piano non la copre, va cambiato prima.', {
    su: voce('Sedi'),
    zoom: 2.4,
    durata: 7,
  });

  // ── Tre: le fatture e il metodo di pagamento ────────────────────────────────
  s.cartello('Le fatture e il metodo di pagamento', 'Parte 3 di 3');

  const avviso = page.getByText(/Il portale di fatturazione non è disponibile/);
  await expect(avviso).toBeVisible();

  await s.scatto('Con un abbonamento attivo qui compare il portale di fatturazione; senza, come qui, la pagina dice a chi rivolgersi.', {
    su: avviso,
    zoom: 1.7,
    durata: 7.5,
  });

  await s.scatto("Il pagamento passa da Stripe, un sito di terzi, e non è un difetto: i dati della carta non passano mai da Easy Lab.", {
    su: page.getByRole('heading', { name: 'Attiva un piano' }).locator('..'),
    zoom: 1.5,
    alone: false,
    durata: 7.5,
  });

  await s.scatto('Fatture, metodo di pagamento e disdetta si gestiscono lì, nel portale: è il posto di ogni domanda che comincia con «la fattura».', {
    su: avviso,
    zoom: 2.2,
    alone: false,
    durata: 8,
  });

  await s.panoramica('Finita la visita si torna qui, e le modifiche fatte là si riflettono su questa pagina.', 6.5);

  s.chiusura('Da ricordare', [
    "L'abbonamento è dell'organizzazione: lo vede e lo governa chi amministra, non ogni utente.",
    'I limiti sono in sedi e in strumenti: si controllano prima di crescere, non dopo.',
    'Fatture e metodo di pagamento stanno nel portale di Stripe: i dati della carta non passano da Easy Lab.',
  ]);

  await s.scrivi();
});

import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida I1 — «Le persone del laboratorio». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Due passi in più del copione precedente** (11 invece di 9; tre capitoli
 * da 2, 6 e 3): la persona invitata è un Responsabile Reparto, e da ADR-057 il
 * ruolo da solo non le fa vedere niente finché non le si assegnano i
 * laboratori. La finestra «Laboratori» è il gesto che lo completa, e la guida
 * lo mostra subito dopo l'invito. `testi/persone-ente.md` è rinumerato.
 *
 * Il vecchio copione compilava l'invito e non lo mandava mai: qui si manda, e la
 * riga nuova compare in elenco.
 *
 * ⚠️ Serve l'**Admin**: invitare e cambiare ruolo sono gesti di amministrazione.
 * ⚠️ Il copione SCRIVE (invita, assegna, cestina): `azzera.sh` prima di ogni giratura.
 */
test('persone ente', async ({ page }) => {
  const s = new Scenografo(page, 'persone-ente', 'Le persone del laboratorio', 'Invitare, dare un ruolo, togliere accesso');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/utenti');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: chi ha accesso ─────────────────────────────────────────────────────
  s.cartello('Chi ha accesso', 'Parte 1 di 3');

  await s.scatto('«Persone» elenca chi ha accesso a questo Ente, con il suo ruolo e il suo stato.', {
    su: page.getByRole('heading', { name: /Persone di questo Ente/ }),
    zoom: 1.7,
    durata: 8,
  });

  await s.panoramica(
    "Il ruolo non è un'etichetta: decide che cosa quella persona vede e può fare, quindi questa pagina è anche l'elenco dei permessi.",
    8,
  );

  // ── Due: invitare qualcuno ──────────────────────────────────────────────────
  s.cartello('Invitare qualcuno', 'Parte 2 di 3');

  const invita = page.getByRole('button', { name: /Invita una persona/ });

  await s.scatto("Si invita per email: non si crea una password per conto d'altri, e non si consegnano credenziali a voce.", {
    su: invita,
    zoom: 2.1,
    durata: 8,
  });

  const finestra = page.getByRole('dialog');

  await s.movimento(
    'Nome e indirizzo: il resto lo sceglie la persona quando accetta.',
    async () => {
      await invita.click();
      await expect(page.locator('#nome')).toBeVisible();
      await page.waitForTimeout(400);
      await page.locator('#nome').pressSequentially('Federico Sala', { delay: 80 });
      await page.locator('#email').click();
      await page.locator('#email').pressSequentially('federico.sala@aurora.test', { delay: 55 });
      await page.waitForTimeout(500);
    },
    { su: invita, zoom: 1.2, coda: 0.9, suono: 'tastiera' },
  );

  await s.movimento(
    "Il ruolo si assegna adesso, ed è la decisione che conta: da lì discende tutto quello che vedrà entrando.",
    async () => {
      // ⚠️ Niente click né Escape: la tendina nativa non finisce nello
      // screencast, ed Escape chiude l'intera finestra d'invito.
      await page.locator('#ruolo').focus();
      await page.waitForTimeout(500);
      await page.locator('#ruolo').selectOption({ label: 'Responsabile Reparto' });
      await expect(page.locator('#ruolo')).toHaveValue('Responsabile Reparto');
      await page.waitForTimeout(800);
    },
    { su: page.locator('#ruolo'), zoom: 1.6, coda: 1.2 },
  );

  const conferma = finestra.getByRole('button', { name: 'Invita', exact: true });
  const riga = page.locator('tbody tr', { hasText: 'Federico Sala' });

  await s.movimento(
    'Alla persona arriva un invito valido sette giorni, con cui sceglierà la propria password.',
    async () => {
      await conferma.click();
      await expect(page.locator('#nome')).toHaveCount(0, { timeout: 10_000 });
      await expect(riga).toBeVisible();
      await riga.scrollIntoViewIfNeeded();
      await page.waitForTimeout(800);
    },
    { su: conferma, zoom: 1.4, coda: 1.4 },
  );

  await expect(riga).toContainText(/Nessun laboratorio assegnato/);

  const laboratori = riga.getByRole('button', { name: 'Laboratori' });

  await s.movimento(
    'A un Responsabile Reparto il ruolo non basta: con «Laboratori» si scelgono quelli che segue, e senza non vede nulla.',
    async () => {
      await laboratori.click();
      await expect(finestra.getByText(/Laboratori di Federico Sala/)).toBeVisible();
      await page.waitForTimeout(900);
      await finestra.getByLabel('Ematologia', { exact: true }).check();
      await expect(finestra.locator('[data-compreso]').first()).toBeVisible();
      await page.waitForTimeout(1200);
    },
    { su: laboratori, zoom: 1.2, coda: 1.4 },
  );

  const salvaLab = finestra.getByRole('button', { name: 'Salva i laboratori' });

  await s.movimento(
    'Chi segue un laboratorio segue anche tutto ciò che contiene. Si salva, e la riga dice che cosa segue.',
    async () => {
      await salvaLab.click();
      await expect(finestra).toHaveCount(0, { timeout: 10_000 });
      await expect(riga).toContainText(/Segue:\s*Ematologia/);
      await page.waitForTimeout(800);
    },
    { su: salvaLab, zoom: 1.4, coda: 1.4 },
  );

  // ── Tre: cambiare ruolo, togliere accesso ───────────────────────────────────
  s.cartello('Cambiare ruolo, togliere accesso', 'Parte 3 di 3');

  await s.scatto('Il ruolo di una persona si cambia in qualsiasi momento, e ha effetto subito.', {
    su: riga.getByRole('button', { name: 'Cambia ruolo' }),
    zoom: 2,
    durata: 7,
  });

  const cestina = riga.getByRole('button', { name: 'Cestina' });

  await s.movimento(
    "«Cestina» toglie l'accesso senza cancellare il passato: gli interventi che quella persona ha registrato restano suoi.",
    async () => {
      await cestina.click();
      await expect(finestra.getByText(/Cestinare Federico Sala/)).toBeVisible();
      await page.waitForTimeout(1200);
    },
    { su: cestina, zoom: 1.3, coda: 1.6 },
  );

  const confermaCestino = finestra.getByRole('button', { name: 'Cestina' });

  await s.movimento(
    "È il gesto giusto quando qualcuno lascia il laboratorio: si toglie l'accesso, non la storia.",
    async () => {
      await confermaCestino.click();
      await expect(finestra).toHaveCount(0, { timeout: 10_000 });
      await expect(riga.getByRole('button', { name: 'Ripristina' })).toBeVisible();
      await expect(riga).toContainText('non entra e non è assegnabile');
      await page.waitForTimeout(900);
    },
    { su: confermaCestino, zoom: 1.4, coda: 1.4 },
  );

  s.chiusura('Da ricordare', [
    'Si invita per email: la password la sceglie la persona, e nessuno la conosce al posto suo.',
    'Il ruolo decide che cosa quella persona vede; a un Responsabile Reparto servono anche i laboratori che segue.',
    "Cestinare toglie l'accesso e lascia intatto lo storico: il lavoro registrato resta attribuito a chi lo ha fatto.",
  ]);

  await s.scrivi();
});

import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida B2 — «Aggiungere e rinominare i reparti». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (11, in
 * tre capitoli da 5, 3 e 3): i testi scritti in `testi/creare-nodi.md` sono
 * numerati come i passi.
 *
 * ⚠️ Serve l'**Admin**: `unita_organizzativa.update` è di Admin, Superadmin e
 * Developer, quindi a un Responsabile questi pulsanti non compaiono affatto.
 *
 * ⚠️ Il nome del laboratorio nuovo va scelto fra quelli che NON esistono: il
 * seme ne ha già dodici, e un doppione è esattamente il gesto che la guida
 * insegnerebbe a fare.
 *
 * L'ultimo passo prova davvero «Elimina» su un laboratorio che ha un
 * sotto-laboratorio: l'applicazione rifiuta, e il rifiuto è quello che si
 * racconta. Prima la guida diceva che l'eliminazione porta via anche ciò che
 * sta sotto, e non era vero.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('creare nodi', async ({ page }) => {
  const s = new Scenografo(page, 'creare-nodi', 'Aggiungere e rinominare i reparti', 'Tenere la struttura uguale alla sede vera');

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/laboratori');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const nome = page.locator('#nome');
  const salva = page.getByRole('button', { name: 'Salva' });

  // ── Uno: un laboratorio nuovo ───────────────────────────────────────────────
  s.cartello('Un laboratorio nuovo', 'Parte 1 di 3');

  const aggiungi = page.getByRole('button', { name: /Aggiungi laboratorio/ });

  await s.scatto('Il pulsante in alto a destra aggiunge sempre un figlio del punto in cui ti trovi: alla radice, un laboratorio.', {
    su: aggiungi,
    zoom: 2.1,
    durata: 7,
  });

  await s.movimento(
    'Si apre un modulo, e il nome è la sola cosa obbligatoria.',
    async () => {
      await aggiungi.click();
      await expect(nome).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: aggiungi, zoom: 1.4, coda: 1 },
  );

  await s.movimento(
    'Conviene scrivere il nome che il reparto ha davvero, non una sigla interna.',
    async () => {
      await nome.click();
      await nome.pressSequentially('Microscopia', { delay: 110 });
      await page.waitForTimeout(400);
    },
    { su: nome, zoom: 1.8, coda: 0.8, suono: 'tastiera' },
  );

  await s.scatto('Le note sono facoltative: sono per chi arriverà dopo, e si possono aggiungere in qualsiasi momento.', {
    su: page.locator('#note'),
    zoom: 1.8,
    durata: 6.5,
  });

  await s.movimento(
    'Si salva.',
    async () => {
      await salva.click();
      await expect(nome).toHaveCount(0);
      await expect(page.getByRole('button', { name: /Microscopia/ })).toBeVisible();
      await page.waitForTimeout(500);
    },
    { su: salva, zoom: 1.8, coda: 1.2 },
  );

  // ── Due: dentro il nuovo reparto ────────────────────────────────────────────
  s.cartello('Dentro il nuovo reparto', 'Parte 2 di 3');

  const scheda = page.getByRole('button', { name: /Microscopia/ });

  await s.scatto("Il laboratorio è nell'elenco, vuoto: nessun sotto-laboratorio e nessuna macchina.", {
    su: scheda,
    zoom: 2.2,
    durata: 6,
  });

  await s.movimento(
    'Entrandoci, lo stesso pulsante ora propone un sotto-laboratorio: cambia con il livello in cui sei.',
    async () => {
      await scheda.click();
      await expect(page.getByRole('navigation', { name: 'Percorso' })).toContainText('Microscopia');
      await expect(page.getByRole('button', { name: /Aggiungi sotto-laboratorio/ }).first()).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: scheda, zoom: 1.3, coda: 1.4 },
  );

  const aggiungiSotto = page.getByRole('button', { name: /Aggiungi sotto-laboratorio/ }).first();

  await s.movimento(
    'Un sotto-laboratorio dentro il laboratorio, con lo stesso modulo di prima.',
    async () => {
      await aggiungiSotto.click();
      await expect(nome).toBeVisible();
      await nome.pressSequentially('Terreni di coltura', { delay: 90 });
      await page.waitForTimeout(400);
      await salva.click();
      await expect(nome).toHaveCount(0);
      await expect(page.getByRole('button', { name: /Terreni di coltura/ })).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: aggiungiSotto, zoom: 1.2, coda: 1.2 },
  );

  // ── Tre: correggere un nome ─────────────────────────────────────────────────
  s.cartello('Correggere un nome', 'Parte 3 di 3');

  const rinomina = page.getByRole('button', { name: 'Rinomina', exact: true });

  await s.movimento(
    "«Rinomina» cambia il nome del nodo in cui ti trovi, e non tocca nient'altro: le macchine restano dove sono.",
    async () => {
      await rinomina.click();
      await expect(nome).toHaveValue('Microscopia');
      await page.waitForTimeout(500);
    },
    { su: rinomina, zoom: 1.4, coda: 1.2 },
  );

  await s.movimento(
    'Si corregge e si salva: il nome nuovo compare ovunque, percorso compreso.',
    async () => {
      await nome.click();
      await nome.press('End');
      await nome.pressSequentially(' elettronica', { delay: 100 });
      await page.waitForTimeout(400);
      await salva.click();
      await expect(nome).toHaveCount(0);
      await expect(page.getByRole('navigation', { name: 'Percorso' })).toContainText('Microscopia elettronica');
      await page.waitForTimeout(400);
    },
    { su: nome, zoom: 1.3, coda: 1.4, suono: 'tastiera' },
  );

  const elimina = page.getByRole('button', { name: 'Elimina', exact: true }).first();

  await s.movimento(
    '«Elimina» vale solo per un nodo vuoto: finché dentro ci sono sotto-laboratori o macchine, si rifiuta.',
    async () => {
      await elimina.click();
      const conferma = page.getByRole('dialog').getByRole('button', { name: 'Elimina' });
      await expect(conferma).toBeVisible();
      await page.waitForTimeout(900);
      await conferma.click();
      await expect(page.getByText('Elimina prima le unità interne.')).toBeVisible();
      await page.waitForTimeout(400);
    },
    { su: elimina, zoom: 1.3, coda: 1.6 },
  );

  await expect(page.getByRole('navigation', { name: 'Percorso' })).toContainText('Microscopia elettronica');

  s.chiusura('Da ricordare', [
    'Il pulsante di aggiunta segue il livello in cui ti trovi: alla radice crea un laboratorio, dentro un laboratorio un sotto-laboratorio.',
    "Rinominare è sicuro: cambia solo l'etichetta, e le macchine non si spostano. Eliminare si può solo un nodo vuoto.",
    'La struttura va tenuta uguale al reparto vero: è da lì che le macchine ereditano dove sono, e chi le può vedere.',
  ]);

  await s.scrivi();
});

import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida M7 — «L'editor dei ruoli». Guida INTERNA. Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (9, in tre
 * capitoli da 3): i testi scritti in `testi/editor-ruoli.md` sono numerati come i passi.
 *
 * Il seme non ha né celle personalizzate né permessi orfani: la prima la crea il
 * copione col gesto che racconta (una concessione a Tenant), il secondo lo
 * pianta artisan, come i `letture_contaore.*` rimasti nel DB di sviluppo.
 *
 * ⚠️ Il copione SCRIVE: `gira-in-fila.sh` azzera prima di ogni giratura.
 */
test('editor ruoli', async ({ page }) => {
  const s = new Scenografo(page, 'editor-ruoli', "L'editor dei ruoli", 'La matrice ruolo→permesso, e le celle che raccontano una storia');

  artisan(
    '\\Spatie\\Permission\\Models\\Permission::findOrCreate("letture_contaore.view", "web")->assignRole("Tecnico");' +
      ' app(\\Spatie\\Permission\\PermissionRegistrar::class)->forgetCachedPermissions(); echo "ok";',
  );

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma/ruoli');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('[data-orfano="letture_contaore.view"]')).toBeVisible();
  await s.avvia();

  // ── Uno: la matrice ─────────────────────────────────────────────────────────
  s.cartello('La matrice', 'Parte 1 di 3');

  await s.scatto('Una riga per permesso, una colonna per ruolo: la matrice dice chi può fare che cosa, in tutto il prodotto.', {
    su: page.locator('table thead').first(),
    zoom: 1.3,
    durata: 7,
  });

  const cella = page.locator('tr[data-permesso="strumenti.create"] td[data-ruolo="Tenant"]');
  const bottone = cella.getByRole('button');
  const finestra = page.locator('[data-conferma="concessione"]');

  await s.movimento(
    'Ogni cella è un bottone: un clic concede o revoca, per tutti quelli che hanno quel ruolo, in tutti gli Enti.',
    async () => {
      await bottone.click();
      await expect(finestra).toBeVisible();
      await page.waitForTimeout(600);
    },
    { su: bottone, zoom: 1.1, coda: 1.2 },
  );

  const concedi = finestra.getByRole('button', { name: 'Concedi' });

  await s.movimento(
    "Prima di scrivere la finestra avvisa: Tenant non ha il secondo fattore, e il permesso arriverebbe a chi entra con la sola password.",
    async () => {
      await page.waitForTimeout(2200);
      await concedi.click();
      await expect(finestra).toHaveCount(0);
      await expect(cella).toHaveAttribute('data-stato', 'si');
      await page.waitForTimeout(700);
    },
    { su: concedi, zoom: 1.2, coda: 1 },
  );

  // ── Due: le celle bloccate ──────────────────────────────────────────────────
  s.cartello('Le celle bloccate', 'Parte 2 di 3');

  await s.scatto("Un permesso «bloccato» non è ridistribuibile dall'interfaccia: sono i vincoli di privacy e di sicurezza del prodotto.", {
    su: page.locator('tr[data-permesso="tenants.view_all"]'),
    zoom: 1.3,
    durata: 7,
  });

  await s.scatto("Leggere gli errori dell'applicazione è del solo Developer: se fosse una cella cliccabile, il gate più stretto sarebbe una casella.", {
    su: page.locator('tr[data-permesso="system.logs.view"]'),
    zoom: 1.3,
    durata: 7.5,
  });

  await s.scatto('Ogni clic resta nel registro di audit; i blocchi e il catalogo, invece, si cambiano solo nel codice, con un rilascio.', {
    su: page.getByText('Ogni click scrive subito'),
    zoom: 1.25,
    durata: 7,
  });

  // ── Tre: «personalizzato» e le orfane ───────────────────────────────────────
  s.cartello("«Personalizzato» e le orfane", 'Parte 3 di 3');

  await s.scatto('La cella appena accesa ora è «personalizzato»: il database e la configurazione non concordano, e si leggono i due valori.', {
    su: cella.locator('[data-personalizzato]'),
    zoom: 1.15,
    durata: 7.5,
  });

  const differenze = page.locator('[data-filtro-differenze]');

  await s.movimento(
    '«Mostra solo le differenze» le raccoglie: è da qui che si decide se riseminare, che le riporterebbe tutte alla configurazione.',
    async () => {
      await differenze.click();
      await expect(differenze).toHaveAttribute('data-filtro-differenze', 'attivo');
      await expect(page.locator('tr[data-permesso]')).toHaveCount(1);
      await page.waitForTimeout(700);
    },
    { su: differenze, zoom: 1.2, coda: 1.2 },
  );

  await s.scatto("In fondo i permessi «orfani»: righe che il codice non usa più e che restano attaccate a qualche ruolo.", {
    su: page.locator('[data-orfani]'),
    zoom: 1.15,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    'La matrice governa i permessi di tutto il prodotto: si modifica a runtime e vale subito.',
    'I permessi bloccati non si ridistribuiscono dalla UI: sono vincoli, non dimenticanze.',
    'Le celle «personalizzato» mostrano dove il DB si è allontanato dalla configurazione: è da lì che si decide se riseminare.',
  ]);

  await s.scrivi();
});

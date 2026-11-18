import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida M9 — «L'error tracker». Guida INTERNA. Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (8, in
 * tre capitoli da 3, 2 e 3): i testi scritti in `testi/errori.md` sono numerati
 * come i passi.
 *
 * ⚠️ Girata col **Developer**: `/piattaforma/errori` sta dietro
 * `system.logs.view`, che è del solo Developer (🔗 ADR-017). Il Superadmin si
 * mostra per contrasto: fra le sue linguette «Errori» non c'è.
 *
 * ⚠️ Il seme non ha errori, e la versione precedente raccontava righe e schede
 * su una tabella vuota. Gli errori si fanno nascere qui, passando dalla stessa
 * `CatturaErrori::cattura()` dell'applicazione: una richiesta finta con una
 * password nell'input, così la scheda mostra che la password non entra. La
 * rotta finta serve perché `CatturaErrori` tratti la richiesta come HTTP: in
 * console l'input non si registra.
 */
test('errori', async ({ page }) => {
  const s = new Scenografo(page, 'errori', "L'error tracker", "Gli errori dell'applicazione, senza mandarli fuori");

  artisan(
    '$r = \\Illuminate\\Http\\Request::create("/strumenti/importa", "POST", ["riga" => "14", "data_installazione" => "31/02/2019", "password" => "Segreta-2026"]);' +
      ' $r->setRouteResolver(fn () => new \\Illuminate\\Routing\\Route("POST", "strumenti/importa", []));' +
      ' app()->instance("request", $r);' +
      ' foreach (range(1, 9) as $i) { try { \\Carbon\\Carbon::createFromFormat("d/m/Y", "31/02/20x9"); } catch (\\Throwable $e) { \\App\\Support\\Errori\\CatturaErrori::cattura($e); } }' +
      ' foreach (range(1, 4) as $i) { try { \\App\\Models\\Strumento::query()->withoutGlobalScopes()->findOrFail(999999); } catch (\\Throwable $e) { \\App\\Support\\Errori\\CatturaErrori::cattura($e); } }' +
      ' echo \\App\\Models\\Errore::count();',
  );

  await accedi(page, 'developer@easylab.test');
  await page.goto('/piattaforma/errori');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('tbody tr a').first()).toBeVisible();
  await s.avvia();

  // ── Uno: perché è interno ───────────────────────────────────────────────────
  s.cartello('Perché è interno', 'Parte 1 di 3');

  await s.scatto("Gli errori dell'applicazione si raccolgono qui dentro, non su un servizio esterno.", {
    su: page.getByRole('heading', { name: 'Errori', level: 1 }),
    zoom: 1.7,
    durata: 8,
  });

  await s.panoramica(
    'Un errore contiene messaggi, input di richiesta e identificativi: mandarlo fuori significherebbe mandare fuori dati dei clienti.',
    8,
  );

  await s.scatto('Da qui la scelta di costruirlo invece di comprarlo: un fornitore in più sarebbe stato un sub-responsabile in più.', {
    su: page.getByText('Cosa si è rotto, quante volte, e con quale contesto.'),
    zoom: 1.8,
    durata: 8,
  });

  // ── Due: chi lo vede ────────────────────────────────────────────────────────
  s.cartello('Chi lo vede', 'Parte 2 di 3');

  await s.scatto('È la pagina più chiusa del prodotto: la vede il solo Developer, e nemmeno il Superadmin.', {
    su: page.getByRole('banner').getByRole('button', { name: /Developer EasyLab/ }),
    zoom: 2,
    durata: 8,
  });

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma');
  await page.waitForLoadState('networkidle');
  const linguette = page.getByRole('link', { name: 'Registro di audit' }).locator('..');
  await expect(linguette).toBeVisible();
  await expect(linguette.getByRole('link', { name: 'Errori', exact: true })).toHaveCount(0);

  await s.scatto('Il Superadmin non ha nemmeno la linguetta: non è gerarchia, è che gli errori contengono dati dei clienti.', {
    su: linguette,
    zoom: 1.6,
    durata: 8,
  });

  // ── Tre: come si legge ──────────────────────────────────────────────────────
  s.cartello('Come si legge', 'Parte 3 di 3');

  await accedi(page, 'developer@easylab.test');
  await page.goto('/piattaforma/errori');
  await page.waitForLoadState('networkidle');

  const riga = page.locator('tbody tr', { hasText: 'InvalidFormatException' });
  await expect(riga).toContainText('9');

  await s.scatto('Gli errori sono raggruppati per tipo: un problema che si ripete è una riga sola con un conteggio, non cento righe.', {
    su: riga,
    zoom: 1.5,
    durata: 8,
  });

  const apri = riga.locator('a').first();

  await s.movimento(
    'Aprendone uno si vede quando è comparso la prima volta, quante volte è successo, e la traccia tecnica.',
    async () => {
      await apri.click();
      await page.waitForURL('**/piattaforma/errori/*');
      await expect(page.getByRole('heading', { name: 'Occorrenze conservate' })).toBeVisible();
      await page.waitForTimeout(700);
    },
    { su: apri, zoom: 1.3, coda: 1.4 },
  );

  const input = page.getByRole('heading', { name: 'Input', level: 3 }).locator('..');
  await expect(input).toContainText('data_installazione');
  await expect(input).not.toContainText('password');
  await expect(input).not.toContainText('Segreta-2026');
  // In fondo alla pagina: al centro, o lo zoom lo tiene sul bordo.
  await input.evaluate((el) => el.scrollIntoView({ block: 'center' }));

  await s.scatto('Il contesto viene ripulito PRIMA di essere salvato: password e segreti non entrano nel database, non vengono mascherati dopo.', {
    su: input,
    zoom: 1.8,
    durata: 8,
  });

  s.chiusura('Da ricordare', [
    'Gli errori restano dentro Easy Lab: mandarli fuori avrebbe significato mandare fuori dati dei clienti.',
    'La pagina è del solo Developer, Superadmin compreso fra chi non la vede.',
    'La ripulitura avviene prima del salvataggio: i segreti non entrano nel database.',
  ]);

  await s.scrivi();
});

import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida F2 — «Il catalogo dei ricambi». Formato «vetrina»
 * (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (8, in
 * tre capitoli da 2, 3 e 3): i testi scritti in `testi/catalogo-ricambi.md`
 * sono numerati come i passi.
 *
 * ⚠️ Il seme non ha grafie doppie, e la terza parte parlava di una cosa che a
 * schermo non c'era. Il copione ne crea una prima di girare — tre montaggi
 * della «Guarnizione di tenuta» spostati su una voce «Guarnizione tenuta» — e
 * poi la unisce davvero, così il conteggio torna quello di partenza.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('catalogo ricambi', async ({ page }) => {
  const s = new Scenografo(page, 'catalogo-ricambi', 'Il catalogo dei ricambi', 'I pezzi montati sulle macchine, raccolti per nome');

  artisan(
    '$o = \\App\\Models\\Ricambio::withoutGlobalScopes()->where("nome","Guarnizione di tenuta")->firstOrFail();' +
      ' $d = \\App\\Models\\Ricambio::withoutGlobalScopes()->create(["tenant_id"=>$o->tenant_id,"reseller_id"=>$o->reseller_id,"nome"=>"Guarnizione tenuta"]);' +
      ' $ids = \\App\\Models\\RicambioUtilizzo::withoutGlobalScopes()->where("ricambio_id",$o->id)->orderBy("id")->limit(3)->pluck("id");' +
      ' \\App\\Models\\RicambioUtilizzo::withoutGlobalScopes()->whereIn("id",$ids)->update(["ricambio_id"=>$d->id]); echo "ok";',
  );

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/ricambi');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: che cos'è ──────────────────────────────────────────────────────────
  s.cartello("Che cos'è", 'Parte 1 di 3');

  await s.scatto('«Ricambi» raccoglie i pezzi che sono stati montati sulle macchine, non un magazzino di quelli a scorta.', {
    su: page.getByRole('heading', { name: 'Ricambi' }),
    zoom: 1.7,
    durata: 7,
  });

  await s.scatto("Nessuno lo compila a mano: si costruisce da solo annotando gli interventi, e si interroga scrivendo il nome di un pezzo.", {
    su: page.getByText(/Scrivi il nome di un pezzo/),
    zoom: 1.5,
    durata: 7.5,
  });

  // ── Due: cercare un pezzo ───────────────────────────────────────────────────
  s.cartello('Cercare un pezzo', 'Parte 2 di 3');

  const ricerca = page.getByPlaceholder(/Cerca un ricambio/);
  await s.movimento(
    'La ricerca lavora sul nome del pezzo, e basta una parola.',
    async () => {
      await ricerca.click();
      await ricerca.pressSequentially('guarnizione', { delay: 110 });
      await page.waitForTimeout(1200);
      await expect(page.getByRole('heading', { name: 'Guarnizione tenuta' })).toBeVisible();
    },
    { su: ricerca, zoom: 1.3, coda: 1, suono: 'tastiera', durata: 6 },
  );

  const voce = page.getByRole('heading', { name: 'Guarnizione di tenuta' });
  const intestazione = voce.locator('..');
  await s.scatto('Per ogni pezzo, su quante macchine è montato e quanti pezzi in tutto, con i laboratori in cui stanno.', {
    su: intestazione,
    zoom: 1.4,
    durata: 7,
  });

  const tabella = page.locator('table').filter({ hasText: 'MATRICOLA' }).first();
  await s.scatto('Sotto, una riga per macchina: il nome, la matricola, il laboratorio e quanti pezzi monta.', {
    su: tabella.locator('thead'),
    zoom: 1.3,
    durata: 6.5,
  });

  // ── Tre: le grafie doppie ───────────────────────────────────────────────────
  s.cartello('Le grafie doppie', 'Parte 3 di 3');

  const doppia = page.getByRole('heading', { name: 'Guarnizione tenuta' });
  await s.scatto("Il nome del pezzo lo scrive chi registra l'intervento, quindi lo stesso ricambio può finire con due grafie diverse.", {
    su: doppia.locator('..'),
    zoom: 1.4,
    durata: 7.5,
  });

  const unisci = doppia.locator('..').getByRole('button', { name: /Unisci/ });
  await s.movimento(
    '«Unisci» serve a quello: si sceglie il nome buono e le due voci diventano una, senza perdere nessun montaggio.',
    async () => {
      await unisci.click();
      const destinazione = page.locator('#destinazioneId');
      await expect(destinazione).toBeVisible();
      await page.waitForTimeout(500);
      await destinazione.selectOption({ index: 1 });
      await expect(destinazione.locator('option:checked')).toContainText('Guarnizione di tenuta');
      await page.waitForTimeout(800);
    },
    { su: unisci, zoom: 1.4, coda: 1, durata: 7.5 },
  );

  const conferma = page.locator('form[wire\\:submit="unisci"]').getByRole('button', { name: 'Unisci' });
  await s.movimento(
    'Vale la pena farlo appena ci si accorge: più a lungo convivono, più montaggi finiscono sulla grafia sbagliata.',
    async () => {
      await conferma.click();
      await expect(page.locator('#destinazioneId')).toBeHidden({ timeout: 10_000 });
      await expect(doppia).toHaveCount(0);
      await page.waitForTimeout(600);
      await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
      await page.waitForTimeout(1200);
    },
    { su: conferma, zoom: 1.3, coda: 1.4, durata: 7.5 },
  );

  s.chiusura('Da ricordare', [
    'Il catalogo non è un magazzino: raccoglie i pezzi già montati, e si costruisce annotando gli interventi.',
    'Da un pezzo si arriva alle macchine su cui sta, con matricola, laboratorio e quantità.',
    '«Unisci» risolve le grafie doppie dello stesso ricambio senza perdere i montaggi.',
  ]);

  await s.scrivi();
});

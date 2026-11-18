import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida D3 — «Lo Scadenzario». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (10, in
 * tre capitoli da 4, 3 e 3): i testi scritti in `testi/scadenzario.md` sono
 * numerati come i passi.
 *
 * Girata col **Responsabile Reparto**. ⚠️ Nel seme Giulia non ha interventi
 * assegnati, e «Solo i miei» dava un elenco vuoto mentre la frase parlava della
 * lista del mattino: il copione gliene assegna tre scaduti prima di girare.
 */
test('scadenzario', async ({ page }) => {
  const s = new Scenografo(page, 'scadenzario', 'Lo Scadenzario', 'Tutto quello che è aperto, su tutte le macchine');

  await accedi(page, 'giulia.ferrari@aurora.test');
  await page.goto('/scadenzario');
  await page.waitForLoadState('networkidle');

  // Tre scaduti fra quelli che Giulia vede, per strumento e descrizione.
  const righe = page.locator('tbody tr').filter({ hasText: 'Scaduto' });
  const coppie: string[] = [];
  for (let i = 0; i < 3; i++) {
    const r = righe.nth(i);
    const id = ((await r.locator('a').first().getAttribute('href')) ?? '').split('/').pop();
    const descr = ((await r.locator('td').nth(5).textContent()) ?? '').trim().replace(/"/g, '\\"');
    coppie.push(`[${id}, "${descr}"]`);
  }
  artisan(
    `$g = \\App\\Models\\User::where("email","giulia.ferrari@aurora.test")->value("id");` +
      ` foreach ([${coppie.join(',')}] as [$s, $d]) { \\App\\Models\\Intervento::withoutGlobalScopes()` +
      `->where("strumento_id",$s)->where("descrizione",$d)->whereNull("data_esecuzione")->update(["tecnico_id"=>$g]); } echo "ok";`,
  );
  await page.reload();
  await page.waitForLoadState('networkidle');
  await s.avvia();

  // ── Uno: il punto di vista rovesciato ───────────────────────────────────────
  s.cartello('Il punto di vista rovesciato', 'Parte 1 di 3');

  await s.scatto('Lo Scadenzario parte dalle scadenze invece che dalle macchine: le raccoglie tutte in un elenco solo.', {
    su: page.getByRole('heading', { name: 'Scadenzario' }),
    zoom: 1.7,
    durata: 7,
  });

  const scaduti = page.getByRole('button', { name: /^Scaduti/ });
  await s.scatto('In cima ci sono tre riquadri: scaduti, in scadenza, e più avanti nel tempo.', {
    su: scaduti.locator('..'),
    zoom: 1.2,
    alone: false,
    durata: 6,
  });

  await s.movimento(
    '«Scaduti» è il riquadro da guardare per primo: interventi la cui data è passata e che nessuno ha ancora segnato come fatti.',
    async () => {
      await scaduti.click();
      await page.waitForTimeout(900);
    },
    { su: scaduti, zoom: 1.5, coda: 1, durata: 7.5 },
  );

  await s.scatto("Cliccando un riquadro l'elenco si restringe a quelle righe, ed è il modo più rapido di costruirsi la lista del giorno.", {
    su: page.locator('thead'),
    zoom: 1.1,
    alone: false,
    durata: 7.5,
  });

  // ── Due: restringere ────────────────────────────────────────────────────────
  s.cartello('Restringere', 'Parte 2 di 3');

  const ricerca = page.getByPlaceholder(/Cerca per macchina/);
  await s.movimento(
    'La ricerca guarda la macchina e la descrizione insieme: si trova per nome di strumento o per che cosa va fatto.',
    async () => {
      await ricerca.click();
      await ricerca.pressSequentially('taratura', { delay: 120 });
      await page.waitForTimeout(1200);
      await expect(page.locator('tbody tr').filter({ hasText: /taratura/i }).first()).toBeVisible();
    },
    { su: ricerca, zoom: 1.3, coda: 1, suono: 'tastiera', durata: 7.5 },
  );

  const tipo = page.locator('select').filter({ hasText: 'Tutti i tipi' });
  await s.movimento(
    'Il tipo separa la manutenzione ordinaria dalle tarature, dalle straordinarie e dal resto.',
    async () => {
      await ricerca.fill('');
      await page.waitForTimeout(500);
      await tipo.selectOption({ label: 'Taratura e certificazione' });
      await page.waitForTimeout(1000);
    },
    { su: tipo, zoom: 1.4, coda: 1, durata: 6.5 },
  );

  const soloMiei = page.getByLabel('Solo i miei');
  await s.movimento(
    '«Solo i miei» lascia gli interventi assegnati a te: è la lista con cui si comincia la giornata.',
    async () => {
      await tipo.selectOption({ index: 0 });
      await page.waitForTimeout(500);
      await soloMiei.check();
      await page.waitForTimeout(1000);
      await expect(page.locator('tbody tr').filter({ hasText: 'Giulia Ferrari' })).toHaveCount(3);
    },
    { su: soloMiei, zoom: 1.4, coda: 1.2, durata: 7 },
  );

  // ── Tre: le righe ───────────────────────────────────────────────────────────
  s.cartello('Le righe', 'Parte 3 di 3');

  const prima = page.locator('tbody tr').filter({ hasText: 'Giulia Ferrari' }).first();
  await s.scatto('Ogni riga porta la data, lo stato, la macchina, dove sta e a chi è assegnata.', {
    su: prima,
    zoom: 1.3,
    durata: 7,
  });

  const inScadenza = page.getByRole('button', { name: /^In scadenza/ });
  await s.movimento(
    'Una data passata si legge in colore, accanto a «Scaduto»; quelle in arrivo restano neutre e dicono «Pianificato».',
    async () => {
      await soloMiei.uncheck();
      await page.waitForTimeout(400);
      await inScadenza.click();
      await page.waitForTimeout(1200);
    },
    { su: inScadenza, zoom: 1.1, coda: 1.4, durata: 7 },
  );

  const macchina = page.locator('tbody tr a').first();
  await s.movimento(
    "Dalla riga si apre la macchina, e da lì si segna l'intervento come fatto: è il giro completo.",
    async () => {
      await macchina.click();
      await page.waitForURL(/\/strumenti\/\d+/);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(500);
    },
    { su: macchina, zoom: 1.4, coda: 1.2, durata: 7 },
  );

  s.chiusura('Da ricordare', [
    "L'elenco degli strumenti parte dalle macchine, lo Scadenzario parte dalle scadenze: stessa verità, due domande diverse.",
    'I tre riquadri in cima sono filtri già pronti: scaduti, in scadenza, più avanti.',
    '«Solo i miei» trasforma la vista di laboratorio nella propria lista di lavoro.',
  ]);

  await s.scrivi();
});

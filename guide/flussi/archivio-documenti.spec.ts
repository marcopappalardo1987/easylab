import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida G2 — «L'archivio dei documenti». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (9, in
 * tre capitoli da 2, 4 e 3): i testi scritti in `testi/archivio-documenti.md`
 * sono numerati come i passi.
 *
 * ⚠️ Nel seme l'archivio è VUOTO: la guida di prima raccontava filtri e righe
 * sopra «Nessun documento caricato». I file si caricano qui, dalla scheda delle
 * macchine come farebbe chiunque, prima che la ripresa cominci. Il copione
 * SCRIVE: `azzera.sh` prima di ogni giratura (lo fa `gira-in-fila.sh`).
 */
const PDF = fs.readFileSync(path.resolve(process.cwd(), 'fixture', 'certificato-taratura.pdf'));

const carica = async (page: Page, scheda: string, nome: string, tipo: string, aUnIntervento = false) => {
  await page.goto(scheda);
  await page.getByRole('button', { name: 'Documenti' }).first().click();
  await page.getByRole('button', { name: /Carica documento/ }).click();
  await page.setInputFiles('#fileDocumento', { name: nome, mimeType: 'application/pdf', buffer: PDF });
  await page.selectOption('#tipoDocumento', tipo);
  if (aUnIntervento) await page.locator('#documentoInterventoId').selectOption({ index: 1 });
  const invia = page.locator('form:has(#fileDocumento)').getByRole('button', { name: 'Carica' });
  await expect(invia).toBeEnabled();
  await invia.click();
  await expect(page.locator('#fileDocumento')).toHaveCount(0, { timeout: 10_000 });
  await expect(page.getByRole('cell', { name: nome })).toBeVisible();
};

test('archivio documenti', async ({ page }) => {
  const s = new Scenografo(page, 'archivio-documenti', "L'archivio dei documenti", "Tutti gli allegati dell'Ente, in un posto solo");

  await accedi(page, 'maria.conti@aurora.test');

  // Due macchine della sede: l'Autoclave delle altre guide e la prima dell'elenco.
  await page.goto('/strumenti');
  const altra = (await page.locator('tbody tr a[href*="/strumenti/"]').first().getAttribute('href'))!;
  const autoclave = '/strumenti/55';

  await carica(page, autoclave, 'Manuale Autoclave Steri-200.pdf', 'manuale');
  await carica(page, autoclave, 'Certificato taratura 2026 - Autoclave.pdf', 'certificato_taratura', true);
  await carica(page, altra, 'Certificato taratura 2026.pdf', 'certificato_taratura');
  await carica(page, altra, 'Dichiarazione di conformita CE.pdf', 'conformita');
  await carica(page, altra, 'Rapporto fine lavoro manutenzione.pdf', 'report_fine_lavoro');

  await page.goto('/documenti');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('tbody tr')).toHaveCount(5);
  await s.avvia();

  // ── Uno: tutto insieme ──────────────────────────────────────────────────────
  s.cartello('Tutto insieme', 'Parte 1 di 3');

  await s.scatto('«Documenti» nel menù raccoglie gli allegati di tutte le macchine della sede: gli stessi file delle schede, visti insieme.', {
    su: page.getByRole('heading', { name: 'Documenti', level: 1 }),
    zoom: 1.7,
    durata: 7,
  });

  await s.panoramica('Serve quando non sai su quale macchina sta il file che cerchi, o quando ne cerchi molti dello stesso tipo.', 7);

  // ── Due: trovare ────────────────────────────────────────────────────────────
  s.cartello('Trovare', 'Parte 2 di 3');

  const cerca = page.locator('#doc-cerca');

  await s.movimento(
    'La ricerca lavora sul nome del file.',
    async () => {
      await cerca.click();
      await cerca.pressSequentially('taratura', { delay: 110 });
      await expect(page.locator('tbody tr')).toHaveCount(2);
      await page.waitForTimeout(500);
    },
    { su: cerca, zoom: 1.3, coda: 1, suono: 'tastiera' },
  );

  await page.locator('#doc-cerca').fill('');
  await page.locator('#doc-cerca').blur();
  await expect(page.locator('tbody tr')).toHaveCount(5);

  const tipo = page.locator('#doc-tipo');

  await s.movimento(
    "Il filtro per tipo separa manuali, certificati e rapporti: è il modo di tirare fuori tutti i certificati di taratura dell'anno.",
    async () => {
      await tipo.selectOption('certificato_taratura');
      await expect(page.locator('tbody tr')).toHaveCount(2);
      await page.waitForTimeout(600);
    },
    { su: tipo, zoom: 1.3, coda: 1.2 },
  );

  const macchina = page.locator('#doc-macchina');

  await s.movimento(
    'E si può restringere a una macchina sola, quando si sa già quale.',
    async () => {
      await macchina.selectOption('55');
      await expect(page.locator('tbody tr')).toHaveCount(1);
      await page.waitForTimeout(600);
    },
    { su: macchina, zoom: 1.3, coda: 1.2 },
  );

  await s.scatto("Ogni riga dice a che cosa è allegato il file: alla macchina, o a un intervento preciso, come questo certificato.", {
    su: page.locator('tbody tr').first().locator('td[data-etichetta="Allegato a"]'),
    zoom: 1.8,
    durata: 7,
  });

  // ── Tre: portarli fuori ─────────────────────────────────────────────────────
  s.cartello('Portarli fuori', 'Parte 3 di 3');

  await s.scatto("«Scarica» prende il singolo file, e passa dall'applicazione: il permesso viene ricontrollato e lo scarico registrato.", {
    su: page.locator('tbody tr').first().getByRole('link', { name: 'Scarica' }),
    zoom: 2.2,
    durata: 7.5,
  });

  // L'archivio torna pieno: l'indice si racconta sull'elenco intero.
  await tipo.selectOption('');
  await macchina.selectOption('');
  await expect(page.locator('tbody tr')).toHaveCount(5);

  await s.scatto("«Indice PDF» prende invece l'elenco: non i file, la loro lista con i filtri che hai applicato.", {
    su: page.getByRole('link', { name: 'Indice PDF' }),
    zoom: 2.2,
    durata: 7,
  });

  const dal = page.locator('#doc-dal');

  await s.movimento(
    "Per una verifica si sceglie il periodo con «Dal» e «Al», e l'indice dice che cosa c'è in archivio per quel periodo.",
    async () => {
      // `focus()` e non `click()`: il clic cade su un segmento a caso della data,
      // il fuoco da tastiera parte dal primo. Le date sono gg/mm/aaaa: lo
      // garantisce `--lang=it-IT` in playwright.config.ts.
      await dal.focus();
      await page.keyboard.type('01012026', { delay: 120 });
      await page.locator('#doc-al').focus();
      await page.keyboard.type('31122026', { delay: 120 });
      await expect(dal).toHaveValue('2026-01-01');
      await expect(page.locator('#doc-al')).toHaveValue('2026-12-31');
      await expect(page.locator('tbody tr')).toHaveCount(5);
      await page.waitForTimeout(500);
    },
    { su: dal, zoom: 1.3, coda: 1.2, suono: 'tastiera' },
  );

  s.chiusura('Da ricordare', [
    "L'archivio sono gli stessi file delle schede, raccolti: si usa quando non sai su quale macchina cercare.",
    'I filtri per tipo, macchina e periodo si sommano alla ricerca sul nome del file.',
    "L'«Indice PDF» dà l'elenco filtrato, non i file: serve a dimostrare che cosa c'è.",
  ]);

  await s.scrivi();
});

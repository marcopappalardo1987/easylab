import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida C2 — «La scheda di una macchina». Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ Stessa sequenza e stesso numero di passi del copione precedente (12, in
 * tre capitoli da 4, 5 e 3): i testi scritti in `testi/scheda-strumento.md`
 * sono numerati come i passi.
 *
 * La macchina è il pHmetro 45 e non più l'autoclave 55: ha un intervento
 * pianificato (il riquadro «Prossimo intervento» non è vuoto) e il numerino
 * delle scadenze da gestire su tutte e tre le linguette che lo portano (ADR-055).
 * Il referente (ADR-054) il seme non lo ha: lo scrive il copione, perché la
 * linguetta «Anagrafica» lo deve mostrare.
 */
test('scheda strumento', async ({ page }) => {
  const s = new Scenografo(page, 'scheda-strumento', 'La scheda di una macchina', 'Panoramica e le sei linguette');

  artisan(
    '\\App\\Models\\Strumento::withoutGlobalScopes()->findOrFail(45)->forceFill(["referente_nome" => "Paolo", "referente_cognome" => "Ricci",' +
      ' "referente_email" => "paolo.ricci@aurora.test", "referente_cellulare" => "333 1234567"])->save(); echo "ok";',
  );

  await accedi(page, 'maria.conti@aurora.test');
  await page.goto('/strumenti/45');
  await page.waitForLoadState('networkidle');
  await expect(page.locator('[data-tab="interventi"] [data-da-gestire]')).toBeVisible();
  await s.avvia();

  // ── Uno: la panoramica ──────────────────────────────────────────────────────
  s.cartello('La panoramica', 'Parte 1 di 3');

  await s.scatto('La scheda apre sempre sulla Panoramica: è il riassunto, e serve a decidere se serve andare più a fondo.', {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 6.5,
  });

  await s.scatto('«In sintesi» dice le tre cose che si cercano per prime: chi la assiste, dove sta, da quando è in servizio.', {
    su: page.getByText('In sintesi').locator('..'),
    zoom: 1.7,
    durata: 7,
  });

  await s.scatto('Il prossimo intervento è la scadenza più vicina: è quella che accenderà il semaforo quando arriva.', {
    su: page.getByText('Prossimo intervento').locator('..'),
    zoom: 1.7,
    durata: 7,
  });

  await s.scatto('Le statistiche contano la vita della macchina: interventi fatti, scaduti non fatti, ricambi montati.', {
    su: page.getByText('Statistiche').locator('..'),
    zoom: 1.6,
    durata: 7,
  });

  // ── Due: le linguette ───────────────────────────────────────────────────────
  s.cartello('Le linguette', 'Parte 2 di 3');

  const tab = (nome: string) => page.locator(`[data-tab="${nome}"]`);
  await page.evaluate(() => window.scrollTo(0, 0));

  await s.scatto(
    'Sei linguette, e il numerino accanto a Interventi, Ricambi e Garanzie conta le scadenze da gestire lì dentro.',
    {
      su: tab('panoramica').locator('..'),
      zoom: 1.6,
      durata: 7.5,
    },
  );

  const apri = async (nome: string, attesa: () => Promise<void>) => {
    await tab(nome).click();
    await attesa();
    await page.waitForTimeout(900);
  };

  await s.movimento(
    '«Interventi» è lo storico della manutenzione: è la fonte di verità, il semaforo ne è solo il riassunto.',
    () => apri('interventi', () => expect(page.locator('[x-show="tab === \'interventi\'"]')).toBeVisible()),
    { su: tab('interventi'), zoom: 1.2, coda: 1.4 },
  );

  await s.movimento(
    '«Garanzie» tiene la copertura della macchina intera; quella dei pezzi montati sta in «Ricambi», riga per riga.',
    () => apri('garanzie', () => expect(page.locator('[x-show="tab === \'garanzie\'"]')).toBeVisible()),
    { su: tab('garanzie'), zoom: 1.2, coda: 1.4 },
  );

  await s.movimento(
    '«Documenti» raccoglie manuali, certificati e rapporti: si allegano qui e si ritrovano qui.',
    () => apri('documenti', () => expect(page.locator('[x-show="tab === \'documenti\'"]')).toBeVisible()),
    { su: tab('documenti'), zoom: 1.2, coda: 1.4 },
  );

  await s.movimento(
    "«Anagrafica» tiene i dati fissi, il referente con i suoi contatti e lo storico degli spostamenti.",
    () => apri('anagrafica', () => expect(page.getByText('paolo.ricci@aurora.test')).toBeVisible()),
    { su: tab('anagrafica'), zoom: 1.2, coda: 1.4 },
  );

  // ── Tre: le azioni ──────────────────────────────────────────────────────────
  s.cartello('Quello che si può fare da qui', 'Parte 3 di 3');

  await page.evaluate(() => window.scrollTo(0, 0));

  await s.scatto('In alto ci sono le azioni sulla macchina, e compaiono solo se il tuo ruolo può compierle.', {
    su: page.getByRole('button', { name: 'Modifica' }).first().locator('..'),
    zoom: 1.6,
    alone: false,
    durata: 7,
  });

  await s.scatto("«Etichetta QR» stampa l'adesivo da attaccare sulla macchina, per ritrovarla col telefono.", {
    su: page.getByRole('link', { name: /Etichetta QR/ }).first(),
    zoom: 2.2,
    durata: 6.5,
  });

  await s.scatto('«Forza semaforo» esiste per i casi che la manutenzione non descrive, e chiede sempre una motivazione.', {
    su: page.getByRole('button', { name: /Forza semaforo/ }).first(),
    zoom: 2.2,
    durata: 7,
  });

  s.chiusura('Da ricordare', [
    'La Panoramica è il riassunto; la verità sta nelle linguette, e in particolare nello storico degli interventi.',
    'Il numerino su una linguetta conta le scadenze da gestire lì dentro: dove non compare, non c\'è niente da fare.',
    'Le azioni in alto compaiono solo se il tuo ruolo può compierle: due persone vedono due schede diverse.',
  ]);

  await s.scrivi();
});

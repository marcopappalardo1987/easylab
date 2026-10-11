import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';

/**
 * Guida A2 — «Orientarsi»: la Dashboard, il menù, la barra in alto.
 * Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (13, in
 * tre capitoli da 5, 5 e 3). Non è pigrizia: i testi scritti della pagina stanno
 * in `testi/orientarsi.md`, numerati come i passi, e cambiare l'ordine
 * significherebbe rifarli tutti — cioè rischiare di spostare una spiegazione
 * sotto il passo sbagliato per guadagnare niente.
 *
 * Qui la cosa nuova è il MOVIMENTO: i riquadri della Dashboard che portano
 * all'elenco, il menù che cambia pagina, la campanella che si apre. Erano tutte
 * cose raccontate con due fermi immagine, e sono tutte cose che si vedono
 * accadere.
 */
test('orientarsi', async ({ page }) => {
  const s = new Scenografo(page, 'orientarsi', 'Orientarsi', 'La Dashboard, il menù e la barra in alto');

  await page.goto('/login');
  await page.fill('#email', 'giulia.ferrari@aurora.test');
  await page.fill('#password', 'guida-demo');
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');
  await s.avvia();

  // ── Uno: la Dashboard ───────────────────────────────────────────────────────
  s.cartello('La Dashboard', 'Parte 1 di 3');

  await s.panoramica('Entrando si apre sempre la Dashboard: lo stato delle macchine della tua sede.', 6);

  await s.scatto(
    'Verde strumentazione idonea, giallo interventi necessari, rosso strumenti non idonei. Sono i tre stati, e coprono tutte le macchine.',
    {
      // I tre riquadri insieme: il padre dei link è la griglia che li contiene.
      su: page.getByRole('link', { name: /Strumentazione idonea/ }).locator('..'),
      zoom: 1.15,
      alone: false,
      durata: 7,
    },
  );

  await s.scatto("Gli strumenti obsoleti sono a parte: è una segnalazione sull'età, non uno stato di manutenzione.", {
    su: page.getByRole('link', { name: /Strumenti obsoleti/ }),
    zoom: 1.7,
    durata: 6.5,
  });

  await s.movimento(
    "Ogni riquadro è un filtro già pronto: si clicca e si apre l'elenco di quelle macchine.",
    async () => {
      await page.getByRole('link', { name: /Interventi necessari/ }).click();
      await page.waitForURL('**/strumenti**');
      await expect(page.locator('tbody tr').first()).toBeVisible();
    },
    { su: page.getByRole('link', { name: /Interventi necessari/ }), zoom: 1.3, coda: 1.2 },
  );

  await s.scatto("L'elenco arriva già filtrato su quello che hai chiesto.", {
    su: page.locator('select').nth(1),
    zoom: 1.6,
    durata: 5.5,
  });

  // ── Due: il menù di sinistra ────────────────────────────────────────────────
  s.cartello('Il menù', 'Parte 2 di 3');

  await s.scatto('Il menù resta sempre lì, e cambia con quello che il tuo ruolo può fare.', {
    // ⚠️ Zoom 1: la barra è alta quasi quanto la pagina, e stringendo le
    // resterebbero i bordi fuori campo — cioè niente alone (vedi
    // `staInFinestra` in lib/scenografo.ts).
    su: page.getByRole('navigation', { name: 'Menù principale' }),
    zoom: 1,
    durata: 6,
  });

  await s.movimento(
    "«Laboratori» è l'albero della sede: laboratori, sotto-laboratori, e le macchine dentro.",
    async () => {
      await page.getByRole('link', { name: 'Laboratori', exact: true }).click();
      await page.waitForURL('**/laboratori**');
      await page.waitForTimeout(500);
    },
    { su: page.getByRole('link', { name: 'Laboratori', exact: true }), zoom: 1.5, coda: 1 },
  );

  await s.panoramica('Si scende un nodo alla volta, e il percorso in alto dice sempre dove sei.', 6);

  /*
   * ⚠️ Il giro delle ALTRE voci. Senza, il capitolo sul menù ne mostrava due su
   * otto e sembrava che le altre non esistessero — in una guida che si chiama
   * «Orientarsi» è proprio la cosa da non fare. Una clip sola che le apre in
   * fila costa otto secondi e le copre tutte; ognuna ha poi la sua guida, e
   * fermarsi a spiegarle qui sarebbe raccontare sei volte la stessa cosa.
   */
  await s.movimento(
    'Le altre voci sono gli elenchi veri: strumenti, documenti, fornitori, ricambi, e «Campo» per il telefono.',
    async () => {
      for (const voce of ['Strumenti', 'Documenti', 'Fornitori', 'Ricambi', 'Campo']) {
        await page.getByRole('link', { name: voce, exact: true }).click();
        await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
        await page.waitForTimeout(850);
      }
    },
    { coda: 0.8 },
  );

  await s.movimento(
    '«Scadenzario» raccoglie in un elenco solo tutto ciò che è aperto su tutte le macchine.',
    async () => {
      await page.getByRole('link', { name: 'Scadenzario', exact: true }).click();
      await page.waitForURL('**/scadenzario**');
      await page.waitForTimeout(500);
    },
    { su: page.getByRole('link', { name: 'Scadenzario', exact: true }), zoom: 1.5, coda: 1 },
  );

  await s.panoramica('Le date rosse sono passate, le gialle stanno arrivando.', 6);

  // ── Tre: in alto a destra ───────────────────────────────────────────────────
  s.cartello('In alto a destra', 'Parte 3 di 3');

  await s.movimento(
    "La campanella porta le stesse scadenze, dentro l'applicazione.",
    async () => {
      await page.getByRole('button', { name: 'Notifiche' }).click();
      await page.waitForTimeout(900);
    },
    { su: page.getByRole('button', { name: 'Notifiche' }), zoom: 1.6, coda: 1.1 },
  );

  await s.movimento(
    "Il tuo nome apre il menù personale: tema, preferenze, e l'uscita.",
    async () => {
      await page.getByRole('button', { name: /Giulia Ferrari/ }).click();
      await page.waitForTimeout(900);
    },
    { su: page.getByRole('button', { name: /Giulia Ferrari/ }), zoom: 1.6, coda: 1.1 },
  );

  await s.scatto("Sotto il nome c'è sempre scritto il tuo ruolo: è quello che decide che cosa vedi.", {
    su: page.getByRole('button', { name: /Giulia Ferrari/ }),
    zoom: 2,
    durata: 6.5,
  });

  s.chiusura('Da ricordare', [
    "La Dashboard è il riassunto: tre stati che coprono tutte le macchine, più la segnalazione sull'età.",
    "Ogni riquadro è un filtro già pronto verso l'elenco degli strumenti.",
    'Il menù di sinistra mostra solo ciò che il tuo ruolo può fare: due persone diverse vedono due menù diversi.',
  ]);

  await s.scrivi();
});

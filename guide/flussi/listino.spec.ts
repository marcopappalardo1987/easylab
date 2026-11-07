import { expect, test } from '@playwright/test';
import { Scenografo } from '../lib/scenografo';
import { accedi } from '../lib/accesso';

/**
 * Guida M10 — «Il listino». Guida INTERNA. Formato «vetrina» (🔗 `lib/scenografo.ts`).
 *
 * ⚠️ **Stessa sequenza e stesso numero di passi del copione precedente** (6, in
 * due capitoli da 3): i testi scritti in `testi/listino.md` sono numerati come
 * i passi.
 *
 * Il listino ora ha anche un **tetto di strumenti** per piano (ADR-049), e il
 * piano senza canone si chiama «comodato d'uso» (ADR-050): li mostra la
 * finestra di modifica, che si apre e si richiude senza salvare.
 *
 * ⛔ Nessun gesto che parli con Stripe: niente «Sincronizza», «Confronta con
 * Stripe», «Aggancia un price», e nessun salvataggio (un prezzo cambiato
 * marcherebbe il piano da sincronizzare). Il copione non scrive.
 */
test('listino', async ({ page }) => {
  const s = new Scenografo(page, 'listino', 'Il listino', 'I piani commerciali, i loro tetti e i prezzi');

  await accedi(page, 'superadmin@easylab.test');
  await page.goto('/piattaforma/piani');
  await page.waitForLoadState('networkidle');
  await s.avvia();

  const riga = (nome: string) => page.locator('tbody tr', { has: page.getByText(nome, { exact: true }) });
  const comodato = riga("Comodato d'uso");
  const saas = riga('SaaS');

  // ── Uno: i piani ────────────────────────────────────────────────────────────
  s.cartello('I piani', 'Parte 1 di 2');

  await s.scatto('Il listino tiene i piani che si possono vendere, con i loro tetti di sedi e di strumenti e il prezzo corrente.', {
    su: page.locator('table').filter({ has: comodato }),
    zoom: 1.3,
    alone: false,
    durata: 7.5,
  });

  const modificaComodato = comodato.getByRole('button', { name: 'Modifica' });

  await s.movimento(
    "I tetti sono quelli che il cliente vede nella propria pagina «Abbonamento»: qui si decide, là si subisce.",
    async () => {
      await modificaComodato.click();
      await expect(page.locator('#max_strumenti')).toBeVisible();
      await page.waitForTimeout(600);
      await page.locator('#max_strumenti').scrollIntoViewIfNeeded();
      await page.waitForTimeout(900);
    },
    { su: modificaComodato, zoom: 1.3, coda: 1.6 },
  );

  await page.getByRole('button', { name: 'Annulla' }).click();
  await expect(page.locator('#max_strumenti')).toHaveCount(0);

  await s.scatto("Il prezzo agganciato a Stripe è ciò che rende vendibile un piano: senza, la registrazione lo dice invece di ripiegare sul comodato d'uso.", {
    su: saas.locator('td').nth(3),
    zoom: 2.2,
    durata: 8,
  });

  // ── Due: cambiare un prezzo ─────────────────────────────────────────────────
  s.cartello('Cambiare un prezzo', 'Parte 2 di 2');

  const modificaSaas = saas.getByRole('button', { name: 'Modifica' });
  const prezzo = page.locator('#prezzo_mensile_cent');

  await s.movimento(
    'I prezzi hanno uno storico: quello nuovo diventa corrente, i vecchi restano per i contratti già firmati.',
    async () => {
      await modificaSaas.click();
      await expect(prezzo).toBeVisible();
      await page.waitForTimeout(600);
      await prezzo.scrollIntoViewIfNeeded();
      await page.waitForTimeout(900);
    },
    { su: modificaSaas, zoom: 1.3, coda: 1.6 },
  );

  await s.scatto("È il motivo per cui un cliente entrato l'anno scorso continua a pagare il prezzo di allora finché non cambia piano.", {
    su: page.getByText(/chi è già abbonato resta sul suo/),
    zoom: 2,
    durata: 7.5,
  });

  await page.getByRole('button', { name: 'Annulla' }).click();
  await expect(prezzo).toHaveCount(0);

  await s.scatto('Archiviare un piano lo toglie dalla vendita e non tocca chi ce l\'ha già: nessun cliente cambia condizioni per una decisione di listino.', {
    su: saas.getByRole('button', { name: 'Archivia' }),
    zoom: 2.2,
    durata: 8,
  });

  s.chiusura('Da ricordare', [
    'Il listino decide i tetti di sedi e di strumenti che i clienti vedranno nella propria pagina Abbonamento.',
    'I prezzi hanno uno storico: cambiarne uno non tocca i contratti già in corso.',
    'Un piano senza prezzo Stripe non è vendibile, e la registrazione lo dichiara invece di ripiegare.',
  ]);

  await s.scrivi();
});

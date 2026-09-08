import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida M10 — «Il listino». Guida INTERNA. */
test('listino', async ({ page }) => {
  const g = new Regista(page, 'listino', 'Il listino', 'I piani commerciali, i loro tetti e i prezzi');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 2', 'I piani');

  await page.goto('/piattaforma/piani');
  await page.waitForLoadState('networkidle');

  await g.passo("Il listino tiene i piani che si possono vendere, con il loro tetto di sedi e il prezzo corrente.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Il tetto è quello che il cliente vede nella propria pagina «Abbonamento»: qui si decide, là si subisce.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Un piano senza prezzo agganciato a Stripe non è vendibile, e la registrazione pubblica lo dice invece di ripiegare su qualcosa di gratuito.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 2', 'Cambiare un prezzo');

  await g.passo("I prezzi hanno uno storico: quello nuovo diventa corrente, i vecchi restano per i contratti già firmati.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È il motivo per cui un cliente entrato l'anno scorso continua a pagare il prezzo di allora finché non cambia piano.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Archiviare un piano lo toglie dalla vendita e non tocca chi ce l'ha già: nessun cliente cambia condizioni per una decisione di listino.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Il listino decide i tetti che i clienti vedranno nella propria pagina Abbonamento.',
    'I prezzi hanno uno storico: cambiarne uno non tocca i contratti già in corso.',
    'Un piano senza prezzo Stripe non è vendibile, e la registrazione lo dichiara invece di ripiegare.',
  ]);

  g.scrivi();
});

import { expect, test } from '@playwright/test';
import { Regista } from '../lib/regista';

/** Guida A2 — «Orientarsi»: la Dashboard, il menù, la barra in alto. */
test('orientarsi', async ({ page }) => {
  const g = new Regista(page, 'orientarsi', 'Orientarsi', 'La Dashboard, il menù e la barra in alto');

  await page.goto('/login');
  await page.fill('#email', 'giulia.ferrari@aurora.test');
  await page.fill('#password', 'guida-demo');
  await page.click('button[type=submit]');
  await page.waitForURL('**/dashboard');

  g.capitolo('Parte 1 di 3', 'La Dashboard');

  await g.passo('Entrando si apre sempre la Dashboard: lo stato delle macchine della tua sede.', {
    durata: 5.5,
  });

  await g.passo('Verde in regola, giallo azione richiesta, rosso non idoneo. Sono i tre stati, e coprono tutte le macchine.', {
    su: page.getByRole('link', { name: /In regola/ }),
    zoom: 1.5,
    durata: 6.5,
  });

  await g.passo("Gli obsoleti sono a parte: è una segnalazione sull'età, non uno stato di manutenzione.", {
    su: page.getByRole('link', { name: /Obsoleti/ }),
    zoom: 2,
    durata: 6,
  });

  await g.passo('Ogni riquadro è un filtro già pronto: si clicca e si apre l\'elenco di quelle macchine.', {
    su: page.getByRole('link', { name: /Azione richiesta/ }),
    click: true,
    zoom: 2,
    durata: 5.5,
  });

  await page.waitForURL('**/strumenti**');
  await expect(page.locator('tbody tr').first()).toBeVisible();

  await g.passo("L'elenco arriva già filtrato su quello che hai chiesto.", { durata: 4.5 });

  g.capitolo('Parte 2 di 3', 'Il menù di sinistra');

  await g.passo('Il menù resta sempre lì, e cambia con quello che il tuo ruolo può fare.', {
    su: page.getByRole('navigation').first(),
    zoom: 1.9,
    durata: 5.5,
  });

  await g.passo("«Anagrafica» è l'albero della sede: dipartimenti, laboratori, e le macchine dentro.", {
    su: page.getByRole('link', { name: 'Anagrafica', exact: true }),
    click: true,
    zoom: 3,
    durata: 5.5,
  });

  await page.waitForURL('**/anagrafica**');

  await g.passo('Si scende un nodo alla volta, e le briciole in alto dicono sempre dove sei.', { durata: 5 });

  await g.passo('«Scadenzario» raccoglie in un elenco solo tutto ciò che è aperto su tutte le macchine.', {
    su: page.getByRole('link', { name: 'Scadenzario', exact: true }),
    click: true,
    zoom: 3,
    durata: 5.5,
  });

  await page.waitForURL('**/scadenzario**');

  await g.passo('Le date rosse sono passate, le gialle stanno arrivando.', { durata: 4.5 });

  g.capitolo('Parte 3 di 3', 'In alto a destra');

  await g.passo('La campanella porta le stesse scadenze, dentro l\'applicazione.', {
    su: page.getByRole('button', { name: 'Notifiche' }),
    click: true,
    zoom: 2.6,
    durata: 5,
  });

  await page.waitForTimeout(600);

  await g.passo('Il tuo nome apre il menù personale: tema, preferenze, e l\'uscita.', {
    su: page.getByRole('button', { name: /Giulia Ferrari/ }),
    click: true,
    zoom: 2.6,
    durata: 5,
  });

  await page.waitForTimeout(500);

  await g.passo('Sotto il nome c\'è sempre scritto il tuo ruolo: è quello che decide che cosa vedi.', {
    su: page.getByRole('button', { name: /Giulia Ferrari/ }),
    zoom: 2.2,
    durata: 5.5,
  });

  g.chiusura('Da ricordare', [
    'La Dashboard è il riassunto: tre stati che coprono tutte le macchine, più la segnalazione sull\'età.',
    'Ogni riquadro è un filtro già pronto verso l\'elenco degli strumenti.',
    'Il menù di sinistra mostra solo ciò che il tuo ruolo può fare: due persone diverse vedono due menù diversi.',
  ]);

  g.scrivi();
});

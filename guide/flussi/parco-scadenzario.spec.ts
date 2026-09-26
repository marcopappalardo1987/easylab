import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida M3 — «Lo scadenzario di tutti i clienti». Guida INTERNA. */
test('parco scadenzario', async ({ page }) => {
  const g = new Regista(page, 'parco-scadenzario', 'Lo scadenzario di tutti i clienti', 'Che cosa scade, su tutto il parco');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 2', 'Le scadenze di tutti');

  await page.goto('/piattaforma/parco/scadenzario');
  await page.waitForLoadState('networkidle');

  await g.passo("È lo Scadenzario che vede un cliente, esteso a tutti: le scadenze aperte di ogni parco, in un elenco solo.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("La colonna del cliente è quella in più: dice a chi appartiene ogni riga.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Serve a vedere in anticipo dove sta per accumularsi lavoro, prima che sia il cliente a telefonare.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 2', 'Come si usa');

  await g.passo("I filtri sono gli stessi: scaduti, in scadenza, tipo di intervento.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Filtrando per un cliente si prepara la telefonata: «avete undici tarature scadute» è un discorso diverso da «come va».", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Anche qui si legge soltanto: per intervenire si impersona, e la traccia porta chi c'era davvero.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "È lo Scadenzario del cliente, esteso a tutti, con la colonna del cliente in più.",
    'Serve a vedere il lavoro che si accumula prima che diventi una telefonata.',
    'In sola lettura: per agire si impersona.',
  ]);

  g.scrivi();
});

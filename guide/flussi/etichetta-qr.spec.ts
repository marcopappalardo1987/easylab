import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida C8 — «L'etichetta QR». */
test('etichetta qr', async ({ page }) => {
  const g = new Regista(page, 'etichetta-qr', "L'etichetta QR", "L'adesivo che porta il telefono sulla scheda giusta");

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'A che cosa serve');

  await page.goto('/strumenti/55');
  await page.waitForLoadState('networkidle');

  await g.passo("«Etichetta QR» sta fra le azioni della scheda, e produce l'adesivo da attaccare sulla macchina.", {
    su: page.getByRole('link', { name: /Etichetta QR/ }).first(),
    zoom: 2.1,
    durata: 7,
  });

  // ⚠️ `goto` e non un click sul pulsante: è un `<a wire:navigate>`, e il click
  // del regista scatta prima della navigazione — la pagina successiva arrivava
  // a volte e a volte no.
  await page.goto('/strumenti/55/qr');
  await page.waitForLoadState('networkidle');

  await g.passo("L'etichetta porta il codice, il nome della macchina, il modello e la matricola: si riconosce anche senza telefono.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Chi lo inquadra arriva su questa scheda dopo aver fatto accesso: il codice non mostra dati da solo.", {
    su: page.getByText(/Indirizzo contenuto nel codice/),
    zoom: 1.9,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Stampare e applicare');

  await g.passo('«Stampa» manda alla stampante la sola etichetta: tutto il resto della pagina non finisce sul foglio.', {
    su: page.getByRole('button', { name: /Stampa/ }).first(),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("Conviene applicarla dove si legge stando in piedi davanti alla macchina, non sul retro né sotto il piano.", {
    zoom: 1,
    durata: 7,
  });

  await g.passo("Su una macchina che scalda o che si lava spesso, vale la pena proteggerla: un adesivo illeggibile è un'etichetta che non c'è.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Se il codice cambia');

  await g.passo('«Rigenera codice» ne crea uno nuovo, e da quel momento il vecchio adesivo non porta più da nessuna parte.', {
    su: page.getByRole('button', { name: /Rigenera/ }).first(),
    zoom: 2.1,
    durata: 8,
  });

  await g.passo("Si usa se un'etichetta è finita dove non doveva, non per ristampare la stessa: per quello basta stampare di nuovo.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "Il QR porta sulla scheda chi ha già accesso a Easy Lab: da solo non mostra niente a un estraneo.",
    "La stampa contiene la sola etichetta, con nome, modello e matricola leggibili a occhio.",
    'Rigenerare il codice invalida gli adesivi già applicati: si fa solo quando serve davvero.',
  ]);

  g.scrivi();
});

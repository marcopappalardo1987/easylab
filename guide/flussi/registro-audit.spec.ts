import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida M8 — «Il registro di audit». Guida INTERNA. */
test('registro audit', async ({ page }) => {
  const g = new Regista(page, 'registro-audit', 'Il registro di audit', 'Chi ha fatto che cosa, e quando');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 3', 'Che cosa registra');

  await page.goto('/piattaforma/audit');
  await page.waitForLoadState('networkidle');

  await g.passo("Il registro raccoglie le scritture di dominio: chi ha creato, modificato o cancellato che cosa, e quando.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Non è un log tecnico: è la memoria delle decisioni, e serve quando due persone ricordano diversamente.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Ogni riga ha un autore, un soggetto e un momento. Aprendola si vedono i valori cambiati, prima e dopo.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', "L'impersonazione");

  await g.passo("Le righe scritte durante un'impersonazione portano il nome di chi c'era davvero dietro, non solo del cliente.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Senza quel timbro il registro direbbe una cosa falsa: che un gesto nostro è stato del cliente.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Vale per quello che succede dentro una richiesta; ciò che parte in coda o da console non ha sessione, quindi non ha timbro — ed è dichiarato.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Che cosa NON si può fare');

  await g.passo("Il registro non si modifica e non si cancella: una riga scritta resta, ed è tutto il suo valore.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Un soggetto cestinato resta nominato: la riga dice «non più presente» invece di sparire.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("L'attribuzione è affidabile a partire da una data dichiarata in pagina: prima di quella, lo storico è quello che è.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Registra le scritture di dominio con autore, soggetto e momento: è la memoria delle decisioni.',
    "Le righe scritte in impersonazione portano chi c'era davvero dietro.",
    'Non si modifica e non si cancella: è la sola ragione per cui ci si può fidare.',
  ]);

  g.scrivi();
});

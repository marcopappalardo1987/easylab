import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/** Guida M4 — «I ricambi su tutto il parco». Guida INTERNA. */
test('parco ricambi', async ({ page }) => {
  const g = new Regista(page, 'parco-ricambi', 'I ricambi su tutto il parco', 'Quali pezzi si consumano, e da chi');

  await accedi(page, 'superadmin@easylab.test');

  g.capitolo('Parte 1 di 2', 'I pezzi di tutti');

  await page.goto('/piattaforma/parco/ricambi');
  await page.waitForLoadState('networkidle');

  await g.passo("È il catalogo dei ricambi esteso a tutti i clienti: quali pezzi sono stati montati, e quante volte.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("La differenza con la vista del cliente è la scala: qui si vedono i consumi di tutto il parco insieme.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("È l'informazione con cui si tratta con un fornitore: quanti pezzi di quel tipo si montano in un anno, su tutti i clienti.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 2', 'A che cosa serve davvero');

  await g.passo("Un pezzo che compare molto più di altri su un certo modello sta segnalando qualcosa sul modello, non sui clienti.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Ed è la vista da aprire quando un lotto risulta difettoso: dice quali clienti hanno quel pezzo montato.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Anche qui si legge soltanto: correggere il nome di un ricambio è un gesto del cliente, non nostro.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'È il catalogo dei ricambi alla scala della piattaforma.',
    'Serve alle trattative coi fornitori e a leggere i difetti ricorrenti di un modello.',
    'Il giorno di un lotto difettoso, dice quali clienti sono coinvolti.',
  ]);

  g.scrivi();
});

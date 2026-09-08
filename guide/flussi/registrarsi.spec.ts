import { test } from '@playwright/test';
import { Regista } from '../lib/regista';

/**
 * Guida L1 — «Aprire un account Easy Lab».
 *
 * ⚠️ È la sola guida che comincia da **non autenticati**: parla a chi Easy Lab
 * non ce l'ha ancora.
 *
 * ⚠️ Il copione si ferma prima di Stripe. Il pagamento avviene su un dominio di
 * terzi, con un'interfaccia che non è di Easy Lab e che cambia quando Stripe
 * vuole: filmarla sarebbe una guida che invecchia da sola.
 *
 * ⚠️ La pagina esiste solo se `REGISTRAZIONE_APERTA` è vera. Il copione la
 * accende per l'ambiente dimostrativo tramite `guide/bin/demo-env.sh`; se
 * dovesse trovare «Registrazioni chiuse», è quella la ragione.
 */
test('registrarsi', async ({ page }) => {
  const g = new Regista(page, 'registrarsi', 'Aprire un account Easy Lab', "Dal modulo pubblico alla propria sede attiva");

  g.capitolo('Parte 1 di 3', 'Il modulo');

  await page.goto('/registrati');
  await page.waitForLoadState('networkidle');

  await g.passo("Chi non ha ancora Easy Lab parte da qui: è l'unica pagina che si apre senza un invito.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Il nome dell'organizzazione è quello che diventerà la tua prima sede, ed è quello che vedranno i tuoi colleghi in alto a sinistra.", {
    su: page.locator('#nome_ente'),
    zoom: 2,
    durata: 8,
  });

  await g.passo("Nome e indirizzo del referente: sarà il primo Amministratore, cioè chi potrà invitare gli altri.", {
    su: page.locator('#email'),
    zoom: 2,
    durata: 8,
  });

  await g.passo("La password la scegli adesso, e vale da subito: qui non c'è un invito da accettare.", {
    su: page.locator('#password'),
    zoom: 2,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Il piano e il pagamento');

  await g.passo("Il piano si sceglie prima di pagare, e determina quante sedi potrai avere.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Il pagamento avviene su Stripe, non qui: i dati della carta non passano mai da Easy Lab.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Finito il pagamento si torna indietro da soli, e l'account viene creato: non c'è nessuno da aspettare.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'I primi minuti');

  await g.passo("Arriva un'email di conferma all'indirizzo del referente: è anche la verifica che l'indirizzo sia davvero suo.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Entrando la prima volta la sede è vuota: il primo gesto è costruire l'anagrafica, poi caricare le macchine.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Poi si invitano i colleghi da «Persone», ciascuno con il proprio ruolo. Da lì in avanti si lavora.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "È l'unica pagina che si apre senza invito: chi ha già un'organizzazione su Easy Lab deve farsi invitare, non registrarsi di nuovo.",
    'Il referente che si registra diventa il primo Amministratore, ed è chi inviterà gli altri.',
    'Il pagamento sta su Stripe: Easy Lab non vede né conserva i dati della carta.',
  ]);

  g.scrivi();
});

import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida I2 — «Chi vede che cosa».
 *
 * ⚠️ Il terzo ruolo è il **Tenant** e non il Tecnico: l'unico Tecnico del seme
 * è l'utente invitato di `primo-accesso`, che nel seme la password non ce l'ha
 * ancora — accedere con lui non riesce, ed è giusto così.
 *
 * ⚠️ Non c'è una pagina dei ruoli da mostrare a un cliente: la matrice dei
 * permessi è di piattaforma. La guida allora fa la sola cosa onesta — mostra la
 * **stessa applicazione** vista da tre ruoli diversi, che è ciò che un cliente
 * sperimenta davvero quando due colleghi si siedono uno accanto all'altro.
 */
test('chi vede cosa', async ({ page }) => {
  const g = new Regista(page, 'chi-vede-cosa', 'Chi vede che cosa', 'La stessa applicazione, vista da tre ruoli diversi');

  g.capitolo('Parte 1 di 3', "L'Amministratore");

  await accedi(page, 'maria.conti@aurora.test');

  await g.passo("Il ruolo non è una qualifica: è l'insieme delle cose che una persona può vedere e fare.", {
    su: page.getByRole('banner'),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("L'Amministratore ha il menù più lungo: anagrafica, persone, fornitori, abbonamento. È chi governa l'Ente.", {
    su: page.getByRole('navigation', { name: 'Menù principale' }),
    zoom: 1.6,
    durata: 8,
  });

  await g.passo("Sulle schede vede tutte le azioni: modificare, spostare, forzare il semaforo, eliminare.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'Il Responsabile di reparto');

  await accedi(page, 'giulia.ferrari@aurora.test');

  await g.passo("Il Responsabile vede solo i reparti che gli sono assegnati: non è un menù più corto, è un parco più piccolo.", {
    su: page.getByRole('navigation', { name: 'Menù principale' }),
    zoom: 1.6,
    durata: 8,
  });

  await g.passo("Le macchine degli altri reparti non compaiono nei suoi elenchi, e non le trova nemmeno cercandole.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Registra interventi, chiude scadenze, allega documenti: tutto sul proprio perimetro.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Il referente del cliente');

  await accedi(page, 'paolo.greco@aurora.test');

  await g.passo("Il referente del cliente ha il menù più corto: guarda il proprio parco, non lo amministra.", {
    su: page.getByRole('navigation', { name: 'Menù principale' }),
    zoom: 1.6,
    durata: 8,
  });

  await g.passo("Le voci che non vede non gli mancano per errore: sono quelle che non gli servono, e alcune contengono dati che non lo riguardano.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Se ti aspetti un pulsante e non c'è, la risposta è quasi sempre il ruolo scritto sotto il tuo nome, in alto a destra.", {
    su: page.getByRole('banner'),
    zoom: 2,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    'Due colleghi con ruoli diversi vedono due Easy Lab diversi: è voluto, non un difetto.',
    'Il Responsabile è limitato ai reparti assegnati: le altre macchine non esistono per lui.',
    "Quando manca un pulsante che ti aspettavi, la spiegazione è il ruolo: si chiede a chi amministra l'Ente.",
  ]);

  g.scrivi();
});

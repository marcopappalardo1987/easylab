import { test } from '@playwright/test';
import path from 'node:path';
import { Regista } from '../lib/regista';
import { accedi } from '../lib/accesso';

/**
 * Guida C9 — «Importare strumenti da CSV».
 *
 * ⚠️ Il file di scena (`fixture/strumenti-esempio.csv`) contiene DI PROPOSITO
 * due righe sbagliate — una matricola vuota e un'ubicazione inesistente —
 * perché la guida deve mostrare l'anteprima degli errori, che è il punto della
 * funzione. Un file tutto valido racconterebbe metà della storia.
 *
 * ⚠️ Il copione SCRIVE: `azzera.sh` prima di ogni giratura.
 */
test('import csv', async ({ page }) => {
  const g = new Regista(page, 'import-csv', 'Importare strumenti da CSV', 'Caricare un parco intero, e vedere prima che cosa non torna');

  await accedi(page, 'maria.conti@aurora.test');

  g.capitolo('Parte 1 di 3', 'Il file');

  await page.goto('/strumenti/import');
  await page.waitForLoadState('networkidle');

  await g.passo("Si arriva da «Importa CSV», in cima all'elenco degli strumenti: serve a caricare molte macchine in una volta, non una alla volta.", {
    su: page.getByRole('heading').first(),
    zoom: 1.7,
    durata: 8,
  });

  await g.passo("Il formato è scritto qui, con un esempio: sei colonne, la prima riga con le intestazioni.", {
    su: page.locator('pre').first(),
    zoom: 1.8,
    durata: 8,
  });

  await g.passo("«Scarica template CSV» dà il file già intestato: è la strada più corta per non sbagliare i nomi delle colonne.", {
    su: page.getByRole('button', { name: /Scarica template/ }),
    zoom: 2,
    durata: 8,
  });

  await page.setInputFiles('#file', path.resolve(process.cwd(), 'fixture', 'strumenti-esempio.csv'));
  await page.waitForTimeout(1200);

  await g.passo('Si carica il proprio file e si chiede di analizzarlo.', {
    su: page.getByRole('button', { name: 'Analizza' }),
    click: true,
    zoom: 2,
  });

  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1200);

  g.capitolo('Parte 2 di 3', "L'anteprima, riga per riga");

  await g.passo("L'analisi non importa niente: mostra che cosa succederebbe, riga per riga.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Ogni riga porta il proprio esito: quelle valide entreranno, quelle con un problema no, e il problema è scritto.", {
    su: page.getByText('Esito').first(),
    zoom: 1.9,
    durata: 8,
  });

  await g.passo("Gli errori tipici sono due: un campo obbligatorio vuoto, e un'ubicazione che nell'anagrafica non esiste.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', "Importare, e che cosa resta fuori");

  await g.passo("Il pulsante dice quante righe verranno importate: solo le valide, e il numero è quello che hai appena letto.", {
    su: page.getByRole('button', { name: /righe valide/ }),
    click: true,
    zoom: 2,
  });

  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1200);

  await g.passo("Le macchine sono nel parco. Le righe scartate non sono andate perdute: si correggono nel foglio e si ricarica.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Conviene importare a lotti piccoli la prima volta: cinque righe di prova dicono se le ubicazioni combaciano, prima di caricarne trecento.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "L'analisi non scrive niente: si vede sempre l'esito prima di importare.",
    "L'ubicazione va scritta come nell'anagrafica, nella forma «Dipartimento > Laboratorio»: è l'errore più frequente.",
    'Le righe scartate restano nel tuo foglio: si correggono e si ricarica, senza rifare le altre.',
  ]);

  g.scrivi();
});

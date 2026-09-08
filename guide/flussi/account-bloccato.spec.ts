import { test } from '@playwright/test';
import { Regista } from '../lib/regista';
import { superaIl2FA } from '../lib/accesso';
import { artisan } from '../lib/ambiente';

/**
 * Guida K2 — «Quando l'accesso è sospeso».
 *
 * ⚠️ Il lockout va MESSO prima di girare: nessuna schermata dell'applicazione
 * lo produce (è una leva di console, `easylab:lockout`), e senza di esso la
 * pagina `/bloccato` rimbalza sulla dashboard.
 *
 * ⚠️ Il copione SCRIVE nel DB dimostrativo: `azzera.sh` prima di ogni giratura.
 */
test('account bloccato', async ({ page }) => {
  const g = new Regista(page, 'account-bloccato', "Quando l'accesso è sospeso", 'Che cosa si vede, e come si torna a lavorare');

  artisan(
    '$a = \\App\\Models\\Account::whereHas("membri", fn($q) => $q->where("email","maria.conti@aurora.test"))->first();'
      + ' $a->forceFill(["is_locked" => true, "locked_at" => now(), "locked_reason" => "Fattura 2026/114 non saldata"])->save();'
      + ' echo "bloccato";',
  );

  // ⚠️ Non si usa `accedi()`: quello aspetta la dashboard, e un account sospeso
  // sulla dashboard non ci arriva mai — l'attesa scadeva col test.
  await page.goto('/login');
  await page.fill('#email', 'maria.conti@aurora.test');
  await page.fill('#password', 'guida-demo');
  await page.click('button[type=submit]');
  await superaIl2FA(page);

  g.capitolo('Parte 1 di 3', 'Che cosa succede');

  await page.goto('/bloccato');
  await page.waitForLoadState('networkidle');

  await g.passo("Quando un pagamento resta insoluto, l'accesso alla sede viene sospeso: non si perde niente, si smette di poter entrare.", {
    su: page.getByRole('heading').first(),
    zoom: 1.8,
    durata: 8,
  });

  await g.passo("Qualunque pagina si provi ad aprire porta qui: la sospensione riguarda l'organizzazione, non il tuo account.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("La pagina dice quale sede è sospesa. Il motivo dettagliato non compare: è una questione fra chi amministra e Easy Lab, non da mostrare a tutti quelli che provano a entrare.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 2 di 3', 'I dati non si toccano');

  await g.passo("Le macchine, gli interventi, i documenti restano dove sono: la sospensione chiude la porta, non svuota la stanza.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Nemmeno le email si fermano per sempre: riprendono quando l'accesso riprende.", {
    zoom: 1,
    durata: 8,
  });

  g.capitolo('Parte 3 di 3', 'Come si esce');

  await g.passo("Se il tuo contratto copre più sedi e solo una è sospesa, le altre restano raggiungibili da qui.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("Per riaprire quella sospesa si salda l'insoluto: chi amministra trova le fatture nel portale di fatturazione.", {
    zoom: 1,
    durata: 8,
  });

  await g.passo("A pagamento registrato l'accesso torna da solo, senza che nessuno debba chiedere niente.", {
    zoom: 1,
    durata: 8,
  });

  g.chiusura('Da ricordare', [
    "La sospensione riguarda la sede, non il tuo account: cambia chi può entrare, non che cosa c'è dentro.",
    'I dati restano intatti e tornano visibili appena la sospensione si chiude.',
    'Si esce saldando: chi amministra trova le fatture nel portale, e la riapertura è automatica.',
  ]);

  g.scrivi();
});

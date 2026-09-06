import { execFileSync } from 'node:child_process';
import path from 'node:path';

/**
 * Esegue codice PHP nel contesto dell'app dimostrativa e ne restituisce
 * l'ultima riga stampata.
 *
 * Serve ai copioni che partono da uno stato che l'interfaccia non sa produrre:
 * una URL firmata d'invito, per esempio, esiste solo dentro un'email.
 *
 * ⚠️ Porta le stesse variabili di `bin/demo-env.sh`. `CACHE_STORE=array` non è
 * un dettaglio: Redis è condiviso e la cache dei permessi di spatie salva gli
 * **id** di ruoli e permessi (vedi CLAUDE.md).
 */
export const artisan = (php: string): string => {
  const radice = path.resolve(process.cwd(), '..');

  const uscita = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
    cwd: radice,
    encoding: 'utf8',
    env: {
      ...process.env,
      DB_CONNECTION: 'pgsql',
      DB_DATABASE: 'easylab_demo',
      CACHE_STORE: 'array',
      APP_URL: 'http://127.0.0.1:8123',
    },
  });

  return uscita.trim().split('\n').pop()!.trim();
};

import { defineConfig } from '@playwright/test';

/**
 * Cattura degli scatti per le guide. Non è una suite di test: le spec sono
 * copioni, e un fallimento significa "la UI non è più quella raccontata".
 *
 * Punta all'ambiente dimostrativo su :8123 (guide/bin/servi.sh), mai al DB di
 * sviluppo. Un solo worker e nessun retry: gli scatti devono essere
 * riproducibili e in ordine.
 */
export default defineConfig({
  testDir: './flussi',
  workers: 1,
  retries: 0,
  timeout: 120_000,
  reporter: 'list',
  use: {
    baseURL: process.env.GUIDA_URL ?? 'http://127.0.0.1:8123',
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2,
    locale: 'it-IT',
    timezoneId: 'Europe/Rome',
    colorScheme: 'light',
    // Il cursore lo disegna Remotion: quello vero non finisce negli scatti.
    launchOptions: { args: ['--force-color-profile=srgb', '--font-render-hinting=none'] },
  },
});

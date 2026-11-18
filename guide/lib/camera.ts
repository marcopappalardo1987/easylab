import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import type { Browser } from '@playwright/test';
import { artisan } from './ambiente';

/**
 * Una fotocamera finta per «Campo»: Chrome legge da un file video quello che
 * altrimenti leggerebbe dal sensore, e `BarcodeDetector` vi trova un QR vero.
 *
 * Serve perché lo scanner di «Campo» è movimento puro: un pulsante, la
 * fotocamera che si accende, la pagina che cambia. Raccontarlo con un fermo sul
 * pulsante diceva la metà; così il gesto si vede tutto, ed è il codice
 * dell'applicazione a leggere il QR e a decidere dove andare.
 *
 * ⚠️ Gli argomenti di lancio SOSTITUISCONO quelli di `playwright.config.ts`
 * (`test.use({ launchOptions })` non fonde): per questo ripete i due di base.
 *
 * ⚠️ Il file si può riscrivere fra due scansioni: Chrome lo riapre a ogni
 * `getUserMedia`, e la seconda inquadratura legge il codice nuovo.
 */
export const fotocameraFinta = (slug: string) => {
  const file = path.join(os.tmpdir(), `easylab-guida-camera-${slug}.y4m`);

  return {
    file,
    argomenti: [
      '--force-color-profile=srgb',
      '--font-render-hinting=none',
      '--use-fake-ui-for-media-stream',
      '--use-fake-device-for-media-stream',
      `--use-file-for-fake-video-capture=${file}`,
    ],
  };
};

/** Il QR che l'applicazione stampa sull'etichetta di una macchina. */
export const qrDellaMacchina = (id: number): string =>
  artisan(
    `echo base64_encode(\\App\\Support\\QrStrumento::svg(\\App\\Models\\Strumento::withoutGlobalScopes()->findOrFail(${id}), 300));`,
  );

/** Un QR qualunque, che con Easy Lab non c'entra: quello di un costruttore. */
export const qrEstraneo = (testo: string): string =>
  artisan(
    '$w = new \\BaconQrCode\\Writer(new \\BaconQrCode\\Renderer\\ImageRenderer(' +
      'new \\BaconQrCode\\Renderer\\RendererStyle\\RendererStyle(300), new \\BaconQrCode\\Renderer\\Image\\SvgImageBackEnd()));' +
      ` echo base64_encode($w->writeString(${JSON.stringify(testo)}));`,
  );

/**
 * Mette il QR davanti alla fotocamera: un attimo di inquadratura vuota, il
 * foglio sfocato che si avvicina, poi il codice a fuoco.
 *
 * Il QR si disegna in un browser a parte, non nella pagina ripresa: aprire una
 * scheda nel contesto della guida finirebbe nello screencast.
 */
export const mettiDavanti = async (browser: Browser, file: string, svgBase64: string): Promise<void> => {
  const foglio = await browser.newPage({ viewport: { width: 360, height: 360 } });
  const svg = Buffer.from(svgBase64, 'base64').toString('utf8');
  await foglio.setContent(
    `<body style="margin:0;width:360px;height:360px;display:grid;place-items:center;background:#fff">${svg}</body>`,
  );
  const png = path.join(os.tmpdir(), `${path.basename(file, '.y4m')}.png`);
  await foglio.screenshot({ path: png });
  await foglio.close();

  fs.rmSync(file, { force: true });
  // Grigio, poi il foglio sfocato (la messa a fuoco: `BarcodeDetector` non lo
  // legge ancora), poi nitido. Senza i due tempi d'attesa il codice si legge al
  // primo fotogramma e nella ripresa la fotocamera non si vede mai.
  const sfondo = '0x8d939a';
  const foglio300 = `scale=300:300,pad=640:480:(ow-iw)/2:(oh-ih)/2:${sfondo}`;
  execFileSync('ffmpeg', [
    '-loglevel', 'error', '-y',
    '-f', 'lavfi', '-i', `color=c=${sfondo}:s=640x480:d=0.9:r=15`,
    '-loop', '1', '-t', '1.3', '-r', '15', '-i', png,
    '-loop', '1', '-t', '4', '-r', '15', '-i', png,
    '-filter_complex', `[1]${foglio300},boxblur=9:2[b];[2]${foglio300}[q];[0][b][q]concat=n=3:v=1[v]`,
    '-map', '[v]', '-pix_fmt', 'yuv420p', file,
  ]);
};

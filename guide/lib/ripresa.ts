import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import type { CDPSession, Page } from '@playwright/test';

type Fotogramma = { t: number; file: string };

/**
 * Riprese vere della pagina, via screencast di Chrome (CDP).
 *
 * Il formato storico delle guide monta PNG fermi e muove la camera in Remotion
 * (vedi `regista.ts`): costa poco e si corregge in fretta, ma le cose che *sono*
 * movimento — un albero che si apre, un filtro che si restringe mentre scrivi,
 * un semaforo che cambia — non si raccontano con due fermi immagine.
 *
 * Qui Chrome manda un fotogramma JPEG ogni volta che ridisegna, ognuno col suo
 * istante. Si annota la finestra temporale dell'azione e si ricostruisce la
 * clip con le durate **reali** fra fotogrammi (concat demuxer di ffmpeg), non a
 * 30fps costanti: una pagina che sta ferma non consuma fotogrammi, e il tempo
 * del video resta quello del browser.
 *
 * ⚠️ I fotogrammi arrivano solo se si risponde con `screencastFrameAck`: senza
 * l'ack Chrome ne manda uno e poi tace.
 */
export class Ripresa {
  private cdp!: CDPSession;
  private readonly fotogrammi: Fotogramma[] = [];
  private readonly grezzi: string;
  private contatore = 0;
  private attiva = false;

  constructor(
    private readonly page: Page,
    private readonly cartella: string,
  ) {
    this.grezzi = path.join(cartella, '.grezzi');
    fs.mkdirSync(this.grezzi, { recursive: true });
  }

  async avvia(): Promise<void> {
    this.cdp = await this.page.context().newCDPSession(this.page);

    this.cdp.on('Page.screencastFrame' as never, (f: any) => {
      const file = `${String(this.contatore++).padStart(5, '0')}.jpg`;
      fs.writeFileSync(path.join(this.grezzi, file), Buffer.from(f.data, 'base64'));
      this.fotogrammi.push({ t: f.metadata.timestamp, file });
      this.cdp.send('Page.screencastFrameAck' as never, { sessionId: f.sessionId } as never).catch(() => {});
    });

    await this.cdp.send('Page.startScreencast' as never, {
      format: 'jpeg',
      quality: 88,
      maxWidth: 2880,
      maxHeight: 1800,
      everyNthFrame: 1,
    } as never);
    this.attiva = true;
    await this.page.waitForTimeout(400); // il primo fotogramma è il fondale della clip
  }

  /**
   * Esegue l'azione filmandola e ritorna la durata della clip prodotta.
   *
   * `durataMinima` allunga la coda congelando l'ultimo fotogramma: serve quando
   * la didascalia è più lunga di quanto il browser abbia messo a rispondere.
   */
  async clip(file: string, azione: () => Promise<void>, durataMinima = 0, coda = 0.5): Promise<number> {
    if (!this.attiva) throw new Error('Ripresa non avviata');

    const t0 = Date.now() / 1000;
    await azione();
    await this.page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
    await this.page.waitForTimeout(coda * 1000);
    const t1 = Date.now() / 1000;

    return this.monta(file, t0, t1, durataMinima);
  }

  async chiudi(): Promise<void> {
    if (!this.attiva) return;
    await this.cdp.send('Page.stopScreencast' as never).catch(() => {});
    this.attiva = false;
    fs.rmSync(this.grezzi, { recursive: true, force: true });
  }

  /** Ricostruisce la clip dai fotogrammi che cadono nella finestra dell'azione. */
  private monta(file: string, t0: number, t1: number, durataMinima: number): number {
    const dentro = this.fotogrammi.filter((f) => f.t >= t0 && f.t <= t1);
    // L'ultimo fotogramma PRIMA dell'azione è lo stato di partenza: senza, la
    // clip comincia dal primo ridisegno, cioè a cosa già avvenuta.
    const prima = [...this.fotogrammi].reverse().find((f) => f.t < t0);
    const scelti = prima ? [prima, ...dentro] : dentro;
    if (scelti.length === 0) throw new Error(`Nessun fotogramma per ${file}`);

    const durate: number[] = scelti.map((f, i) => {
      const fine = i + 1 < scelti.length ? scelti[i + 1].t : t1;
      return Math.max(0.033, fine - Math.max(f.t, t0));
    });

    let totale = durate.reduce((a, b) => a + b, 0);
    if (totale < durataMinima) {
      durate[durate.length - 1] += durataMinima - totale;
      totale = durataMinima;
    }

    const elenco = path.join(this.grezzi, 'elenco.txt');
    const righe = scelti.flatMap((f, i) => [
      `file '${path.join(this.grezzi, f.file)}'`,
      `duration ${durate[i].toFixed(3)}`,
    ]);
    // Il concat demuxer ignora la durata dell'ultimo file: va ripetuto.
    righe.push(`file '${path.join(this.grezzi, scelti[scelti.length - 1].file)}'`);
    fs.writeFileSync(elenco, righe.join('\n'));

    execFileSync('ffmpeg', [
      '-y', '-loglevel', 'error',
      '-f', 'concat', '-safe', '0', '-i', elenco,
      '-vf', 'scale=1920:-2:flags=lanczos,format=yuv420p',
      '-r', '30', '-fps_mode', 'cfr',
      // ⚠️ Il taglio esatto NON è decorativo: il concat demuxer ignora la durata
      // dell'ultimo file e va ripetuto, e la ripetizione si porta dietro di nuovo
      // l'ultima durata. Senza `-t` la clip esce più lunga del copione, e in
      // montaggio la scena successiva entra su un fotogramma già finito.
      '-t', totale.toFixed(3),
      '-c:v', 'libx264', '-crf', '19', '-preset', 'veryfast',
      path.join(this.cartella, file),
    ]);

    return Number(totale.toFixed(2));
  }
}

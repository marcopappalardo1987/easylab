import fs from 'node:fs';
import path from 'node:path';
import type { Locator, Page } from '@playwright/test';

export type Fuoco = { x: number; y: number; w: number; h: number };

export type Passo = {
  n: number;
  /** Assente sui cartelli: un capitolo non ha uno scatto suo. */
  file: string | null;
  didascalia: string;
  durata: number;
  /** Rettangolo su cui la camera stringe. Assente = inquadratura piena. */
  fuoco: Fuoco | null;
  /** Dove arriva il cursore in questo passo. Assente = cursore fuori campo. */
  cursore: { x: number; y: number } | null;
  /** Il passo si chiude con un click sul punto del cursore. */
  click: boolean;
  /** Cartello di sezione. */
  capitolo: { titolo: string; occhiello: string } | null;
  /** Scheda di chiusura. */
  chiusura: { titolo: string; punti: string[] } | null;
};

export type Manifest = {
  titolo: string;
  sottotitolo: string;
  larghezza: number;
  altezza: number;
  passi: Passo[];
};

type OpzioniPasso = {
  /** Elemento da inquadrare e su cui portare il cursore. */
  su?: Locator;
  /** Secondi di permanenza. Default: proporzionale alla lunghezza della didascalia. */
  durata?: number;
  /** Quanto stringere: 1 = pagina intera. */
  zoom?: number;
  /** Il cursore arriva e clicca (il click avviene DOPO lo scatto). */
  click?: boolean;
};

/**
 * Raccoglie gli scatti di un flusso e ne descrive il montaggio.
 *
 * Il video NON si registra qui: Playwright produce PNG a piena risoluzione più
 * un manifest, e Remotion li anima. Il motivo è la correzione: cambiare una
 * didascalia costa un re-render di 40 secondi invece di rigirare il browser, e
 * un webm di una pagina che si muove a scatti è illeggibile.
 *
 * Ogni passo scatta lo stato PRIMA dell'azione e annota dove va il cursore: in
 * montaggio si vede il puntatore arrivare sul bottone, poi il passo successivo
 * mostra il risultato.
 */
export class Regista {
  private readonly passi: Passo[] = [];
  private readonly cartella: string;

  constructor(
    private readonly page: Page,
    private readonly slug: string,
    private readonly titolo: string,
    private readonly sottotitolo: string,
  ) {
    this.cartella = path.join(process.cwd(), 'out', slug);
    fs.rmSync(this.cartella, { recursive: true, force: true });
    fs.mkdirSync(this.cartella, { recursive: true });
  }

  async passo(didascalia: string, opzioni: OpzioniPasso = {}): Promise<void> {
    // Numerati solo gli scatti: i cartelli non sono passi da seguire, e
    // contarli sfaserebbe il «3/18» in sovrimpressione.
    const n = this.passi.filter((p) => p.file !== null).length + 1;
    const file = `${String(n).padStart(2, '0')}.png`;

    if (opzioni.su) {
      await opzioni.su.scrollIntoViewIfNeeded();
      await opzioni.su.waitFor({ state: 'visible' });
    }
    await this.quiete();

    let fuoco: Fuoco | null = null;
    let cursore: { x: number; y: number } | null = null;

    if (opzioni.su) {
      const box = await opzioni.su.boundingBox();
      if (!box) throw new Error(`Passo ${n}: elemento senza riquadro, "${didascalia}"`);
      fuoco = this.allarga(box, opzioni.zoom ?? 2.2);
      cursore = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    }

    await this.page.screenshot({ path: path.join(this.cartella, file) });

    this.passi.push({
      n,
      file,
      didascalia,
      durata: opzioni.durata ?? this.respiro(didascalia),
      fuoco,
      cursore,
      click: opzioni.click ?? false,
      capitolo: null,
      chiusura: null,
    });

    if (opzioni.click && opzioni.su) {
      await opzioni.su.click();
    }
  }

  /**
   * Cartello di sezione. Non scatta nulla: in montaggio si stampa sopra
   * l'ultimo fotogramma, sfocato. Serve a spezzare un video lungo in tratti
   * che si possono cercare, e a dire prima che cosa si sta per fare.
   */
  capitolo(occhiello: string, titolo: string, durata = 2.8): void {
    this.passi.push({
      n: this.passi.length + 1,
      file: null,
      didascalia: '',
      durata,
      fuoco: null,
      cursore: null,
      click: false,
      capitolo: { titolo, occhiello },
      chiusura: null,
    });
  }

  /** Scheda finale: quel che resta in mano a chi ha guardato. */
  chiusura(titolo: string, punti: string[], durata = 6): void {
    this.passi.push({
      n: this.passi.length + 1,
      file: null,
      didascalia: '',
      durata,
      fuoco: null,
      cursore: null,
      click: false,
      capitolo: null,
      chiusura: { titolo, punti },
    });
  }

  /** Attende che la pagina smetta di muoversi: rete ferma e nessuna animazione. */
  private async quiete(): Promise<void> {
    await this.page.waitForLoadState('networkidle').catch(() => {});
    await this.page.evaluate(() => document.fonts.ready);
    await this.page.waitForTimeout(150);
  }

  /**
   * Allarga il riquadro dell'elemento fino all'inquadratura richiesta, tenendola
   * dentro i bordi della pagina: una camera che esce dal viewport mostrerebbe
   * bande vuote.
   */
  private allarga(box: { x: number; y: number; width: number; height: number }, zoom: number): Fuoco {
    const vp = this.page.viewportSize()!;
    const w = Math.min(vp.width, vp.width / zoom);
    const h = Math.min(vp.height, vp.height / zoom);
    const cx = box.x + box.width / 2;
    const cy = box.y + box.height / 2;

    return {
      x: Math.max(0, Math.min(vp.width - w, cx - w / 2)),
      y: Math.max(0, Math.min(vp.height - h, cy - h / 2)),
      w,
      h,
    };
  }

  /** ~15 caratteri al secondo, con un minimo di 2,5s perché l'occhio arrivi. */
  private respiro(testo: string): number {
    return Math.max(2.5, Math.min(9, testo.length / 15));
  }

  scrivi(): void {
    const vp = this.page.viewportSize()!;
    const manifest: Manifest = {
      titolo: this.titolo,
      sottotitolo: this.sottotitolo,
      larghezza: vp.width,
      altezza: vp.height,
      passi: this.passi,
    };
    fs.writeFileSync(
      path.join(this.cartella, 'manifest.json'),
      JSON.stringify(manifest, null, 2),
    );
  }
}

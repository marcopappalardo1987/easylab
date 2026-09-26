import fs from 'node:fs';
import path from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { Ripresa } from './ripresa';

export type Fuoco = { x: number; y: number; w: number; h: number };
export type Pagina = { w: number; h: number };
/** L'effetto che accompagna una ripresa. Marca una cosa che si vede accadere. */
export type Suono = 'click' | 'tastiera' | 'scorrimento';

export type Scena =
  | { tipo: 'apertura'; titolo: string; sottotitolo: string; durata: number }
  | { tipo: 'cartello'; parola: string; occhiello: string; durata: number }
  | {
      tipo: 'scatto';
      file: string;
      didascalia: string;
      durata: number;
      pagina: Pagina;
      fuoco: Fuoco | null;
      elemento: Fuoco | null;
      cursore: { x: number; y: number } | null;
      vista: Pagina;
      fianco: boolean;
      scorri: boolean;
      url: string;
    }
  | {
      tipo: 'ripresa';
      file: string;
      didascalia: string;
      durata: number;
      pagina: Pagina;
      fuoco: Fuoco | null;
      elemento: Fuoco | null;
      cursore: { x: number; y: number } | null;
      vista: Pagina;
      suono: Suono | null;
      url: string;
    }
  | { tipo: 'chiusura'; titolo: string; punti: string[]; durata: number };

export type Copione = {
  titolo: string;
  sottotitolo: string;
  larghezza: number;
  altezza: number;
  scene: Scena[];
};

/**
 * Copione del formato «vetrina»: schermate vere, riprese vere.
 *
 * Convive col `Regista` storico invece di sostituirlo: quello monta PNG fermi e
 * copre le 46 guide già girate, questo aggiunge le clip — e un formato nuovo che
 * riscrivesse il vecchio metterebbe a rischio lavoro finito per una prova.
 *
 * Tre materiali, tre usi:
 *  - `scatto`   → un dettaglio da leggere: PNG a piena risoluzione, camera che stringe;
 *  - `panoramica` → una pagina più alta del viewport: scatto intero, scorrimento in montaggio;
 *  - `ripresa`  → una cosa che *è* movimento: clip vera del browser (vedi `ripresa.ts`).
 */
export class Scenografo {
  private readonly scene: Scena[] = [];
  private readonly cartella: string;
  private readonly ripresa: Ripresa;
  private scatti = 0;
  private clip = 0;

  constructor(
    private readonly page: Page,
    private readonly slug: string,
    private readonly titolo: string,
    private readonly sottotitolo: string,
  ) {
    this.cartella = path.join(process.cwd(), 'out', slug);
    // ⛔ La cartella si svuota, ma `voce/` NO: quella è sintesi già PAGATA, e
    // rigirare la cattura per cambiare una didascalia non deve ricomprarla.
    // È già successo il 26 Set 2026: un `rmSync` della cartella intera, e le
    // dieci frasi della vetrina rigenerate da capo senza motivo.
    if (fs.existsSync(this.cartella)) {
      for (const voce of fs.readdirSync(this.cartella)) {
        if (voce !== 'voce') fs.rmSync(path.join(this.cartella, voce), { recursive: true, force: true });
      }
    }
    fs.mkdirSync(this.cartella, { recursive: true });
    this.ripresa = new Ripresa(page, this.cartella);
  }

  async avvia(durata = 4.2): Promise<void> {
    this.scene.push({ tipo: 'apertura', titolo: this.titolo, sottotitolo: this.sottotitolo, durata });
    await this.ripresa.avvia();
  }

  cartello(parola: string, occhiello: string, durata = 2.2): void {
    this.scene.push({ tipo: 'cartello', parola, occhiello, durata });
  }

  /** Dettaglio fermo: si inquadra un elemento e si tiene, come oggi. */
  async scatto(didascalia: string, opzioni: { su?: Locator; zoom?: number; durata?: number } = {}): Promise<void> {
    const file = `s${String(++this.scatti).padStart(2, '0')}.png`;
    let fuoco: Fuoco | null = null;
    let elemento: Fuoco | null = null;
    let cursore: { x: number; y: number } | null = null;

    if (opzioni.su) {
      await opzioni.su.scrollIntoViewIfNeeded();
      await opzioni.su.waitFor({ state: 'visible' });
    }
    await this.quiete();

    if (opzioni.su) {
      const box = await opzioni.su.boundingBox();
      if (!box) throw new Error(`Scatto ${file}: elemento senza riquadro`);
      elemento = { x: box.x, y: box.y, w: box.width, h: box.height };
      fuoco = this.allarga(box, opzioni.zoom ?? 1.6);
      cursore = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    }

    await this.page.screenshot({ path: path.join(this.cartella, file) });
    const vp = this.page.viewportSize()!;

    this.scene.push({
      tipo: 'scatto',
      file,
      didascalia,
      durata: opzioni.durata ?? this.respiro(didascalia),
      pagina: { w: vp.width, h: vp.height },
      fuoco,
      elemento,
      cursore,
      vista: { w: vp.width, h: vp.height },
      fianco: false,
      scorri: false,
      url: this.indirizzo(),
    });
  }

  /**
   * Pagina intera, più alta del viewport: in montaggio scorre da sola.
   *
   * ⚠️ Lo scatto `fullPage` NON è il viewport: l'altezza vera va misurata e
   * portata nel manifest, o la camera calcola su proporzioni sbagliate e la
   * pagina appare stirata.
   */
  async panoramica(didascalia: string, durata = 7): Promise<void> {
    const file = `s${String(++this.scatti).padStart(2, '0')}.png`;
    await this.quiete();
    await this.page.screenshot({ path: path.join(this.cartella, file), fullPage: true });

    const vp = this.page.viewportSize()!;
    const alta = await this.page.evaluate(() =>
      Math.max(document.documentElement.scrollHeight, document.body.scrollHeight),
    );
    const contenuto = await this.altezzaContenuto();

    this.scene.push({
      tipo: 'scatto',
      file,
      didascalia,
      durata,
      pagina: { w: vp.width, h: Math.max(alta, vp.height) },
      fuoco: null,
      elemento: null,
      cursore: null,
      // ⚠️ La finestra si **accorcia** al contenuto: una pagina corta ritratta a
      // piena altezza è per due terzi bianco, e sul palco quel bianco pesa più
      // di quello che c'è scritto. Una finestra alta quanto serve è anche più
      // vera: è così che uno la tiene sullo schermo.
      vista: { w: vp.width, h: Math.min(vp.height, Math.max(320, contenuto)) },
      fianco: true,
      scorri: alta > vp.height * 1.15,
      url: this.indirizzo(),
    });
  }

  /** Ripresa vera: l'azione si svolge e si vede svolgersi. */
  async movimento(
    didascalia: string,
    azione: () => Promise<void>,
    opzioni: { su?: Locator; zoom?: number; coda?: number; durata?: number; suono?: Suono | null } = {},
  ): Promise<void> {
    const file = `r${String(++this.clip).padStart(2, '0')}.mp4`;
    let fuoco: Fuoco | null = null;
    let elemento: Fuoco | null = null;
    let cursore: { x: number; y: number } | null = null;

    if (opzioni.su) {
      const box = await opzioni.su.boundingBox();
      if (box) {
        elemento = { x: box.x, y: box.y, w: box.width, h: box.height };
        fuoco = this.allarga(box, opzioni.zoom ?? 1.25);
        // Lo screencast non riprende il puntatore del sistema: il click vero
        // c'è, ma invisibile. Il cursore lo ridisegna il montaggio, dove è
        // avvenuto davvero.
        cursore = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
      }
    }
    await this.quiete();

    const durata = await this.ripresa.clip(
      file,
      azione,
      opzioni.durata ?? this.respiro(didascalia),
      opzioni.coda ?? 0.6,
    );
    const vp = this.page.viewportSize()!;

    this.scene.push({
      tipo: 'ripresa',
      file,
      didascalia,
      durata,
      pagina: { w: vp.width, h: vp.height },
      fuoco,
      elemento,
      cursore,
      vista: { w: vp.width, h: vp.height },
      // Dove c'è un elemento c'è stato un click vero: è il caso normale, e
      // dirlo ogni volta nel copione sarebbe rumore.
      suono: opzioni.suono === undefined ? (opzioni.su ? 'click' : null) : opzioni.suono,
      url: this.indirizzo(),
    });
  }

  chiusura(titolo: string, punti: string[], durata = 6.5): void {
    this.scene.push({ tipo: 'chiusura', titolo, punti, durata });
  }

  async scrivi(): Promise<void> {
    await this.ripresa.chiudi();
    const vp = this.page.viewportSize()!;
    const copione: Copione = {
      titolo: this.titolo,
      sottotitolo: this.sottotitolo,
      larghezza: vp.width,
      altezza: vp.height,
      scene: this.scene,
    };
    fs.writeFileSync(path.join(this.cartella, 'copione.json'), JSON.stringify(copione, null, 2));
  }

  /** Quanto in basso arriva il contenuto vero, ignorando il bianco sotto. */
  private async altezzaContenuto(): Promise<number> {
    return this.page.evaluate(() => {
      const radice = document.querySelector('main') ?? document.body;
      let giu = 0;
      radice.querySelectorAll('*').forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.width > 4 && r.height > 4) giu = Math.max(giu, r.bottom + window.scrollY);
      });

      return Math.ceil(giu + 28);
    });
  }

  /** Il percorso vero, per la barra del browser disegnata in montaggio. */
  private indirizzo(): string {
    try {
      const u = new URL(this.page.url());
      return `easylab.technology${u.pathname}`;
    } catch {
      return 'easylab.technology';
    }
  }

  private async quiete(): Promise<void> {
    await this.page.waitForLoadState('networkidle', { timeout: 2000 }).catch(() => {});
    await this.page.evaluate(() => document.fonts.ready);
    await this.page.waitForTimeout(150);
  }

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

  private respiro(testo: string): number {
    return Math.max(3, Math.min(10, testo.length / 14));
  }
}

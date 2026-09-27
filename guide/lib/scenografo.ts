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
  async scatto(
    didascalia: string,
    opzioni: { su?: Locator; zoom?: number; durata?: number; alone?: boolean } = {},
  ): Promise<void> {
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
      elemento = await this.riquadroVisibile(opzioni.su);
      fuoco = this.allarga(elemento, opzioni.zoom ?? 1.6);
      // ⚠️ `alone: false` tiene l'inquadratura e toglie la luce. Serve quando la
      // frase parla di PIÙ cose — «verde, giallo e rosso» — e accenderne una
      // sola dice il contrario di quello che si sta dicendo. Inquadrarle tutte
      // e tre senza alone è onesto; un contorno intorno al gruppo comprenderebbe
      // anche quello che nel gruppo non c'è.
      if (opzioni.alone === false || !this.staInFinestra(elemento)) elemento = null;
      // Il cursore resta sull'elemento VERO, non sulla scheda: è lì che
      // Playwright ha cliccato, e un puntatore che indica il centro di un
      // riquadro grande mentre il click è avvenuto su una riga dentro di esso
      // racconta una cosa che non è successa.
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
        elemento = await this.riquadroVisibile(opzioni.su);
        fuoco = this.allarga(elemento, opzioni.zoom ?? 1.25);
        if (!this.staInFinestra(elemento)) elemento = null;
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

    /*
     * ⛔ Se l'azione ha cambiato la pagina, l'alone NON si disegna.
     *
     * Il riquadro è misurato prima; un click su una scheda porta altrove in
     * trecento millesimi — anche senza cambiare indirizzo, perché è Livewire a
     * riscrivere la pagina. Tenendo l'alone acceso «per poco» si illumina
     * comunque un pezzo di contenuto NUOVO, che non c'entra: il 26 Set 2026 era
     * un rettangolo vuoto di fianco a un bottone, e sembrava un guasto.
     *
     * Il tempo non si indovina: si guarda se l'elemento è ancora dov'era. Se
     * non c'è più, o si è spostato, restano il cursore e il suono del click a
     * dire dove si è cliccato — che è quanto serve, perché da lì in poi quel
     * che conta è il risultato.
     */
    if (elemento && opzioni.su) {
      // ⚠️ Col timeout, e corto: dopo un'azione che porta altrove l'elemento
      // non c'è più, e un `boundingBox()` nudo resta in attesa fino alla morte
      // del test — due minuti per scoprire una cosa che si sa in un secondo.
      const dopo = await opzioni.su.boundingBox({ timeout: 1200 }).catch(() => null);
      const fermo = dopo !== null && Math.abs(dopo.x - elemento.x) < 12 && Math.abs(dopo.y - elemento.y) < 12;
      if (!fermo) elemento = null;
    }

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

  /**
   * Il riquadro che l'occhio VEDE come «quella cosa lì».
   *
   * ⚠️ Un localizzatore di testo pesca il nodo del testo, che dentro una
   * scheda è una riga alta venti pixel in mezzo a un riquadro da cento. Se
   * l'alone si accende su quello, nel video sembra un difetto — ed è successo:
   * il 26 Set 2026 la luce sulla scheda «Genetica Medica» illuminava la sola
   * etichetta. Quindi si sale al primo antenato CLICCABILE (è lui che l'utente
   * percepisce come il bersaglio) e, se quello sta dentro una scheda vera
   * (bordo o ombra) poco più grande, si prende la scheda.
   *
   * ⛔ Nessuna euristica sulle dimensioni: si sale solo lungo antenati
   * cliccabili o schede, mai «finché cresce», o su una pagina diversa si
   * finirebbe a illuminare mezzo schermo.
   */
  private async riquadroVisibile(su: Locator): Promise<Fuoco> {
    return su.evaluate((el) => {
      const cliccabile = (n: Element | null): boolean =>
        !!n &&
        (n.matches('a, button, summary, [role=button], [role=link], [role=menuitem]') ||
          n.hasAttribute('wire:click') ||
          n.hasAttribute('onclick'));

      let nodo: Element = el;
      for (let i = 0; i < 6 && nodo.parentElement && !cliccabile(nodo); i++) {
        nodo = nodo.parentElement;
      }
      if (!cliccabile(nodo)) nodo = el;

      const padre = nodo.parentElement;
      if (padre) {
        const dentro = nodo.getBoundingClientRect();
        const fuori = padre.getBoundingClientRect();
        const stile = getComputedStyle(padre);
        const scheda = stile.borderTopWidth !== '0px' || stile.boxShadow !== 'none';
        // 2,8 e non 2: una scheda con `p-4` intorno a un bottone stretto sta
        // già a 2,05, e con la soglia bassa restava fuori — cioè si tornava
        // esattamente al difetto che questa funzione esiste per togliere.
        if (scheda && fuori.width * fuori.height <= dentro.width * dentro.height * 2.8) nodo = padre;
      }

      const r = nodo.getBoundingClientRect();

      return { x: r.x, y: r.y, w: r.width, h: r.height };
    });
  }

  /**
   * L'alone si disegna solo se il riquadro ci sta dentro la finestra.
   *
   * ⚠️ Un elemento più alto del viewport — una tabella di quaranta righe — dà
   * un alone con bordi fuori campo: a schermo non si vede una luce su qualcosa,
   * si vede la pagina che si scurisce e basta. In quel caso l'inquadratura
   * basta da sola a dire di che cosa si parla.
   */
  private staInFinestra(f: Fuoco): boolean {
    const vp = this.page.viewportSize()!;

    // 0,95 e non 0,88: la barra laterale è alta 844 su 900, cioè quasi tutta la
    // finestra ma dentro — e senza alone il passo che parla del menù non
    // illuminava niente. Sopra questa soglia l'elemento non ci sta più, e un
    // contorno coi lati fuori campo non si legge come una luce su qualcosa.
    return f.w <= vp.width * 0.95 && f.h <= vp.height * 0.95;
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

  private allarga(box: Fuoco, zoom: number): Fuoco {
    const vp = this.page.viewportSize()!;
    const w = Math.min(vp.width, vp.width / zoom);
    const h = Math.min(vp.height, vp.height / zoom);
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
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

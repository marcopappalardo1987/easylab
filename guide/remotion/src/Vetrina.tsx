import React from 'react';
import {
  AbsoluteFill,
  Audio,
  Img,
  OffthreadVideo,
  Sequence,
  staticFile,
  useCurrentFrame,
  interpolate,
  Easing,
} from 'remotion';
import { Marchio } from './Marchio';
import { Parole, molla } from './Testo';
import { Cursore } from './Cursore';
import { ALTEZZA, FPS, LARGHEZZA } from './camera';

/**
 * Formato «vetrina»: la finestra vera del browser su un palco, e dentro le
 * riprese vere dell'applicazione (🔗 `lib/ripresa.ts` per la cattura).
 *
 * Tre scelte che fanno la differenza fra questo e il formato storico:
 *
 *  1. **La schermata non riempie il fotogramma.** Sta in una finestra con la sua
 *     barra, il suo indirizzo e la sua ombra, appoggiata su un fondo di marca.
 *     Il motivo non è estetico: a pieno schermo un ritaglio di pagina non si
 *     distingue da un'immagine qualunque, mentre la cornice dice «questa è
 *     l'applicazione, e sei tu che la stai guardando».
 *  2. **La didascalia non copre la pagina.** Vive nella fascia sotto la
 *     finestra. Le sovraimpressioni sul contenuto nascondono sempre la riga che
 *     serve, e il lettore non può spostarle.
 *  3. **Gli stacchi si sovrappongono.** Ogni scena parte `SFUMATURA` secondi
 *     prima del suo istante e sale in dissolvenza sopra quella che esce, che in
 *     quel momento è ancora viva. Senza sovrapposizione fra due clip vere resta
 *     un fotogramma nero, cioè un singhiozzo.
 *
 * ⚠️ Lo zoom sulle **riprese** è limitato (`ZOOM_CLIP_MAX`): la clip è larga
 * 1920 per una finestra da 1440, quindi oltre ~1,15× si vedono i blocchi del
 * JPEG. Sugli **scatti** si può stringere al doppio, perché i PNG sono a 2880.
 */

export type Fuoco = { x: number; y: number; w: number; h: number };
export type Pagina = { w: number; h: number };
export type Punto = { x: number; y: number };
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
      cursore: Punto | null;
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
      cursore: Punto | null;
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

/** Voce narrante: `i` è l'indice della scena, `durata` la lunghezza del wav. */
export type Voce = { i: number; durata: number };

export type ProprietaVetrina = { slug: string; copione: Copione; voci?: Voce[] };

export const formatoVetrina = { width: LARGHEZZA, height: ALTEZZA, fps: FPS };

const INCHIOSTRO = '#0B1220';
const BLU = '#2997D4';
const BLU_CUPO = '#06589C';
const PANNA = '#F1F5F9';
const GRIGIO = '#8FA3BC';

/**
 * Gli effetti, col loro volume e il ritardo dall'inizio della scena.
 *
 * ⚠️ I volumi NON sono tutti uguali perché i file non lo sono: misurati, i
 * picchi vanno da -8 dB (`stacco`) a -22 dB (`scorrimento`). Questi numeri li
 * riportano tutti intorno a -19 dB, che è il punto in cui si sentono sotto la
 * voce senza coprirla. Cambiando un file va rimisurato il suo picco, non
 * ritoccato a orecchio il numero di fianco.
 *
 * Il ritardo dice A CHE COSA corrisponde il suono: il click arriva quando il
 * cursore si posa (0,28 s), i tasti subito dopo, perché nella ripresa prima si
 * clicca il campo e poi si scrive.
 */
const EFFETTI = {
  apertura: { volume: 0.6, quando: 0.15 },
  stacco: { volume: 0.28, quando: 0 },
  conferma: { volume: 0.7, quando: 0.1 },
  click: { volume: 0.5, quando: 0.28 },
  tastiera: { volume: 0.65, quando: 0.62 },
  scorrimento: { volume: 1, quando: 0.3 },
} as const;

const Effetto: React.FC<{ nome: keyof typeof EFFETTI; da: number }> = ({ nome, da }) => (
  <Sequence from={Math.round((da + EFFETTI[nome].quando) * FPS)} layout="none">
    <Audio src={staticFile(`audio/sfx/${nome}.wav`)} volume={EFFETTI[nome].volume} />
  </Sequence>
);

const BARRA = 44;
const SFUMATURA = 0.34;
const ZOOM_CLIP_MAX = 1.15;
const ZOOM_SCATTO_MAX = 2.1;

/** Lo spazio del palco in cui stanno finestra e didascalia. */
const MARGINE = { w: 1530, h: 890 };

type Sistemazione = { cx: number; cy: number; k: number; ry: number };

/**
 * Sistema finestra e testo come UN gruppo, centrato sul palco.
 *
 * ⚠️ La finestra cambia altezza da scena a scena, perché si accorcia al
 * contenuto vero della pagina. Tenendo la didascalia a un'altezza fissa, una
 * pagina corta lasciava mezzo palco vuoto in mezzo ai due. Si centra l'insieme,
 * e il testo sta sempre a un palmo dal bordo inferiore della finestra.
 */
const impagina = (vista: Pagina, caratteri: number) => {
  const k = Math.min(MARGINE.w / vista.w, MARGINE.h / (vista.h + BARRA));
  const altezzaFinestra = (vista.h + BARRA) * k;
  const righe = Math.max(1, Math.ceil(caratteri / 62));
  const gruppo = altezzaFinestra + 54 + righe * 48;
  const cima = Math.max(24, (ALTEZZA - 44 - gruppo) / 2);

  return {
    sistemazione: { cx: LARGHEZZA / 2, cy: cima + altezzaFinestra / 2, k, ry: 0 } as Sistemazione,
    testoY: cima + altezzaFinestra + 54,
  };
};

const fra = (v: number, min: number, max: number) => Math.min(Math.max(v, min), max);

type Inquadratura = { s: number; tx: number; ty: number };

/**
 * Porta un rettangolo della pagina al centro della finestra, senza mai lasciare
 * bande vuote: la scala non scende sotto la copertura della finestra e le
 * traslazioni restano dentro i bordi della pagina.
 */
const inquadra = (f: Fuoco, pagina: Pagina, vista: Pagina, tetto: number): Inquadratura => {
  const copertura = Math.max(vista.w / pagina.w, vista.h / pagina.h);
  const s = fra(Math.max(vista.w / f.w, vista.h / f.h), copertura, Math.max(copertura, tetto));

  return {
    s,
    tx: fra(vista.w / 2 - (f.x + f.w / 2) * s, vista.w - pagina.w * s, 0),
    ty: fra(vista.h / 2 - (f.y + f.h / 2) * s, vista.h - pagina.h * s, 0),
  };
};

const interpolaInquadratura = (a: Inquadratura, b: Inquadratura, t: number): Inquadratura => ({
  // Scala in logaritmico: l'occhio legge i rapporti, non le differenze, e in
  // lineare uno zoom parte di scatto e frena.
  s: Math.exp(Math.log(a.s) + (Math.log(b.s) - Math.log(a.s)) * t),
  tx: a.tx + (b.tx - a.tx) * t,
  ty: a.ty + (b.ty - a.ty) * t,
});

const conAgio = (t: number, da: number, a: number) =>
  interpolate(t, [da, a], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.bezier(0.33, 0, 0.12, 1),
  });

/* ─────────────────────────── il palco ─────────────────────────── */

const Palco: React.FC<{ t: number }> = ({ t }) => (
  <AbsoluteFill style={{ backgroundColor: INCHIOSTRO, overflow: 'hidden' }}>
    <div
      style={{
        position: 'absolute',
        left: -400 + Math.sin(t * 0.22) * 60,
        top: -320 + Math.cos(t * 0.17) * 50,
        width: 1500,
        height: 1200,
        borderRadius: '50%',
        background: `radial-gradient(circle, ${BLU_CUPO}, transparent 62%)`,
        opacity: 0.55,
        filter: 'blur(40px)',
      }}
    />
    <div
      style={{
        position: 'absolute',
        right: -500 + Math.cos(t * 0.19) * 70,
        bottom: -420 + Math.sin(t * 0.13) * 60,
        width: 1400,
        height: 1100,
        borderRadius: '50%',
        background: `radial-gradient(circle, #0E7490, transparent 64%)`,
        opacity: 0.42,
        filter: 'blur(40px)',
      }}
    />
  </AbsoluteFill>
);

/* ─────────────────────── la finestra del browser ─────────────────────── */

const Finestra: React.FC<{
  sistemazione: Sistemazione;
  vista: Pagina;
  entrata: number;
  url: string;
  children: React.ReactNode;
}> = ({ sistemazione, vista, entrata, url, children }) => {
  const { cx, cy, k, ry } = sistemazione;
  const h = vista.h + BARRA;
  // Entrando, la finestra sale di poco e si raddrizza: l'inclinazione residua
  // basta a dare profondità, e sparire del tutto la farebbe sembrare piatta.
  const salita = (1 - entrata) * 70;
  const gradi = ry * (0.55 + 0.45 * (1 - entrata));

  return (
    <div
      style={{
        position: 'absolute',
        left: cx - vista.w / 2,
        top: cy - h / 2 + salita,
        width: vista.w,
        height: h,
        transform: `perspective(2600px) scale(${k * (0.985 + 0.015 * entrata)}) rotateY(${gradi}deg)`,
        transformOrigin: 'center center',
        opacity: entrata,
        borderRadius: 16,
        overflow: 'hidden',
        background: '#fff',
        border: '1px solid rgba(255,255,255,.16)',
        boxShadow: '0 60px 120px rgba(0,0,0,.6), 0 8px 24px rgba(0,0,0,.4)',
      }}
    >
      <div
        style={{
          height: BARRA,
          background: 'linear-gradient(#1B2942, #16223A)',
          display: 'flex',
          alignItems: 'center',
          paddingLeft: 18,
          gap: 9,
          borderBottom: '1px solid rgba(255,255,255,.07)',
        }}
      >
        {['#FF5F57', '#FEBC2E', '#28C840'].map((c) => (
          <div key={c} style={{ width: 12, height: 12, borderRadius: '50%', background: c, opacity: 0.85 }} />
        ))}
        <div
          style={{
            marginLeft: 22,
            padding: '6px 18px',
            borderRadius: 999,
            background: 'rgba(255,255,255,.07)',
            color: '#C7D6E8',
            fontSize: 17,
            letterSpacing: 0.2,
            maxWidth: 560,
            overflow: 'hidden',
            whiteSpace: 'nowrap',
          }}
        >
          {url}
        </div>
      </div>
      <div style={{ position: 'relative', width: vista.w, height: vista.h, overflow: 'hidden', background: '#fff' }}>
        {children}
      </div>
    </div>
  );
};

/**
 * Alone sull'elemento di cui si parla: tutto il resto scurisce, lui resta.
 *
 * Un solo elemento invece di quattro rettangoli intorno: l'ombra a spargimento
 * enorme copre l'intera pagina e il buco è il riquadro stesso, quindi il bordo
 * arrotondato combacia sempre.
 */
const Alone: React.FC<{ f: Fuoco; forza: number; scurisci: boolean }> = ({ f, forza, scurisci }) => (
  <div
    style={{
      position: 'absolute',
      left: f.x - 12,
      top: f.y - 12,
      width: f.w + 24,
      height: f.h + 24,
      borderRadius: 12,
      boxShadow: scurisci
        ? `0 0 0 9999px rgba(8,14,26,${0.42 * forza})`
        : `0 0 26px ${6 * forza}px rgba(41,151,212,${0.28 * forza})`,
      outline: `2px solid rgba(41,151,212,${0.95 * forza})`,
      outlineOffset: -1,
    }}
  />
);

/* ─────────────────────── le fasce di testo ─────────────────────── */

const Didascalia: React.FC<{ testo: string; t: number; numero: string; y: number }> = ({ testo, t, numero, y }) => {
  const e = molla(t);

  return (
    <div
      style={{
        position: 'absolute',
        left: 150,
        right: 150,
        top: y,
        display: 'flex',
        alignItems: 'flex-start',
        gap: 22,
        opacity: e,
        transform: `translateY(${(1 - e) * 18}px)`,
      }}
    >
      <div
        style={{
          flex: '0 0 auto',
          marginTop: 6,
          padding: '4px 13px',
          borderRadius: 8,
          background: BLU,
          color: '#05233B',
          fontSize: 21,
          fontWeight: 700,
          letterSpacing: 0.5,
        }}
      >
        {numero}
      </div>
      <Parole
        testo={testo}
        t={t - 0.1}
        passo={0.045}
        stile={{ color: PANNA, fontSize: 36, lineHeight: 1.32, fontWeight: 500, letterSpacing: -0.3 }}
      />
    </div>
  );
};

/* ─────────────────────── le scene di solo testo ─────────────────────── */

const Apertura: React.FC<{ scena: Extract<Scena, { tipo: 'apertura' }>; t: number }> = ({ scena, t }) => {
  const uscita = 1 - conAgio(t, scena.durata - 0.5, scena.durata);
  // Il lockup del marchio scrive già «EasyLab»: ripeterlo sotto a caratteri
  // cubitali è la stessa parola due volte, e la seconda non aggiunge niente.
  // Quando il titolo È il nome, il posto grande va al sottotitolo, che dice
  // di che cosa parla il video.
  const gliLoDiceIlMarchio = /^easy\s*lab$/i.test(scena.titolo.trim());

  return (
    <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', opacity: uscita }}>
      <div style={{ opacity: molla(t), transform: `translateY(${(1 - molla(t)) * 14}px)` }}>
        <Marchio larghezza={380} />
      </div>
      <div style={{ width: molla(t - 0.35) * 120, height: 3, background: BLU, marginTop: 38, marginBottom: 34 }} />
      {gliLoDiceIlMarchio ? (
        <Parole
          testo={scena.sottotitolo}
          t={t - 0.5}
          stile={{ color: '#fff', fontSize: 62, fontWeight: 600, letterSpacing: -1.4 }}
        />
      ) : (
        <>
          <Parole
            testo={scena.titolo}
            t={t - 0.5}
            stile={{ color: '#fff', fontSize: 92, fontWeight: 700, letterSpacing: -2.4 }}
          />
          <div style={{ height: 18 }} />
          <Parole
            testo={scena.sottotitolo}
            t={t - 0.85}
            stile={{ color: GRIGIO, fontSize: 36, fontWeight: 400, letterSpacing: -0.2 }}
          />
        </>
      )}
    </AbsoluteFill>
  );
};

/**
 * Stacco di capitolo: una parola sola, grande, su una tendina che entra e esce
 * di lato. Non è decorazione — spezza un video lungo in tratti che si possono
 * cercare, e dice prima che cosa si sta per vedere.
 */
const Cartello: React.FC<{ scena: Extract<Scena, { tipo: 'cartello' }>; t: number; numero: string }> = ({
  scena,
  t,
  numero,
}) => {
  const dentro = conAgio(t, 0, 0.5);
  const fuori = conAgio(t, scena.durata - 0.45, scena.durata);
  const taglio = `inset(0 ${(1 - dentro) * 100}% 0 ${fuori * 100}%)`;

  return (
    <AbsoluteFill style={{ clipPath: taglio }}>
      <AbsoluteFill style={{ background: `linear-gradient(115deg, ${BLU_CUPO}, #072F52 65%, #0B1220)` }} />
      {/* Il numero grande in filigrana non è un vezzo: un campo di colore
          uniforme fa sembrare il video fermo, e qui dà anche il conto dei
          capitoli senza doverlo scrivere. */}
      <div
        style={{
          position: 'absolute',
          right: 120,
          top: 40,
          fontSize: 460,
          fontWeight: 800,
          letterSpacing: -14,
          color: 'rgba(255,255,255,.085)',
          opacity: conAgio(t, 0.1, 0.9),
          transform: `translateY(${(1 - conAgio(t, 0.1, 1.1)) * 40}px)`,
        }}
      >
        {numero}
      </div>
      <div
        style={{
          position: 'absolute',
          left: 0,
          bottom: 132,
          height: 4,
          width: conAgio(t, 0.2, 1.1) * 640,
          background: BLU,
        }}
      />
      <AbsoluteFill style={{ justifyContent: 'center', paddingLeft: 190 }}>
        <div
          style={{
            color: 'rgba(255,255,255,.62)',
            fontSize: 24,
            fontWeight: 700,
            letterSpacing: 4,
            textTransform: 'uppercase',
            opacity: molla(t - 0.25),
            marginBottom: 20,
          }}
        >
          {scena.occhiello}
        </div>
        <Parole
          testo={scena.parola}
          t={t - 0.35}
          salita={26}
          stile={{ color: '#fff', fontSize: 132, fontWeight: 700, letterSpacing: -4 }}
        />
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

const Chiusura: React.FC<{ scena: Extract<Scena, { tipo: 'chiusura' }>; t: number }> = ({ scena, t }) => (
  <AbsoluteFill style={{ justifyContent: 'center', paddingLeft: 210, paddingRight: 160 }}>
    <Parole
      testo={scena.titolo}
      t={t}
      stile={{ color: '#fff', fontSize: 66, fontWeight: 700, letterSpacing: -1.8 }}
    />
    <div style={{ height: 46 }} />
    {scena.punti.map((punto, i) => {
      const e = molla(t - 0.4 - i * 0.28);

      return (
        <div
          key={i}
          style={{
            display: 'flex',
            gap: 24,
            alignItems: 'flex-start',
            marginBottom: 28,
            opacity: e,
            transform: `translateX(${(1 - e) * 26}px)`,
          }}
        >
          <div style={{ color: BLU, fontSize: 34, fontWeight: 700, width: 42 }}>{i + 1}</div>
          <div style={{ color: PANNA, fontSize: 34, lineHeight: 1.35, maxWidth: 1180 }}>{punto}</div>
        </div>
      );
    })}
    <div style={{ position: 'absolute', right: 150, bottom: 110, opacity: molla(t - 1.4) }}>
      <Marchio larghezza={230} />
    </div>
  </AbsoluteFill>
);

/* ─────────────────────── le scene con la pagina ─────────────────────── */

const Schermo: React.FC<{
  slug: string;
  scena: Extract<Scena, { tipo: 'scatto' | 'ripresa' }>;
  t: number;
  entrata: number;
  numero: string;
}> = ({ slug, scena, t, entrata, numero }) => {
  const vista = scena.vista;
  const { sistemazione, testoY } = impagina(vista, scena.didascalia.length);
  const pagina = scena.pagina;

  const pieno: Fuoco = { x: 0, y: 0, w: pagina.w, h: Math.min(pagina.h, vista.h) };
  const tetto = scena.tipo === 'ripresa' ? ZOOM_CLIP_MAX : ZOOM_SCATTO_MAX;

  let cam: Inquadratura;
  if ('scorri' in scena && scena.scorri) {
    // Panoramica: la pagina scorre da sola, alla velocità della lettura.
    const q = conAgio(t, 0.9, scena.durata - 0.5);
    const a = inquadra({ x: 0, y: 0, w: pagina.w, h: vista.h }, pagina, vista, 1);
    const b = inquadra({ x: 0, y: pagina.h - vista.h, w: pagina.w, h: vista.h }, pagina, vista, 1);
    cam = interpolaInquadratura(a, b, q);
  } else if (scena.fuoco) {
    const q = conAgio(t, 0.15, Math.min(scena.durata * 0.55, 2.2));
    cam = interpolaInquadratura(
      inquadra(pieno, pagina, vista, tetto),
      inquadra(scena.fuoco, pagina, vista, tetto),
      q,
    );
  } else {
    // Respiro: una spinta lentissima. Ferma del tutto, una schermata sembra un PDF.
    const q = conAgio(t, 0, scena.durata);
    cam = interpolaInquadratura(inquadra(pieno, pagina, vista, 1), inquadra(pieno, pagina, vista, 1.05), q);
  }

  // ⚠️ L'alone va sull'ELEMENTO, non sul riquadro della camera: quello è già
  // stato allargato per l'inquadratura, quindi copre mezza pagina e lo scuro
  // finisce fuori campo — cioè non si vede niente, che è quanto succedeva.
  /*
   * ⛔ Su una RIPRESA l'alone dura un istante e NON scurisce la pagina.
   *
   * Il riquadro è misurato PRIMA dell'azione, e un'azione cambia la pagina —
   * anche senza cambiare indirizzo, come fa Livewire. Tenendolo acceso, quelle
   * coordinate finiscono sopra un contenuto nuovo che non c'entra niente: il
   * 26 Set 2026 l'alone è rimasto a illuminare un rettangolo vuoto di fianco a
   * un bottone, per due secondi, e sembrava un guasto.
   *
   * Quindi: si accende sul bersaglio mentre il cursore arriva, e si spegne al
   * click. Dopo il click quel che conta è il RISULTATO, non più il bersaglio.
   * Lo scurimento resta ai soli fermi immagine, dove la pagina non si muove.
   */
  const ripresa = scena.tipo === 'ripresa';
  const fine = ripresa ? 0.62 : scena.durata - 0.8;
  const alone = scena.elemento
    ? conAgio(t, ripresa ? 0.05 : 0.45, ripresa ? 0.3 : 1.2) * (1 - conAgio(t, fine, fine + 0.45))
    : 0;

  // Il cursore nella finestra: pagina → vista, con la camera del momento.
  const punto = scena.cursore
    ? { x: scena.cursore.x * cam.s + cam.tx, y: scena.cursore.y * cam.s + cam.ty }
    : null;
  const battito = scena.tipo === 'ripresa' ? conAgio(t, 0.15, 0.75) : 0;

  return (
    <>
      <Finestra sistemazione={sistemazione} vista={vista} entrata={entrata} url={scena.url}>
        <div
          style={{
            position: 'absolute',
            width: pagina.w,
            height: pagina.h,
            transformOrigin: '0 0',
            transform: `translate(${cam.tx}px, ${cam.ty}px) scale(${cam.s})`,
          }}
        >
          {scena.tipo === 'scatto' ? (
            <Img
              src={staticFile(`${slug}/${scena.file}`)}
              style={{ width: '100%', height: '100%', display: 'block' }}
            />
          ) : (
            <OffthreadVideo
              src={staticFile(`${slug}/${scena.file}`)}
              style={{ width: '100%', height: '100%', display: 'block' }}
              muted
            />
          )}
          {scena.elemento && alone > 0 && <Alone f={scena.elemento} forza={alone} scurisci={!ripresa} />}
        </div>
        {punto && (
          <Cursore
            x={punto.x}
            y={punto.y}
            opacita={scena.tipo === 'ripresa' ? conAgio(t, 0, 0.3) * (1 - conAgio(t, 1.4, 1.9)) : conAgio(t, 0.3, 0.8)}
            pulsazione={battito > 0 && battito < 1 ? battito : 0}
          />
        )}
      </Finestra>

      <Didascalia testo={scena.didascalia} t={t - SFUMATURA} numero={numero} y={testoY} />
    </>
  );
};

/* ─────────────────────── il montaggio ─────────────────────── */

const durateDi = (copione: Copione) => {
  const inizi: number[] = [];
  let cursore = 0;
  for (const s of copione.scene) {
    inizi.push(cursore);
    cursore += s.durata;
  }

  return { inizi, totale: cursore };
};

export const durataInFotogrammiVetrina = (copione: Copione): number =>
  Math.max(1, Math.round(durateDi(copione).totale * FPS));

export const Vetrina: React.FC<ProprietaVetrina> = ({ slug, copione, voci = [] }) => {
  const frame = useCurrentFrame();
  const t = frame / FPS;
  const { inizi, totale } = durateDi(copione);

  const passi = copione.scene.filter((s) => s.tipo === 'scatto' || s.tipo === 'ripresa').length;
  let visti = 0;
  let capitoli = 0;
  const numeri = copione.scene.map((s) => {
    if (s.tipo === 'scatto' || s.tipo === 'ripresa') return `${++visti}/${passi}`;
    if (s.tipo === 'cartello') return String(++capitoli).padStart(2, '0');

    return '';
  });

  // Musica che si fa da parte sotto la voce: a volume pieno le due cose
  // competono e si capisce peggio il parlato che con il silenzio.
  const sottoVoce = voci.some((v) => t >= inizi[v.i] && t <= inizi[v.i] + v.durata) ? 1 : 0;
  const progresso = totale > 0 ? Math.min(1, t / totale) : 0;

  return (
    <AbsoluteFill style={{ backgroundColor: INCHIOSTRO, fontFamily: 'Inter, system-ui, sans-serif' }}>
      <Audio
        src={staticFile('audio/tema.wav')}
        loop
        volume={(f) =>
          interpolate(f / FPS, [0, 1.6, totale - 2.2, totale], [0, 0.5, 0.5, 0], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          }) * (1 - 0.78 * sottoVoce)
        }
      />

      <Palco t={t} />

      {copione.scene.map((scena, i) => {
        // Ogni scena comincia SFUMATURA prima del suo istante e sale sopra
        // quella che esce, che a quel punto è ancora viva: è così che due clip
        // vere si succedono senza un fotogramma nero fra loro.
        const anticipo = i === 0 ? 0 : SFUMATURA;
        const da = Math.max(0, Math.round((inizi[i] - anticipo) * FPS));
        const per = Math.round((scena.durata + anticipo) * FPS) + 1;

        return (
          <Sequence key={i} from={da} durationInFrames={per} layout="none">
            <ScenaResa
              slug={slug}
              scena={scena}
              anticipo={anticipo}
              numero={numeri[i]}
              voce={voci.find((v) => v.i === i)}
            />
          </Sequence>
        );
      })}

      <div style={{ position: 'absolute', left: 0, bottom: 0, width: LARGHEZZA, height: 4, background: 'rgba(255,255,255,.08)' }}>
        <div style={{ width: progresso * LARGHEZZA, height: '100%', background: `linear-gradient(90deg, ${BLU_CUPO}, ${BLU})` }} />
      </div>
    </AbsoluteFill>
  );
};

/**
 * Una scena, col suo tempo che parte da `-anticipo` (la coda dello stacco) e
 * arriva a `durata`.
 */
const ScenaResa: React.FC<{
  slug: string;
  scena: Scena;
  anticipo: number;
  numero: string;
  voce?: Voce;
}> = ({ slug, scena, anticipo, numero, voce }) => {
  const t = useCurrentFrame() / FPS - anticipo;
  const entrata = anticipo > 0 ? conAgio(t, -anticipo, 0) : 1;

  return (
    <AbsoluteFill style={{ opacity: entrata }}>
      {voce && (
        <Sequence from={Math.round(anticipo * FPS)} layout="none">
          <Audio src={staticFile(`${slug}/voce/${String(voce.i).padStart(2, '0')}.wav`)} />
        </Sequence>
      )}
      {scena.tipo === 'apertura' && <Effetto nome="apertura" da={anticipo} />}
      {scena.tipo === 'cartello' && <Effetto nome="stacco" da={anticipo} />}
      {scena.tipo === 'chiusura' && <Effetto nome="conferma" da={anticipo} />}
      {scena.tipo === 'ripresa' && scena.suono && (
        <>
          {/* Scrivere in un campo vuol dire prima cliccarlo: due suoni, non uno. */}
          {(scena.suono === 'click' || scena.suono === 'tastiera') && <Effetto nome="click" da={anticipo} />}
          {scena.suono !== 'click' && <Effetto nome={scena.suono} da={anticipo} />}
        </>
      )}

      {scena.tipo === 'apertura' && <Apertura scena={scena} t={t} />}
      {scena.tipo === 'cartello' && <Cartello scena={scena} t={t} numero={numero} />}
      {scena.tipo === 'chiusura' && <Chiusura scena={scena} t={t} />}
      {(scena.tipo === 'scatto' || scena.tipo === 'ripresa') && (
        <Schermo slug={slug} scena={scena} t={t} entrata={entrata} numero={numero} />
      )}
    </AbsoluteFill>
  );
};

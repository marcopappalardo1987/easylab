import React from 'react';
import { AbsoluteFill, Audio, Img, Sequence, staticFile, useCurrentFrame, interpolate, Easing } from 'remotion';
import type { Fuoco, Manifest, Passo } from './tipi';
import { ALTEZZA, FPS, LARGHEZZA } from './camera';
import { Cursore } from './Cursore';
import { Marchio } from './Marchio';
import { Parole, molla } from './Testo';
import { INTRO } from '../../lib/tempi';

/**
 * Variante «Più» del montaggio: stessa sostanza didattica di `Guida`, linguaggio
 * visivo più marcato. Vive accanto all'originale e non lo tocca, così si possono
 * confrontare sullo stesso manifest prima di decidere quale pubblicare.
 *
 * Le tre differenze che contano, e il perché di ciascuna:
 *
 * 1. **La schermata sta dentro una finestra**, non a tutto campo. Una guida di un
 *    gestionale mostra un'applicazione web: incorniciarla dice «questo è il tuo
 *    browser» senza scriverlo, e lascia margine per il resto della scena.
 * 2. **Il fuoco si vede**, non solo si inquadra. La camera zooma come prima, ma
 *    intorno al rettangolo utile cala un velo e compare un contorno: chi guarda
 *    sa DOVE guardare anche se il movimento gli è sfuggito.
 * 3. **Lo stato è sempre a schermo**: barra di avanzamento in alto e capitolo
 *    corrente accanto. Una guida da novanta secondi senza riferimenti sembra più
 *    lunga di quello che è.
 */

const VOLUME = 0.36;
const MOVIMENTO = 0.75;
const RESPIRO = 0.4;

/** Finestra in cui vive la schermata, dentro il fotogramma 1920×1080. */
const FINESTRA = { x: 176, y: 104, w: 1568, h: 742 };
const BARRA = 52;
const VISTA = { w: FINESTRA.w, h: FINESTRA.h - BARRA };

const ACCENTO = '#2997D4';
const INCHIOSTRO = '#0A1220';

type Inquadratura = { scala: number; tx: number; ty: number };

/**
 * Inquadratura del fuoco dentro la finestra, **senza mai scoprire i bordi della
 * pagina**. Due vincoli insieme:
 *
 * · la scala non scende sotto quella che copre la vista (`max` sui due assi), o
 *   ai lati comparirebbero bande vuote — il difetto che si vedeva alla prima
 *   prova, perché il rettangolo del fuoco ha quasi sempre un'altra proporzione;
 * · la traslazione si blocca ai bordi, così lo zoom su un fuoco laterale
 *   accosta la pagina al bordo invece di uscirne.
 */
const inquadraNellaVista = (f: Fuoco, pagina: { w: number; h: number }): Inquadratura => {
  const copertura = Math.max(VISTA.w / pagina.w, VISTA.h / pagina.h);
  const scala = Math.max(Math.min(VISTA.w / f.w, VISTA.h / f.h), copertura);
  const fra_ = (v: number, min: number, max: number) => Math.min(Math.max(v, min), max);

  return {
    scala,
    tx: fra_(VISTA.w / 2 - (f.x + f.w / 2) * scala, VISTA.w - pagina.w * scala, 0),
    ty: fra_(VISTA.h / 2 - (f.y + f.h / 2) * scala, VISTA.h - pagina.h * scala, 0),
  };
};

const fraInquadrature = (a: Inquadratura, b: Inquadratura, t: number): Inquadratura => ({
  // Scala logaritmica: l'occhio legge i rapporti, non le differenze (come in `camera.ts`).
  scala: Math.exp(Math.log(a.scala) + (Math.log(b.scala) - Math.log(a.scala)) * t),
  tx: a.tx + (b.tx - a.tx) * t,
  ty: a.ty + (b.ty - a.ty) * t,
});

const fuocoDi = (p: Passo | null, w: number, h: number): Fuoco => p?.fuoco ?? { x: 0, y: 0, w, h };
const eCartello = (p: Passo) => p.capitolo !== null || p.chiusura !== null;

export const durataInFotogrammiPiu = (m: Manifest): number =>
  Math.round((INTRO + m.passi.reduce((a, p) => a + p.durata, 0)) * FPS);

/** Una voce già sintetizzata per un passo: indice del passo e durata del file. */
export type Voce = { i: number; durata: number };

export type ProprietaGuidaPiu = { slug: string; manifest: Manifest; voci?: Voce[] };

export const GuidaPiu: React.FC<ProprietaGuidaPiu> = ({ slug, manifest, voci = [] }) => {
  const frame = useCurrentFrame();
  const t = frame / FPS;
  const totale = durataInFotogrammiPiu(manifest) / FPS;

  let trascorso = t - INTRO;
  let i = 0;
  while (i < manifest.passi.length - 1 && trascorso >= manifest.passi[i].durata) {
    trascorso -= manifest.passi[i].durata;
    i += 1;
  }
  const passo = manifest.passi[i];

  let iScatto = i;
  while (iScatto >= 0 && manifest.passi[iScatto].file === null) iScatto -= 1;
  if (iScatto < 0) iScatto = manifest.passi.findIndex((p) => p.file !== null);
  const scatto = iScatto >= 0 ? manifest.passi[iScatto] : null;

  let iPrecedente = iScatto - 1;
  while (iPrecedente >= 0 && manifest.passi[iPrecedente].file === null) iPrecedente -= 1;
  const precedente = iPrecedente >= 0 ? manifest.passi[iPrecedente] : null;

  // Mezzo secondo di pagina intera prima di stringere: senza, chi guarda vede
  // un dettaglio e non sa in che schermata si trova. È il difetto che salta
  // all'occhio nella prima anteprima, dove la colonna di sinistra non si vedeva mai.
  const APERTURA = 0.5;

  const avanzamento =
    iScatto === i
      ? interpolate(Math.max(0, trascorso), [APERTURA, APERTURA + MOVIMENTO], [0, 1], {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
          easing: Easing.bezier(0.33, 0, 0.15, 1),
        })
      : 1;

  const pagina = { w: manifest.larghezza, h: manifest.altezza };
  const intera = inquadraNellaVista({ x: 0, y: 0, w: pagina.w, h: pagina.h }, pagina);
  const camera = fraInquadrature(
    intera,
    inquadraNellaVista(fuocoDi(scatto, pagina.w, pagina.h), pagina),
    precedente === null && t < INTRO ? 1 : avanzamento,
  );

  /** Punto della pagina → punto del fotogramma, passando per la finestra. */
  const suSchermo = (p: { x: number; y: number }) => ({
    x: FINESTRA.x + camera.tx + p.x * camera.scala,
    y: FINESTRA.y + BARRA + camera.ty + p.y * camera.scala,
  });

  const puntoOra = iScatto === i ? passo.cursore : null;
  const puntoPrima = precedente?.cursore ?? null;
  const punto = puntoOra
    ? puntoPrima
      ? {
          x: puntoPrima.x + (puntoOra.x - puntoPrima.x) * avanzamento,
          y: puntoPrima.y + (puntoOra.y - puntoPrima.y) * avanzamento,
        }
      : puntoOra
    : null;

  const istanteClick = MOVIMENTO + 0.35;
  const pulsazione =
    iScatto === i && passo.click && trascorso >= istanteClick
      ? interpolate(trascorso, [istanteClick, istanteClick + 0.5], [0, 1], { extrapolateRight: 'clamp' })
      : 0;

  // Il riquadro del fuoco compare DOPO che la camera è arrivata: durante il
  // movimento sarebbe un rettangolo che insegue, cioè rumore.
  const fuoco = iScatto === i ? passo.fuoco : null;
  const riquadro = fuoco
    ? (() => {
        const a = suSchermo({ x: fuoco.x, y: fuoco.y });
        const b = suSchermo({ x: fuoco.x + fuoco.w, y: fuoco.y + fuoco.h });

        return { x: a.x, y: a.y, w: b.x - a.x, h: b.y - a.y };
      })()
    : null;
  const evidenza = fuoco
    ? interpolate(trascorso, [APERTURA + MOVIMENTO * 0.7, APERTURA + MOVIMENTO + 0.45], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
      })
    : 0;

  const velo = eCartello(passo) ? interpolate(trascorso, [0, 0.22], [0, 1], { extrapolateRight: 'clamp' }) : 0;
  const aperturaIntro = interpolate(t, [0, INTRO - 0.5, INTRO], [1, 1, 0], { extrapolateRight: 'clamp' });
  const nScatti = manifest.passi.filter((p) => p.file !== null).length;
  const avanzamentoTotale = interpolate(t, [INTRO, totale], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  /** Istante d'inizio di ogni passo, in secondi dall'inizio del video. */
  const inizi: number[] = [];
  let acc = INTRO;
  for (const p of manifest.passi) {
    inizi.push(acc);
    acc += p.durata;
  }

  /** Quanto la musica deve farsi da parte: 1 mentre si parla, 0 in silenzio. */
  const sottoVoce = voci.reduce((max, v) => {
    const da = inizi[v.i] + RESPIRO;
    const a = da + v.durata;
    const dentro = interpolate(t, [da - 0.35, da, a, a + 0.5], [0, 1, 1, 0], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });

    return Math.max(max, dentro);
  }, 0);

  // Il capitolo che stiamo raccontando: l'ultimo cartello incontrato.
  let capitoloCorrente = '';
  for (let k = 0; k <= i; k += 1) {
    const c = manifest.passi[k].capitolo;
    if (c) capitoloCorrente = c.titolo;
  }

  return (
    <AbsoluteFill style={{ backgroundColor: INCHIOSTRO, fontFamily: 'Inter, system-ui, sans-serif' }}>
      <Audio
        src={staticFile('audio/tema.wav')}
        loop
        volume={(f) =>
          interpolate(f / FPS, [0, 1.5, totale - 2.5, totale], [0, VOLUME, VOLUME, 0], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          }) *
          // Sotto il parlato la musica scende al 22%: a volume pieno le due cose
          // si coprono, e una guida non è un videoclip.
          (1 - 0.78 * sottoVoce)
        }
      />

      {voci.map((v) => (
        <Sequence
          key={v.i}
          // Un respiro prima di attaccare: la voce che parte sull'istante del
          // taglio sembra incollata, e copre l'inquadratura d'apertura.
          from={Math.round((inizi[v.i] + RESPIRO) * FPS)}
          durationInFrames={Math.ceil(v.durata * FPS) + 2}
        >
          <Audio src={staticFile(`${slug}/voce/${String(v.i).padStart(2, '0')}.wav`)} volume={1} />
        </Sequence>
      ))}

      {/* Fondale: due aloni morbidi e una griglia appena accennata. Non decora
          soltanto: dà profondità alla finestra, che altrimenti galleggia sul piatto. */}
      <AbsoluteFill
        style={{
          background: `radial-gradient(1100px 620px at 18% 8%, rgba(41,151,212,.22), transparent 62%),
                       radial-gradient(900px 560px at 88% 96%, rgba(37,99,235,.16), transparent 60%),
                       linear-gradient(160deg, #0A1220 0%, #101E33 58%, #0A1220 100%)`,
        }}
      />
      <AbsoluteFill
        style={{
          opacity: 0.35,
          backgroundImage:
            'linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px)',
          backgroundSize: '72px 72px',
          maskImage: 'radial-gradient(1200px 700px at 50% 45%, rgba(0,0,0,.9), transparent 78%)',
        }}
      />

      {/* La finestra */}
      <div
        style={{
          position: 'absolute',
          left: FINESTRA.x,
          top: FINESTRA.y,
          width: FINESTRA.w,
          height: FINESTRA.h,
          borderRadius: 20,
          overflow: 'hidden',
          background: '#0F1A2B',
          border: '1px solid rgba(255,255,255,.10)',
          boxShadow: '0 44px 120px rgba(0,0,0,.62), 0 2px 0 rgba(255,255,255,.06) inset',
        }}
      >
        <div
          style={{
            height: BARRA,
            display: 'flex',
            alignItems: 'center',
            gap: 10,
            padding: '0 18px',
            background: 'linear-gradient(180deg, #16243A, #111C2E)',
            borderBottom: '1px solid rgba(255,255,255,.07)',
          }}
        >
          {['#F45B5B', '#F5B34A', '#4CC97A'].map((c) => (
            <div key={c} style={{ width: 12, height: 12, borderRadius: 6, background: c, opacity: 0.9 }} />
          ))}
          <div
            style={{
              marginLeft: 14,
              flex: 1,
              maxWidth: 620,
              height: 30,
              borderRadius: 15,
              background: 'rgba(255,255,255,.06)',
              border: '1px solid rgba(255,255,255,.07)',
              color: '#93A6C0',
              fontSize: 15,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              letterSpacing: 0.2,
            }}
          >
            <span style={{ opacity: 0.75 }}>app.easylab.technology</span>
            {capitoloCorrente && (
              <>
                <span style={{ margin: '0 10px', opacity: 0.45 }}>›</span>
                <span style={{ color: '#D3E2F2' }}>{capitoloCorrente}</span>
              </>
            )}
          </div>
        </div>

        <div style={{ position: 'relative', width: VISTA.w, height: VISTA.h, overflow: 'hidden', background: '#fff' }}>
          <div
            style={{
              position: 'absolute',
              width: manifest.larghezza,
              height: manifest.altezza,
              transformOrigin: '0 0',
              transform: `translate(${camera.tx}px, ${camera.ty}px) scale(${camera.scala})`,
              filter: velo > 0 ? `blur(${velo * 9}px)` : undefined,
            }}
          >
            {scatto?.file && (
              <Img src={staticFile(`${slug}/${scatto.file}`)} style={{ width: '100%', height: '100%', display: 'block' }} />
            )}
          </div>
        </div>
      </div>

      {/* Il fuoco: velo intorno e contorno che si accende. Il velo è un'ombra
          enorme sul riquadro, così resta un solo elemento invece di quattro bande. */}
      {riquadro && evidenza > 0 && (
        // Dentro un contenitore che ricalca la vista: il velo e il contorno non
        // devono sbordare sulla barra del browser né fuori dalla finestra.
        <div
          style={{
            position: 'absolute',
            left: FINESTRA.x,
            top: FINESTRA.y + BARRA,
            width: VISTA.w,
            height: VISTA.h,
            overflow: 'hidden',
            borderRadius: '0 0 20px 20px',
            pointerEvents: 'none',
          }}
        >
        <div
          style={{
            position: 'absolute',
            left: riquadro.x - FINESTRA.x - 10,
            top: riquadro.y - (FINESTRA.y + BARRA) - 10,
            width: riquadro.w + 20,
            height: riquadro.h + 20,
            borderRadius: 14,
            border: `2px solid rgba(41,151,212,${0.85 * evidenza})`,
            boxShadow: `0 0 0 9999px rgba(10,18,32,${0.42 * evidenza}), 0 0 34px rgba(41,151,212,${0.35 * evidenza})`,
            transform: `scale(${1 + (1 - evidenza) * 0.02})`,
          }}
        />
        </div>
      )}

      {punto && (
        <Cursore
          x={suSchermo(punto).x}
          y={suSchermo(punto).y}
          opacita={interpolate(trascorso, [0, 0.3], [puntoPrima ? 1 : 0, 1], { extrapolateRight: 'clamp' })}
          pulsazione={pulsazione}
        />
      )}

      {/* Barra di avanzamento e capitolo corrente */}
      {t >= INTRO && (
        <div style={{ position: 'absolute', left: FINESTRA.x, top: 64, width: FINESTRA.w }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 12 }}>
            <Marchio larghezza={126} />
            <div style={{ color: '#7D93B0', fontSize: 19, letterSpacing: 0.3 }}>{manifest.titolo}</div>
            {capitoloCorrente && (
              <>
                <div style={{ width: 4, height: 4, borderRadius: 2, background: '#41546E' }} />
                <div style={{ color: '#C7D6E8', fontSize: 19 }}>{capitoloCorrente}</div>
              </>
            )}
          </div>
          <div style={{ height: 4, borderRadius: 2, background: 'rgba(255,255,255,.08)', overflow: 'hidden' }}>
            <div
              style={{
                width: `${avanzamentoTotale * 100}%`,
                height: '100%',
                borderRadius: 2,
                background: `linear-gradient(90deg, ${ACCENTO}, #7CC6F0)`,
              }}
            />
          </div>
        </div>
      )}

      {velo > 0 && <AbsoluteFill style={{ backgroundColor: INCHIOSTRO, opacity: velo * 0.9 }} />}

      {/* Cartello di capitolo */}
      {passo.capitolo && (
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 18 }}>
          <div
            style={{
              width: molla(trascorso - 0.1) * 92,
              height: 4,
              borderRadius: 2,
              background: ACCENTO,
              marginBottom: 8,
            }}
          />
          <Parole
            testo={passo.capitolo.occhiello}
            t={trascorso}
            ritardo={0.18}
            passo={0.04}
            stile={{ color: ACCENTO, fontSize: 26, fontWeight: 600, letterSpacing: 4, textTransform: 'uppercase' }}
          />
          <Parole
            testo={passo.capitolo.titolo}
            t={trascorso}
            ritardo={0.3}
            passo={0.07}
            salita={26}
            stile={{ color: '#F8FAFC', fontSize: 74, fontWeight: 700, letterSpacing: -1.4 }}
          />
        </AbsoluteFill>
      )}

      {/* Scheda di chiusura */}
      {passo.chiusura && (
        <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 26 }}>
          <Parole
            testo={passo.chiusura.titolo}
            t={trascorso}
            ritardo={0.2}
            passo={0.07}
            salita={24}
            stile={{ color: '#F8FAFC', fontSize: 62, fontWeight: 700, letterSpacing: -1.2 }}
          />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            {passo.chiusura.punti.map((punto_, k) => {
              const p = molla(trascorso - 0.55 - k * 0.14);

              return (
                <div
                  key={punto_}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 14,
                    opacity: p,
                    transform: `translateX(${(1 - p) * -24}px)`,
                  }}
                >
                  <div style={{ width: 10, height: 10, borderRadius: 5, background: ACCENTO }} />
                  <div style={{ color: '#CBD5E1', fontSize: 30 }}>{punto_}</div>
                </div>
              );
            })}
          </div>
        </AbsoluteFill>
      )}

      {/* Didascalia: scheda che entra con una molla, numero in evidenza */}
      {!eCartello(passo) && t >= INTRO && (
        <div
          style={{
            position: 'absolute',
            left: FINESTRA.x,
            top: FINESTRA.y + FINESTRA.h + 26,
            width: FINESTRA.w,
            display: 'flex',
            alignItems: 'stretch',
            gap: 0,
            opacity: molla(trascorso - 0.15),
            transform: `translateY(${(1 - molla(trascorso - 0.15)) * 26}px)`,
          }}
        >
          <div
            style={{
              width: 76,
              borderRadius: '16px 0 0 16px',
              background: `linear-gradient(160deg, ${ACCENTO}, #1F7FB8)`,
              color: '#fff',
              fontSize: 34,
              fontWeight: 700,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            {passo.n}
          </div>
          <div
            style={{
              flex: 1,
              borderRadius: '0 16px 16px 0',
              background: 'rgba(12,21,35,.92)',
              border: '1px solid rgba(255,255,255,.09)',
              borderLeft: 'none',
              padding: '22px 26px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: 24,
              backdropFilter: 'blur(6px)',
            }}
          >
            <Parole
              testo={passo.didascalia}
              t={trascorso}
              ritardo={0.22}
              passo={0.035}
              salita={12}
              stile={{ color: '#EEF4FB', fontSize: 31, lineHeight: 1.32, fontWeight: 500 }}
            />
            <div style={{ color: '#6E86A4', fontSize: 20, whiteSpace: 'nowrap' }}>
              {passo.n}/{nScatti}
            </div>
          </div>
        </div>
      )}

      {/* Apertura */}
      {aperturaIntro > 0 && (
        <AbsoluteFill
          style={{
            opacity: aperturaIntro,
            alignItems: 'center',
            justifyContent: 'center',
            flexDirection: 'column',
            gap: 20,
            background: `radial-gradient(900px 520px at 50% 40%, rgba(41,151,212,.20), transparent 64%), ${INCHIOSTRO}`,
          }}
        >
          <div style={{ opacity: molla(t - 0.15), transform: `scale(${0.94 + molla(t - 0.15) * 0.06})` }}>
            <Marchio larghezza={430} />
          </div>
          <div
            style={{
              width: molla(t - 0.4) * 120,
              height: 4,
              borderRadius: 2,
              background: `linear-gradient(90deg, ${ACCENTO}, #7CC6F0)`,
              marginTop: 16,
              marginBottom: 8,
            }}
          />
          <Parole
            testo={manifest.titolo}
            t={t}
            ritardo={0.5}
            passo={0.08}
            salita={28}
            stile={{ color: '#F8FAFC', fontSize: 86, fontWeight: 700, letterSpacing: -1.8 }}
          />
          <Parole
            testo={manifest.sottotitolo}
            t={t}
            ritardo={0.88}
            passo={0.03}
            salita={14}
            stile={{ color: '#93A6C0', fontSize: 34 }}
          />
        </AbsoluteFill>
      )}
    </AbsoluteFill>
  );
};

export const formatoPiu = { width: LARGHEZZA, height: ALTEZZA, fps: FPS };

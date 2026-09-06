import React from 'react';
import { AbsoluteFill, Audio, Img, staticFile, useCurrentFrame, interpolate, Easing } from 'remotion';
import type { Manifest, Passo } from './tipi';
import { ALTEZZA, FPS, LARGHEZZA, fra, fuocoDi, inquadra, proietta } from './camera';
import { Cursore } from './Cursore';
import { Didascalia } from './Didascalia';
import { Capitolo, Chiusura } from './Cartello';
import { Marchio } from './Marchio';
import { Parole, molla } from './Testo';

/** Secondi di spostamento della camera all'ingresso di ogni passo. */
const MOVIMENTO = 0.75;
const INTRO = 2.6;
const VOLUME = 0.36;

export type ProprietaGuida = { slug: string; manifest: Manifest };

const eCartello = (p: Passo) => p.capitolo !== null || p.chiusura !== null;

export const durataInFotogrammi = (m: Manifest): number =>
  Math.round((INTRO + m.passi.reduce((a, p) => a + p.durata, 0)) * FPS);

export const Guida: React.FC<ProprietaGuida> = ({ slug, manifest }) => {
  const frame = useCurrentFrame();
  const t = frame / FPS;
  const totale = durataInFotogrammi(manifest) / FPS;

  let trascorso = t - INTRO;
  let i = 0;
  while (i < manifest.passi.length - 1 && trascorso >= manifest.passi[i].durata) {
    trascorso -= manifest.passi[i].durata;
    i += 1;
  }

  const passo = manifest.passi[i];

  // Lo scatto da mostrare e la sua inquadratura vengono dall'ultimo passo che
  // ne aveva uno: sotto un cartello resta in piedi la schermata di prima.
  let iScatto = i;
  while (iScatto >= 0 && manifest.passi[iScatto].file === null) iScatto -= 1;
  // Un cartello messo prima del primo scatto non ha nulla dietro: guarda avanti,
  // così apre sulla schermata che sta per raccontare invece che sul nero.
  if (iScatto < 0) {
    iScatto = manifest.passi.findIndex((p) => p.file !== null);
  }
  const scatto = iScatto >= 0 ? manifest.passi[iScatto] : null;

  let iPrecedente = iScatto - 1;
  while (iPrecedente >= 0 && manifest.passi[iPrecedente].file === null) iPrecedente -= 1;
  const precedente = iPrecedente >= 0 ? manifest.passi[iPrecedente] : null;

  // La camera si muove solo mentre corre il passo che l'ha chiesta: se siamo su
  // un cartello, lo scatto sotto è già arrivato a destinazione.
  const avanzamento =
    iScatto === i
      ? interpolate(Math.max(0, trascorso), [0, MOVIMENTO], [0, 1], {
          extrapolateRight: 'clamp',
          easing: Easing.bezier(0.33, 0, 0.15, 1),
        })
      : 1;

  const da = inquadra(fuocoDi(precedente, manifest.larghezza, manifest.altezza));
  const a = inquadra(fuocoDi(scatto, manifest.larghezza, manifest.altezza));
  const camera = precedente === null && t < INTRO ? a : fra(da, a, avanzamento);

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

  const schermo = punto ? proietta(punto, camera) : null;
  // ⛔ Opaca dal PRIMO fotogramma, senza dissolvenza in entrata. Il fotogramma
  // zero è la copertina che ogni lettore video mostra da fermo: con la
  // dissolvenza si vedeva lo scatto nudo della prima schermata, cioè la guida
  // sembrava cominciare a metà. Trovato impaginando la pagina Guida, non
  // guardando il video — da fermo nessuno lo guarda.
  const aperturaIntro = interpolate(t, [0, INTRO - 0.5, INTRO], [1, 1, 0], { extrapolateRight: 'clamp' });
  const nScatti = manifest.passi.filter((p) => p.file !== null).length;

  // Il velo sotto i cartelli: nasconde la schermata quel tanto che basta perché
  // il testo si legga, senza farla sparire — resta il riferimento di dov'eravamo.
  // Il velo sale PRIMA del testo, non insieme: le schermate dell'app sono chiare,
  // e un titolo bianco su fondo quasi bianco resta illeggibile finché non è
  // coperto. Con la stessa rampa del testo le prime due parole non si leggono.
  const velo = eCartello(passo) ? interpolate(trascorso, [0, 0.22], [0, 1], { extrapolateRight: 'clamp' }) : 0;

  return (
    <AbsoluteFill style={{ backgroundColor: '#111C2E' }}>
      <Audio
        src={staticFile('audio/tema.wav')}
        loop
        volume={(f) =>
          interpolate(f / FPS, [0, 1.5, totale - 2.5, totale], [0, VOLUME, VOLUME, 0], {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
          })
        }
      />

      <AbsoluteFill style={{ overflow: 'hidden' }}>
        <div
          style={{
            position: 'absolute',
            width: manifest.larghezza,
            height: manifest.altezza,
            transformOrigin: '0 0',
            transform: `translate(${camera.tx}px, ${camera.ty}px) scale(${camera.scala})`,
            boxShadow: '0 30px 90px rgba(0,0,0,.55)',
            filter: velo > 0 ? `blur(${velo * 9}px)` : undefined,
          }}
        >
          {scatto?.file && (
            <Img src={staticFile(`${slug}/${scatto.file}`)} style={{ width: '100%', height: '100%', display: 'block' }} />
          )}
        </div>
      </AbsoluteFill>

      {velo > 0 && <AbsoluteFill style={{ backgroundColor: '#111C2E', opacity: velo * 0.88 }} />}

      {schermo && (
        <Cursore
          x={schermo.x}
          y={schermo.y}
          opacita={interpolate(trascorso, [0, 0.3], [puntoPrima ? 1 : 0, 1], { extrapolateRight: 'clamp' })}
          pulsazione={pulsazione}
        />
      )}

      {passo.capitolo && (
        <Capitolo occhiello={passo.capitolo.occhiello} titolo={passo.capitolo.titolo} t={trascorso} durata={passo.durata} />
      )}
      {passo.chiusura && <Chiusura titolo={passo.chiusura.titolo} punti={passo.chiusura.punti} t={trascorso} />}

      {!eCartello(passo) && (
        <Didascalia
          testo={passo.didascalia}
          n={passo.n}
          totale={nScatti}
          t={trascorso}
          durata={passo.durata}
          opacita={t < INTRO ? 0 : 1}
        />
      )}

      {aperturaIntro > 0 && (
        <AbsoluteFill
          style={{
            backgroundColor: '#111C2E',
            opacity: aperturaIntro,
            alignItems: 'center',
            justifyContent: 'center',
            flexDirection: 'column',
            gap: 22,
            fontFamily: 'Inter, system-ui, sans-serif',
          }}
        >
          <div style={{ opacity: molla(t - 0.15), transform: `scale(${0.94 + molla(t - 0.15) * 0.06})` }}>
            <Marchio larghezza={430} />
          </div>
          <div style={{ width: molla(t - 0.4) * 96, height: 3, borderRadius: 2, background: '#2997D4', marginTop: 14, marginBottom: 6 }} />
          <Parole
            testo={manifest.titolo}
            t={t}
            ritardo={0.5}
            passo={0.08}
            salita={26}
            stile={{ color: '#f8fafc', fontSize: 82, fontWeight: 700, letterSpacing: -1.5 }}
          />
          <Parole
            testo={manifest.sottotitolo}
            t={t}
            ritardo={0.85}
            passo={0.03}
            salita={14}
            stile={{ color: '#94a3b8', fontSize: 34 }}
          />
        </AbsoluteFill>
      )}
    </AbsoluteFill>
  );
};

export const formato = { width: LARGHEZZA, height: ALTEZZA, fps: FPS };

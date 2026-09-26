import React from 'react';
import { interpolate } from 'remotion';
import { Marchio } from './Marchio';
import { Parole, molla } from './Testo';

const CARATTERE = 'Inter, system-ui, sans-serif';

/** Cartello di sezione, sopra l'ultimo fotogramma sfocato. */
export const Capitolo: React.FC<{ occhiello: string; titolo: string; t: number; durata: number }> = ({
  occhiello,
  titolo,
  t,
  durata,
}) => {
  const apertura = molla(t - 0.12);
  const uscita = interpolate(t, [durata - 0.4, durata], [1, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });

  return (
    <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center', paddingLeft: 170, gap: 20, opacity: uscita, fontFamily: CARATTERE }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 18 }}>
        {/* Il filo cresce invece di comparire: dà una direzione all'ingresso. */}
        <div style={{ width: apertura * 54, height: 4, borderRadius: 2, background: '#2997D4' }} />
        <Parole
          testo={occhiello}
          t={t}
          ritardo={0.2}
          passo={0.045}
          salita={8}
          stile={{
            color: '#93c5fd',
            fontSize: 26,
            fontWeight: 600,
            // La spaziatura si stringe entrando: il titolo «si mette a fuoco».
            letterSpacing: 4 + (1 - apertura) * 9,
            textTransform: 'uppercase',
          }}
        />
      </div>
      <Parole
        testo={titolo}
        t={t}
        ritardo={0.3}
        passo={0.075}
        salita={30}
        stile={{ color: '#f8fafc', fontSize: 76, fontWeight: 700, letterSpacing: -1.6, maxWidth: 1300 }}
      />
      <div style={{ position: 'absolute', right: 170, bottom: 150, opacity: apertura }}>
        <Marchio larghezza={190} opacita={0.5} />
      </div>
    </div>
  );
};

/** Scheda di chiusura: il riepilogo con cui si esce. */
export const Chiusura: React.FC<{ titolo: string; punti: string[]; t: number }> = ({ titolo, punti, t }) => (
  <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', justifyContent: 'center', paddingLeft: 170, paddingRight: 170, gap: 34, fontFamily: CARATTERE }}>
    <div style={{ marginBottom: 12, opacity: molla(t) }}>
      <Marchio larghezza={270} opacita={0.95} />
    </div>
    <Parole
      testo={titolo}
      t={t}
      ritardo={0.12}
      passo={0.07}
      salita={26}
      stile={{ color: '#f8fafc', fontSize: 62, fontWeight: 700, letterSpacing: -1.2 }}
    />
    <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
      {punti.map((p, i) => {
        const ritardo = 0.55 + i * 0.42;
        const m = molla(t - ritardo);

        return (
          <div key={p} style={{ display: 'flex', gap: 20, alignItems: 'baseline' }}>
            <div style={{ color: '#2997D4', fontSize: 34, fontWeight: 700, opacity: m, transform: `scale(${0.4 + m * 0.6})` }}>·</div>
            <Parole
              testo={p}
              t={t}
              ritardo={ritardo}
              passo={0.028}
              salita={12}
              stile={{ color: '#cbd5e1', fontSize: 36, lineHeight: 1.4, maxWidth: 1420 }}
            />
          </div>
        );
      })}
    </div>
  </div>
);

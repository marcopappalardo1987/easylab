import React from 'react';
import { interpolate } from 'remotion';
import { Marchio } from './Marchio';
import { Parole, molla } from './Testo';

const CARATTERE = 'Inter, system-ui, sans-serif';

export const Didascalia: React.FC<{
  testo: string;
  n: number;
  totale: number;
  t: number;
  durata: number;
  opacita: number;
}> = ({ testo, n, totale, t, durata, opacita }) => {
  // La barra sale entrando e riscende un attimo prima del taglio: senza uscita
  // il testo resta immobile fino allo stacco e la sostituzione sembra un errore.
  const entrata = molla(t);
  const uscita = interpolate(t, [durata - 0.3, durata], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  // ⛔ In uscita la barra SCORRE FUORI, non si dissolve. Dissolvendo, appena il
  // fondo scuro schiarisce il testo bianco resta appeso su una tabella chiara e
  // si legge slavato — e non è una questione di curve: qualunque cosa stia sopra
  // un pannello che sta sparendo diventa illeggibile, perché è il pannello a
  // renderla leggibile. Restano opachi e se ne vanno insieme dal bordo basso.
  const oPannello = opacita * (1 - uscita ** 3);
  const scorrimento = (1 - entrata) * 30 + uscita * 160;
  const pop = molla(t - 0.04);

  return (
    <div
      style={{
        position: 'absolute',
        left: 0,
        right: 0,
        bottom: 0,
        padding: '120px 140px 58px',
        background: 'linear-gradient(to top, rgba(17,28,46,.94) 40%, rgba(17,28,46,0))',
        display: 'flex',
        // ⚠️ In alto, non in basso: una didascalia su due righe è ammessa (il
        // limite misurato è 79 battute per riga), e col fondo il cartellino del
        // numero finiva accanto alla SECONDA riga, scollato dall'inizio della
        // frase. Con una riga sola non cambia nulla.
        alignItems: 'flex-start',
        gap: 28,
        opacity: oPannello,
        transform: `translateY(${scorrimento}px)`,
      }}
    >
      <div
        style={{
          flexShrink: 0,
          width: 46,
          height: 46,
          borderRadius: 12,
          background: '#2997D4',
          color: '#fff',
          fontSize: 22,
          fontWeight: 700,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          fontFamily: CARATTERE,
          transform: `scale(${0.6 + pop * 0.4})`,
          marginTop: 2,
        }}
      >
        {n}
      </div>

      <Parole
        testo={testo}
        t={t}
        ritardo={0.1}
        salita={14}
        stile={{ color: '#f8fafc', fontSize: 38, lineHeight: 1.32, fontWeight: 500, fontFamily: CARATTERE, letterSpacing: -0.3 }}
      />

      <div style={{ marginLeft: 'auto', flexShrink: 0, display: 'flex', alignItems: 'center', gap: 20, paddingTop: 12 }}>
        <div style={{ color: '#64748b', fontSize: 22, fontFamily: CARATTERE }}>
          {n}/{totale}
        </div>
        <div style={{ width: 1, height: 26, background: '#334155' }} />
        <Marchio larghezza={132} opacita={0.8} />
      </div>
    </div>
  );
};

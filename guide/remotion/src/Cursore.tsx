import React from 'react';

/** Il puntatore non è negli scatti: Playwright non lo cattura. Lo disegna Remotion. */
export const Cursore: React.FC<{ x: number; y: number; opacita: number; pulsazione: number }> = ({
  x,
  y,
  opacita,
  pulsazione,
}) => (
  <div style={{ position: 'absolute', left: x, top: y, opacity: opacita, pointerEvents: 'none' }}>
    {pulsazione > 0 && (
      <div
        style={{
          position: 'absolute',
          left: -60 * pulsazione,
          top: -60 * pulsazione,
          width: 120 * pulsazione,
          height: 120 * pulsazione,
          borderRadius: '50%',
          border: `${Math.max(1, 6 * (1 - pulsazione))}px solid rgba(37, 99, 235, 0.9)`,
          opacity: 1 - pulsazione,
        }}
      />
    )}
    <svg width={44} height={44} viewBox="0 0 24 24" style={{ filter: 'drop-shadow(0 3px 6px rgba(0,0,0,.45))' }}>
      <path d="M5 2.5 L5 20 L9.4 15.6 L12.2 21.6 L15 20.3 L12.2 14.4 L18.4 14.4 Z" fill="#fff" stroke="#111827" strokeWidth={1.2} strokeLinejoin="round" />
    </svg>
  </div>
);

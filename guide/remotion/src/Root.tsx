import React from 'react';
import { Composition } from 'remotion';
import { Guida, durataInFotogrammi, formato, type ProprietaGuida } from './Guida';
import type { Manifest } from './tipi';

/**
 * ⛔ **Niente `import` da `out/`**: quella cartella è ignorata da git, quindi su
 * un clone pulito il bundle non compilerebbe finché qualcuno non ha girato una
 * guida — cioè lo studio sarebbe rotto per chiunque non fosse la macchina su cui
 * è nato. Il manifest vero arriva sempre da `--props` (`bin/monta.mjs`); questo
 * serve solo a dare una composizione allo Studio quando si apre a mani vuote.
 */
const SEGNAPOSTO: Manifest = {
  titolo: 'Nessuna guida caricata',
  sottotitolo: 'Lancia ./bin/gira.sh <slug>, poi apri lo Studio',
  larghezza: 1440,
  altezza: 900,
  passi: [
    {
      n: 1,
      file: null,
      didascalia: '',
      durata: 4,
      fuoco: null,
      cursore: null,
      click: false,
      capitolo: { occhiello: 'Studio', titolo: 'Nessun manifest' },
      chiusura: null,
    },
  ],
};

export const Root: React.FC = () => (
  <Composition
    id="Guida"
    component={Guida}
    {...formato}
    durationInFrames={durataInFotogrammi(SEGNAPOSTO)}
    defaultProps={{ slug: '', manifest: SEGNAPOSTO } as ProprietaGuida}
    calculateMetadata={({ props }) => ({ durationInFrames: durataInFotogrammi(props.manifest) })}
  />
);

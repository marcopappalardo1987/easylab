import React from 'react';
import { Composition } from 'remotion';
import { Guida, durataInFotogrammi, formato, type ProprietaGuida } from './Guida';
import { GuidaPiu, durataInFotogrammiPiu, formatoPiu, type ProprietaGuidaPiu } from './GuidaPiu';
import { Vetrina, durataInFotogrammiVetrina, formatoVetrina, type Copione, type ProprietaVetrina } from './Vetrina';
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

/** Copione minimo per aprire lo Studio senza aver girato la vetrina. */
const COPIONE: Copione = {
  titolo: 'Nessuna vetrina caricata',
  sottotitolo: 'Lancia ./bin/gira-vetrina.sh, poi apri lo Studio',
  larghezza: 1440,
  altezza: 900,
  scene: [{ tipo: 'apertura', titolo: 'Easy Lab', sottotitolo: 'Nessun copione', durata: 4 }],
};

export const Root: React.FC = () => (
  <>
  <Composition
    id="Guida"
    component={Guida}
    {...formato}
    durationInFrames={durataInFotogrammi(SEGNAPOSTO)}
    defaultProps={{ slug: '', manifest: SEGNAPOSTO } as ProprietaGuida}
    calculateMetadata={({ props }) => ({ durationInFrames: durataInFotogrammi(props.manifest) })}
  />
  <Composition
    id="GuidaPiu"
    component={GuidaPiu}
    {...formatoPiu}
    durationInFrames={durataInFotogrammiPiu(SEGNAPOSTO)}
    defaultProps={{ slug: '', manifest: SEGNAPOSTO } as ProprietaGuidaPiu}
    calculateMetadata={({ props }) => ({ durationInFrames: durataInFotogrammiPiu(props.manifest) })}
  />
  <Composition
    id="Vetrina"
    component={Vetrina}
    {...formatoVetrina}
    durationInFrames={durataInFotogrammiVetrina(COPIONE)}
    defaultProps={{ slug: '', copione: COPIONE } as ProprietaVetrina}
    calculateMetadata={({ props }) => ({ durationInFrames: durataInFotogrammiVetrina(props.copione) })}
  />
  </>
);

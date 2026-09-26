# Come si fa una guida video di Easy Lab

Riferimento operativo per chi (persona o agente) produce la **prossima** guida.
Le guide sono stilisticamente identiche fra loro: questo documento è il contratto
che lo garantisce. Il pilota di riferimento è `flussi/intervento.spec.ts`.

Chi legge questo file dovrebbe poter produrre una guida nuova **senza rileggere il
codice del montaggio**: se serve farlo, manca qualcosa qui.

---

## 1. Il modello: Playwright cattura, Remotion monta

Playwright **non registra video**. Produce PNG a piena risoluzione più un
`manifest.json` che descrive, per ogni momento: dove va il cursore, su cosa
stringe la camera, che cosa dice la didascalia, quanto dura. Remotion anima
quel manifest.

⚠️ **Non passare al video nativo di Playwright.** Un webm di una pagina che si
muove a scatti è illeggibile, e cambiare una parola significa rigirare tutto il
browser. Col manifest, correggere un testo costa un re-render di ~90 secondi e
nessuna sessione di cattura.

```
./bin/servi.sh &          # app dimostrativa su :8123 — una volta per sessione
./bin/gira.sh <slug>      # azzera il DB, cattura, monta
```

L'mp4 esce in `out/<slug>/<slug>.mp4`. Formato fisso: **1920×1080, 30 fps, h264 + aac**.

---

## 2. Prima di scrivere un copione

1. **L'ambiente esiste già.** `easylab_demo` è popolato e `easylab_demo_seme` è
   il suo template. Se il DB non c'è: `./bin/prepara-demo.sh`, poi
   `./bin/azzera.sh --semina`.
2. **L'utente è Giulia Ferrari** (`giulia.ferrari@aurora.test` / `guida-demo`),
   ruolo *Responsabile Reparto*, tre dipartimenti assegnati. Per i flussi che
   richiedono un altro ruolo, vedi §8.
3. **Guarda la pagina vera prima di scrivere il copione.** I selettori si
   ricavano dalla pagina, non si indovinano. Uno script usa e getta con
   `chromium.launch()` che stampa `allTextContents()` di `button`, `a`, `label`,
   `th` costa un minuto e evita tre girate a vuoto.

---

## 3. Anatomia di un copione

```ts
import { expect, test } from '@playwright/test';
import { Regista } from '../lib/regista';

test('nome del flusso', async ({ page }) => {
  const g = new Regista(page, '<slug>', '<Titolo>', '<sottotitolo>');

  await page.goto('/login');
  g.capitolo('Parte 1 di 3', 'Entrare e orientarsi');

  await g.passo('Frase di una riga.', { su: page.locator('#email'), zoom: 2.4 });

  await g.passo('Un clic e siamo dentro.', {
    su: page.getByRole('button', { name: /accedi/i }),
    click: true,
    zoom: 2.6,
  });

  // …

  g.chiusura('In tre mosse', ['…', '…', '…']);
  g.scrivi();
});
```

### `g.passo(didascalia, opzioni)`

| opzione | effetto |
|---|---|
| `su` | `Locator`: decide l'inquadratura **e** dove va il cursore. Assente = pagina intera, cursore fuori campo. |
| `zoom` | quanto stringere; `1` = pagina intera. Vedi la scala in §4. |
| `click` | il clic scatta **dopo** lo scatto: il video mostra il puntatore che arriva sul bottone, e il passo successivo il risultato. |
| `durata` | secondi. Omessa, la calcola sulla lunghezza del testo (~15 caratteri al secondo, minimo 2,5 s, massimo 9). |

> ⚠️ **Nel copione non si scrive nulla che serva alla sola pagina.** La
> didascalia è per il video: corta, come se chi legge stesse già guardando il
> gesto, perché è così. Tutto ciò che esiste solo nella guida scritta —
> l'apertura della guida, l'apertura di ogni capitolo, l'approfondimento di ogni
> passo — sta in `testi/<slug>.md`, numerato come i passi e i cartelli del
> copione: aggiungendo o togliendo un `g.passo()` o un `g.capitolo()` va
> aggiornato anche quel file, o `TestiScrittiGuardrailTest` diventa rosso.
> Il perché di due testi è in `app/Support/Guide/TestiScritti.php`.

### `g.capitolo(occhiello, titolo)` e `g.chiusura(titolo, punti[])`

Cartelli. Si stampano sopra l'ultimo fotogramma sfocato e **non entrano nella
numerazione** «6/18»: un cartello non è un passo da eseguire.

---

## 4. Le regole di stile

Sono vincolanti. Una guida che se ne discosta non è «una variante»: è fuori serie.

### Ritmo

- **12–20 scatti** per guida, **60–120 secondi** di durata totale.
- **Tre capitoli**, occhiello `Parte N di 3`. Meno di tre, il video non ha
  struttura; più di tre, l'argomento andava spezzato in due guide.
- Un capitolo ogni 4–7 passi.
- Chiusura sempre presente, **esattamente tre punti**.

### Zoom

| valore | quando |
|---|---|
| assente (1) | schermate d'insieme: dashboard, elenco appena aperto, risultato finale |
| 1.4 – 1.8 | una tabella, un blocco di schede, una sezione |
| 2.0 – 2.6 | un campo, un menù a tendina, un bottone in una finestra |
| 3.0 | una voce di menù, una linguetta, un bottone piccolo |

Non superare 3: oltre, i PNG a 2× cominciano a mostrare il pixel.

### Didascalie

- **Una frase per passo, al massimo due righe.** La riga tiene **79 battute** —
  misurate, non stimate: `1277 px` di spazio utile diviso `16,1 px` per battuta
  di testo italiano a 38 px. Il tetto pratico è quindi **155 battute**; oltre, la
  terza riga mangia la schermata e il passo andava spezzato in due.
- Presente indicativo, impersonale: «Si cerca», «Basta digitare», non «Cerchiamo»
  né «Puoi cercare».
- Si dice **che cosa fa la funzione**, non che cosa si sta cliccando. «La ricerca
  lavora su nome, modello e matricola insieme» batte «Clicca nel campo di ricerca».
- Virgolette basse «…» per gli elementi dell'interfaccia.
- Niente punti esclamativi, niente ammiccamenti, niente «semplicemente».
- I tre punti della chiusura sono **fatti che restano in mano**, non un riassunto
  dei clic: «Ogni intervento vuole un assegnatario e una data di scadenza: è la
  data che accende il semaforo».

### Marchio

Ci pensa il montaggio: testa, cartelli, chiusura, barra delle didascalie. Non
aggiungerne altrove. Il componente sceglie da sé fra lockup e lettering (soglia:
200 px di larghezza).

### Musica

Una sola traccia per tutte le guide, `bin/musica.mjs` → `out/audio/tema.wav`.
**Non se ne aggiungono altre**: la riconoscibilità della serie passa anche di lì.
Oggi: 100 BPM, F · C/E · Dm7 · Bb, ottavi camminanti e charleston sui levare,
riverbero corto. Sta a **-35 dB** nel mix, con dissolvenza in apertura e chiusura.

---

## 5. Regole non negoziabili

Ognuna nasce da un difetto vero, trovato girando il pilota. Chi le salta li rifà.

### ⛔ Ogni passo che afferma un esito vuole la sua asserzione

La prima passata del pilota ha prodotto una guida **che mentiva**: didascalia
«l'intervento è in cima allo storico», schermo fermo su «Il campo assegnatario è
obbligatorio». Il montaggio non se ne accorge — impagina qualunque cosa.

```ts
await g.passo('Si salva.', { su: page.getByRole('button', { name: 'Salva' }), click: true });
// La finestra che resta aperta significa validazione fallita.
await expect(page.getByRole('button', { name: 'Salva' })).toBeHidden({ timeout: 10_000 });
```

E la guardia va **provata rompendola**: togli il campo che la fa passare,
verifica che la spec diventi rossa, rimetti. Ripristina da una copia
(`cp file /tmp/… && … && cp /tmp/… file`), **mai** con `git checkout -- <file>`:
se il lavoro non è committato, quel comando non annulla la mutazione, annulla
il lavoro.

### ⛔ Il copione scrive, quindi si azzera prima di girare

`gira.sh` lo fa già. Se lanci `npx playwright test` a mano, il DB conserva quel
che hai creato la volta prima e la giratura successiva mostra due righe
identiche nello storico. È successo alla seconda passata del pilota.

### ⛔ `expect()->toContain()` è variadico

Vale in tutto il progetto (vedi `CLAUDE.md`): un messaggio passato come secondo
argomento diventa un **secondo ago**, e l'asserzione negativa si soddisfa sempre.
La spiegazione va nel nome del test o in un commento.

### ⚠️ Le schermate dell'app sono chiare

Due conseguenze già pagate, entrambe invisibili su un fotogramma fermo:

- **La barra delle didascalie esce scorrendo, non dissolvendo.** È il fondo scuro
  a rendere leggibile il testo bianco: appena schiarisce, quel che ci sta sopra
  si legge slavato. Non è questione di curve — provate lineare, radice e opacità
  separate fra pannello e contenuto, il difetto resta.
- **Sui cartelli il velo sale prima del testo** (0,22 s contro 0,3 s). Con la
  stessa rampa, le prime due parole del titolo cadono su una schermata ancora
  chiara.

Se tocchi `Didascalia.tsx` o `Guida.tsx`, ricontrolla **questi due** ritagli:

```
ffmpeg -ss <fine_passo - 0.15> -i out/<slug>/<slug>.mp4 -frames:v 1 -vf "crop=1920:230:0:850" /tmp/uscita.jpg
ffmpeg -ss <inizio_capitolo + 0.25> -i out/<slug>/<slug>.mp4 -frames:v 1 /tmp/capitolo.jpg
```

### ⚠️ Mai il DB di sviluppo

`easylab` contiene dati di clienti veri. Gli script portano `DB_DATABASE=easylab_demo`
e `CACHE_STORE=array` (Redis è condiviso e la cache dei permessi di spatie salva
gli **id** di ruoli e permessi: un comando con la cache di default contro un altro
DB fa negare tutto a tutti nell'app di sviluppo). Non aggirarli.

---

## 6. Prima di dire «fatto»

- [ ] `./bin/gira.sh <slug>` verde da capo a fondo, partendo dall'azzeramento.
- [ ] Ogni passo che afferma un esito ha la sua asserzione, e almeno una è stata
      **provata rompendo il codice**.
- [ ] Durata fra 60 e 120 s: `ffprobe -show_entries format=duration`.
- [ ] Traccia audio presente: `ffprobe -show_entries stream=codec_type` dà
      `video` **e** `audio`.
- [ ] Livello del mix fra **-33 e -37 dB** medi:
      `ffmpeg -i <mp4> -af volumedetect -f null /dev/null`.
- [ ] Guardati almeno cinque fotogrammi: testa, un capitolo a +0,25 s, un passo
      con lo zoom stretto, un'uscita di didascalia, la chiusura.
- [ ] Nessuna didascalia va a capo (nel dubbio, il fotogramma).
- [ ] `testi/<slug>.md` ha `## premessa`, un `## capitolo N` per ogni cartello e
      un blocco per ogni `g.passo()`, e nessuno in più.
- [ ] Il flusso finisce in uno stato **vero**: se il copione salva qualcosa,
      l'ultimo scatto lo mostra salvato.

---

## 7. Guasti tipici

| sintomo | causa | rimedio |
|---|---|---|
| `elemento senza riquadro` | `su` punta a un elemento non visibile | `scrollIntoViewIfNeeded` lo fa già il Regista: l'elemento non c'è proprio, o è in un'altra linguetta |
| il video mostra un errore di validazione | manca un campo obbligatorio | riempilo, e aggiungi l'asserzione che l'avrebbe colto |
| due righe identiche nell'elenco finale | girato senza azzerare | `./bin/azzera.sh` |
| camera che «salta» | due passi consecutivi con fuochi lontanissimi | metti un passo d'insieme (senza `su`) in mezzo |
| testo del capitolo illeggibile | hai toccato le rampe del velo | §5, quarta regola |
| `selectOption` fallisce con un oggetto | l'API vuole il **valore**, non `{label: /regex/}` | passa il valore dell'enum |

---

## 8. Flussi che richiedono un altro ruolo

`config/rbac.php` impone la 2FA a **Developer, Superadmin e Admin**, quindi per
un po' le guide di amministrazione e di piattaforma non si sono potute girare.
✅ **Sbloccato il 7 Set 2026**, in due pezzi:

1. `bin/pianta-2fa.sh` mette nel **seme** del DB dimostrativo un segreto TOTP
   noto e confermato, sull'Admin `maria.conti@aurora.test`. Va nel seme e non nel
   DB corrente, perché `azzera.sh` gira prima di ogni copione e lo ricreerebbe
   senza.
2. `lib/totp.ts` calcola il codice a sei cifre (RFC 6238, venti righe di crypto
   standard invece di una dipendenza in più); `lib/accesso.ts` lo usa.

```ts
import { accedi } from '../lib/accesso';

await accedi(page, 'maria.conti@aurora.test');   // supera la challenge da sé
```

Chi **filma** il login fa da sé i propri passi e poi chiama `superaIl2FA(page)`.

⚠️ Il codice si calcola **dopo** il submit e non prima: la finestra dura trenta
secondi, e calcolarlo in anticipo dà un copione che fallisce una volta ogni
tanto, cioè la specie peggiore.

Il segreto è in chiaro in `lib/totp.ts` ed è un **dato di scena**: vive solo in
`easylab_demo`, che nessun ambiente vero raggiunge.

# Studio delle guide

Video di istruzioni per l'uso della piattaforma: **Playwright cattura**, **Remotion monta**.

- **[STILE.md](STILE.md)** — come si fa una guida nuova. Da leggere prima di
  scriverne una: regole di ritmo, zoom, didascalie, e le trappole già pagate.
- **[CATALOGO.md](CATALOGO.md)** — tutte le funzionalità e le 46 guide da
  produrre, con stato, rotte e ordine consigliato.

## Perché non il video di Playwright

Playwright sa registrare, ma un webm di una pagina che si muove a scatti è
illeggibile e non si corregge: cambiare una parola vuol dire rigirare tutto il
browser. Qui il browser produce **PNG a piena risoluzione più un manifest**
(dove va il cursore, dove stringe la camera, che cosa dice la didascalia), e
Remotion anima quel manifest. Ritoccare un testo costa un re-render.

## Giro completo

```
./bin/servi.sh &          # app dimostrativa su :8123 (una volta)
./bin/gira.sh intervento  # azzera il DB, cattura, monta
```

L'mp4 esce in `out/<slug>/<slug>.mp4`. Per portarlo dentro l'applicazione:

```
./bin/pubblica.sh         # copia mp4 + manifest in public/guide/
```

e la guida compare in `/guida` **se** il suo slug è in `config/guide.php`.

## La pagina Guida

Voce di sidebar, indice a sinistra raggruppato per argomento, filtro testuale,
video e testo nella stessa pagina. Cliccando un passo scritto il video salta lì.

⚠️ **Video e testo non sono due contenuti**: la guida scritta è generata dallo
stesso `manifest.json` che monta il video, quindi le didascalie *sono* i passi.
Redigere il testo a parte lo farebbe divergere dal video al primo ritocco, e
nessuno se ne accorgerebbe. L'istante d'inizio di ogni passo lo scrive `monta.mjs`
nel manifest, perché è l'unico a sapere quanto dura la testata.

Oggi la pagina è gatata su `can:tenants.view_all`: è un **cancello di rilascio**,
non una regola di sicurezza — cinque guide su quarantasei non sono un manuale.
Quando si apre, si toglie il middleware dalla rotta e il `@can` dalla sidebar;
non serve nessun permesso nuovo.

## Testi e musica

Oltre alla didascalia per passo ci sono due cartelli, dichiarati nel copione:

```ts
g.capitolo('Parte 2 di 3', 'Trovare la macchina giusta');
g.chiusura('In tre mosse', ['…', '…', '…']);
```

Si stampano sopra l'ultimo fotogramma sfocato — la schermata resta lì a dire
dov'eravamo — e non entrano nella numerazione «3/18»: un cartello non è un passo
da eseguire.

I testi entrano **parola per parola** (`Parole` in `remotion/src/Testo.tsx`), con
una molla e un passo di ~60 ms: a blocco intero un testo «appare», sfalsato si
**legge**, perché l'occhio viene portato da sinistra a destra alla velocità con
cui lo leggerebbe da sé. Sopra i 100 ms diventa un'insegna a scorrimento.

Due trappole trovate montando, entrambe di leggibilità e nessuna delle due
visibile su un fotogramma fermo:

- ⛔ **La barra delle didascalie esce SCORRENDO, non dissolvendo.** È il fondo
  scuro a rendere leggibile il testo bianco: appena quello schiarisce, quel che
  ci sta sopra si legge slavato su una tabella chiara. Non è questione di curve
  — provate lineare, radice e opacità separate fra pannello e contenuto: il
  difetto resta. Se ne vanno insieme dal bordo basso, opachi.
- ⚠️ **Sui cartelli il velo sale prima del testo** (0,22 s contro 0,3 s). Con la
  stessa rampa, le prime due parole del titolo cadono su una schermata ancora
  chiara e non si leggono.

## Marchio

Il montaggio copia il logo da `public/brand/` a ogni render: se lì cambia, il
video successivo lo prende. Si usa la variante per **fondo scuro** — quella
normale ha il blu profondo che su `#111C2E` fa 1,9:1 e sparisce (ADR-033/034) —
e il fondo dei cartelli è proprio `#111C2E`, la base scura del Design System.

`Marchio` sceglie da sé fra lockup e lettering: sotto i 200px di larghezza il
payoff «GESTIONE STRUMENTAZIONE E MANUTENZIONE» diventa una riga grigia
illeggibile, quindi passa al compatto. Sta in testa, sui cartelli, in chiusura e
nella barra delle didascalie.

## Musica

La **sintetizza** `bin/musica.mjs` (`out/audio/tema.wav`, anello di 19,2s che
Remotion ripete): 100 BPM, F · C/E · Dm7 · Bb, basso e arpeggio in ottavi,
charleston sui levare, riverbero corto. Sta a -35 dB nel mix, con dissolvenza in
apertura e chiusura. **È una sola per tutte le guide**: la riconoscibilità della
serie passa anche di lì.

Generata invece che scaricata: una traccia di terzi, anche «royalty free», porta
una licenza da rispettare su un video che finisce ai clienti. Il rumore del
charleston esce da un generatore con seme fisso, quindi il file è riproducibile
byte per byte.

⚠️ **Se la si cambia**, tre cose vanno tenute — sono la differenza fra musica e
sintetizzatore, e le prime due versioni le hanno imparate a caro prezzo:

1. il **riverbero**, senza il quale i suoni restano appiccicati all'altoparlante;
2. armoniche che si spengono a **velocità diverse** (è ciò che distingue una
   corda pizzicata da un fischio), con un filo di inarmonicità;
3. un **motivo** che torna, invece di un arpeggio che sale e scende.

E due vincoli meccanici:

- il ripiegamento della coda sull'inizio va fatto **dopo** il riverbero, o
  all'anello successivo l'ultimo accordo si tronca di netto (saldatura misurata:
  208 su 32767, meno del doppio del passo medio fra due campioni);
- **col ritmo il riverbero va accorciato** (1,5s contro i 2,6s della versione
  lenta): la coda lunga impasta gli ottavi.

## Ambiente

## Dove stanno i file (deciso il 6 Set 2026)

**I video restano una copia locale in `public/guide/`, ignorata da git.** Niente
Backblaze per ora: cinque guide sono 64 MB e il servizio le serve come qualunque
altro file statico.

⚠️ **Quando rivedere la decisione**: a catalogo completo (46 guide) sono circa
600 MB, che è troppo per stare su un volume di applicazione e troppo per
rigenerarli a ogni deploy. Il momento di spostarli sullo storage è **prima** che
il manuale si apra ai clienti, non dopo — è lo stesso disco degli allegati, e la
migrazione a manuale pubblicato significa riscrivere URL che qualcuno ha già
messo fra i preferiti.

Chi la sposterà tocca due punti soli: `bin/pubblica.sh` (dove copia) e
`Manuale::componi()` (come costruisce `video` e `copertina`).

⛔ Gli scatti NON si prendono dal DB di sviluppo: contiene dati di clienti veri.
Girano su **`easylab_demo`**, popolato da `bin/prepara-demo.sh` (una volta) e
riportato al seme da `bin/azzera.sh` prima di ogni giratura — il copione scrive,
e senza azzeramento la seconda passata mostra due interventi identici.

`bin/demo-env.sh` porta `CACHE_STORE=array`: Redis è condiviso e la cache dei
permessi di spatie salva gli id di ruoli e permessi (vedi `CLAUDE.md`).

L'utente dimostrativo è un **Responsabile Reparto** (`giulia.ferrari@aurora.test`),
non un Admin: `config/rbac.php` impone la 2FA a Developer/Superadmin/Admin, e un
passaggio di sicurezza in apertura non c'entra col flusso raccontato.

## Scrivere una guida

Una spec in `flussi/`, un `Regista` e una chiamata a `passo()` per ogni momento:

```ts
await g.passo('La ricerca lavora su nome, modello e matricola insieme.', {
  su: page.getByPlaceholder(/Cerca per nome/i),
  zoom: 2.4,
  click: true,
});
```

`su` decide inquadratura e bersaglio del cursore; `click` fa scattare il clic
**dopo** lo scatto, così il video mostra il puntatore che arriva sul bottone e
il passo dopo il risultato.

⚠️ **Ogni passo che afferma un esito vuole la sua asserzione.** La prima
passata di `intervento.spec.ts` ha prodotto una guida che diceva «l'intervento è
in cima allo storico» mentre la finestra era ferma su «Il campo assegnatario è
obbligatorio». Una guida che mente è peggio di nessuna guida.

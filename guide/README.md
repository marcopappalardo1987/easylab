# Studio delle guide

Video di istruzioni per l'uso della piattaforma: **Playwright cattura**, **Remotion monta**.

- **[STILE.md](STILE.md)** — come si fa una guida nuova. Da leggere prima di
  scriverne una: regole di ritmo, zoom, didascalie, e le trappole già pagate.
- **[CATALOGO.md](CATALOGO.md)** — tutte le funzionalità e le 46 guide da
  produrre, con stato, rotte e ordine consigliato.
- **[testi/](testi/)** — la **prosa della guida scritta**, un file per guida:
  l'apertura, i capitoli, l'approfondimento di ogni passo. Non sono le
  didascalie del video, che durano quattro secondi e hanno l'immagine accanto.
  Si cambiano senza rigirare niente — non passano dal manifest — e un deploy li
  porta in pagina.

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
./bin/pubblica.sh          # disco locale (sviluppo)
./bin/pubblica.sh remoto   # bucket di un ambiente Laravel Cloud
```

e la guida compare in `/guida` **se** il suo slug è in `config/guide.php`.

## Dove stanno i file (deciso il 6 Set 2026, rifatto il 7)

**Su un disco, non in `public/`.** La prima versione teneva una copia locale in
`public/guide/`, ignorata da git — e non poteva funzionare: staging e produzione
girano su **Laravel Cloud**, che costruisce l'immagine da git. Ciò che non è
versionato non esiste lì, e 64 MB di mp4 (600 a catalogo completo) in git non ci
vanno: i binari non si comprimono per differenze, e ogni rifacimento di una
guida lascia la sua copia nella storia per sempre.

Ora vive sul disco nominato da `config('guide.disco')`, sotto il prefisso
`guide/`. Il default segue il disco dell'ambiente: `local` in sviluppo, il
bucket attaccato in cloud — dove Laravel Cloud imposta `FILESYSTEM_DISK`
al nome che registra lui. **In cloud non serve configurare niente.**

⚠️ **Per caricare da qui verso un ambiente remoto** servono le quattro
`GUIDE_STAGING_*` / `GUIDE_PRODUZIONE_*` nel proprio `.env`, copiate a mano da
`LARAVEL_CLOUD_DISK_CONFIG` del pannello. Non è pigrizia: il framework registra
i dischi iniettati da Cloud solo se `LARAVEL_CLOUD=1`, e accendere quel flag su
una macchina di sviluppo porta con sé code gestite, logging su socket e
connessione Postgres non poolata.

## Come si servono i byte

Attraverso l'applicazione (`ServeFileGuida`), **non** con una URL pre-firmata
del bucket: ADR-026 ha già deciso questo per i documenti — una URL firmata è di
fatto un bearer token, con l'autorizzazione fuori dal giro e il prodotto legato
al provider. Le guide non contengono dati di nessuno e l'eccezione era tentante;
non si fa, perché varrebbe come precedente e la prossima volta il file sarebbe
un documento.

🔴 Il controller implementa **HTTP Range** (`206 Partial Content`), e non è
zelo di protocollo: cliccando un passo scritto il video salta a quell'istante, e
senza Range il browser non può cercare. Il prezzo è che i byte passano dalla
compute dell'applicazione; se un giorno le guide si aprissero a tutti i clienti,
la strada è una CDN davanti a questa rotta, non l'URL firmata.

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

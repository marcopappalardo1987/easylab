🎨 **Restyling UI — Roadmap Operativa**

*Il piano di esecuzione per allineare l'interfaccia a `../Design/Design System Base.md` e al suo campione
`../Design/design-system.html`, e per darle un **tema chiaro e uno scuro**. È scritta per essere eseguita da
una **flotta di agenti** in sequenza e in parallelo, senza intervento umano fino alla revisione finale: ogni
task dichiara chi lo esegue, con quale modello, quali file può toccare e quando può partire.*

> **Stato (26 Ago 2026).** Documento **operativo**, non normativo: qui si decide *come* e *in che ordine*, non
> *cosa è giusto*. Il contratto visivo resta il Design System — §1–§7, la cui **numerazione non si cambia** —
> più la §8 che questa roadmap gli fa aggiungere (task F0.3). Dove questa roadmap e il DS dicessero cose
> diverse, **vince il DS**.
>
> 🔗 Attua **ADR-033** (il primary è il blu del marchio), che dal 21 Ago 2026 è *accettata ma non attuata*, e
> introduce **ADR-034** (il tema chiaro/scuro, task F0.2).

---

## 0. Premesse e assunzioni

**Legenda.** `[CORE]` indispensabile · `[STRETCH]` slitta per primo · ⚠️ trappola nota · ⛔ divieto ·
🔗 documento collegato · 🛡️ rete di test.

⚠️ **`§4` e `DS §4` non sono la stessa cosa.** Un `§` da solo rimanda a una sezione **di questo documento**;
il riferimento al contratto visivo porta sempre il prefisso — `DS §4` è il semaforo, `§4` è il ciclo di un
task. Sono due numerazioni che convivono e nessuna delle due si può rinumerare: quella del DS è citata dai
docblock del codice, questa dai task qui sotto.

**I numeri di partenza**, misurati il 26 Ago 2026 e non stimati:

| | |
|---|---|
| Viste Blade | **56 file, 6.455 righe** |
| Usi di classi colore del DS | **~1.190, su 50 file** |
| Occorrenze di `dark:` nell'app | **0** (le 44 esistenti sono tutte in `welcome.blade.php`, pagina Laravel di default **non instradata**) |
| Token semantici in `app.css` | **0** — le viste applicano le scale direttamente (`bg-white`, `text-neutral-900`, `border-neutral-200`) |
| Gradini `primary` definiti | **8 su 11**, e sono **teal** |
| Famiglia `--color-chart-*` | **assente**, mentre il DS §2.5 la specifica |
| Token definito e mai usato | `locked-500` |

**Cosa è già deciso e non si rimette in discussione.**

- La palette, i suoi hex e le sue soglie di contrasto: DS §2. Il semaforo è colore **+ forma + etichetta**
  (DS §4, 🔗 ADR-005) e questo non cambia di un grado.
- Il tema scuro **esiste già**: `design-system.html` lo implementa per intero — due strati di token,
  `data-theme` a tre stati, i gradini scuri **scelti per il fondo scuro e non ottenuti invertendo il chiaro**.
  Questa roadmap lo **porta**, non lo progetta.
- Il progetto è su **Tailwind v4 CSS-first**: `tailwind.config.js` non esiste e non va creato (DS §6).

**Cosa questa roadmap decide.**

1. L'introduzione dello **strato semantico** dei token e la riscrittura degli usi (§1).
2. Il meccanismo del tema: **sistema → ultima scelta dell'utente** (§1.3, ADR-034).
3. Chi fa cosa, con quale modello, e come più agenti lavorano insieme senza pestarsi i piedi (§2, §3).

**Assunzione dichiarata.** Il lavoro procede in autonomia fino a F6. Marco interviene **due volte**: per i
prerequisiti di §6 (credenziali di prova) e per la **revisione umana finale**. Ogni proposta che
*cambierebbe il Design System* non viene applicata: si annota e arriva a quella revisione (§4).

---

## 1. L'architettura in una pagina

### 1.1 I due strati

> **Il restyling NON è spargere `dark:` su 56 viste.** Sarebbero ~1.190 varianti da scrivere e da tenere
> allineate a mano, e la prima che si dimentica non dà errore: dà un testo nero su fondo nero. Il campione
> risolve il problema una volta sola, e lo scrive nei propri commenti: *«le SCALE sono identiche nei due temi,
> i token SEMANTICI sono gli unici che cambiano — è il motivo per cui nessun componente contiene un colore
> letterale o una regola dentro un `@media`».*

```css
/* resources/css/app.css — forma di arrivo */

@theme {
    /* ── strato 1: LE SCALE. Identiche nei due temi. 🔗 DS §2, ADR-033 ── */
    --color-primary-50 … --color-primary-950;   /* 11 gradini; 400 e 600 sono gli hex del logo */
    --color-neutral-50 … --color-neutral-950;
    --color-success-* --color-warning-* --color-danger-* --color-obsolete-* --color-locked-*;
    --color-info-500: var(--color-primary-600);  /* alias del brand, non un secondo blu */
    --color-chart-verde|arancione|rosso|obsoleto|brand;   /* DS §2.5 — oggi mancano tutti */

    /* ── strato 2: I SEMANTICI. Alias verso variabili che il tema riscrive. ── */
    --color-canvas: var(--bg);          --color-ink:   var(--ink);
    --color-surface: var(--surface);    --color-ink-2: var(--ink-2);
    --color-surface-sunken: var(--surface-sunken);   --color-ink-3: var(--ink-3);
    --color-border: var(--border);      --color-ink-inverse: var(--ink-inverse);
    --color-border-strong: var(--border-strong);
    --color-brand: var(--brand);        --color-brand-hover: var(--brand-hover);
    --color-brand-ink: var(--brand-ink);            --color-brand-line: var(--brand-line);
    --color-brand-soft: var(--brand-soft);          --color-brand-soft-strong: var(--brand-soft-strong);
    --color-brand-soft-ink: var(--brand-soft-ink);
    --color-ok-dot|ok-soft|ok-soft-ink;             --color-warn-…;  --color-bad-…;
    --color-obs-…;   --color-lock-…;                --color-ring: var(--ring);
    --color-overlay: var(--overlay);

    --radius-sm|md|lg|xl;  --shadow-sm|md;  --font-sans;   /* 🔗 DS §3 */
}

:root { color-scheme: light;  /* i valori CHIARI: --surface, --ink, --brand, --ok-dot … */ }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { color-scheme: dark; /* scuri */ } }
:root[data-theme="dark"] { color-scheme: dark; /* gli stessi scuri */ }
@media print { :root, :root[data-theme="dark"] { /* la stampa è SEMPRE chiara */ } }
```

- ⚠️ **`color-scheme` non è decorazione.** Senza, scrollbar, `<select>`, date picker e campi nativi restano
  chiari sul fondo scuro. È l'unica riga che parla al browser invece che alla pagina.
- ⚠️ **La duplicazione dei valori scuri fra la media query e `[data-theme="dark"]` è voluta**, ed è la stessa
  del campione: la media query porta `:not([data-theme="light"])` perché una scelta esplicita di *chiaro*
  deve poter zittire il sistema operativo. Un solo blocco non può fare entrambe le cose.
- ⚠️ **Un solo blocco `@theme`, piatto, senza graffe annidate.** 🛡️ `PaletteGuardrailTest` estrae con
  `/@theme\s*\{(.*?)\n\}/s`: legge **il primo blocco e fino alla prima `\n}`**. Un secondo `@theme`, o un
  `@media` dentro il blocco, rende la rete cieca **senza dirlo**.
- **Un token che il DS non ha, e perché**: `--overlay` (il velo dietro la modale). Oggi è
  `bg-neutral-900/40`; sul fondo scuro `#0A1220` un velo così è quasi invisibile e la modale smette di
  staccarsi. Va aggiunto in DS §8 con questa motivazione — **non si allarga la palette per un hover**
  (è già successo, e la risposta fu `hover:no-underline`), ma qui manca un ruolo, non un gradino.

### 1.2 La tabella di mappatura — **il contratto che ogni agente applica**

Ricavata dal campione (`design-system.html`, blocchi `.el-*`), non inventata: dove una riga dice
`--surface-sunken` è perché lì il campione scrive `var(--surface-sunken)`.

| Ruolo | Oggi nelle viste | Diventa | Nota |
|---|---|---|---|
| Fondo pagina | `bg-neutral-50` (su `<body>`) | `bg-canvas` | |
| Superficie (card, modale, dropdown, top bar) | `bg-white` | `bg-surface` | |
| Superficie incassata (`<thead>`, hover riga, footer card, campo disabilitato, skeleton, sidebar) | `bg-neutral-50` / `bg-neutral-100` | `bg-surface-sunken` | ⚠️ **`bg-neutral-50` fa due mestieri**: fondo pagina *e* incasso. La scelta è per occorrenza |
| Testo primario e titoli | `text-neutral-900` / `text-neutral-800` | `text-ink` | |
| Testo secondario | `text-neutral-700` / `text-neutral-600` | `text-ink-2` | |
| Testo terziario, placeholder, `.muted` | `text-neutral-500` | `text-ink-3` | |
| Elementi **non** testuali (separatori, icone decorative) | `text-neutral-400` | `text-ink-3` o `border-border` | ⚠️ **103 usi da classificare uno per uno** — DS §2.2: `neutral-400` non può portare testo |
| Bordi e divisori | `border-neutral-200`, `divide-neutral-100/200` | `border-border`, `divide-border` | |
| Contorno dei campi | `border-neutral-300` | `border-border-strong` | |
| Bottone primario | `bg-primary-600 text-white` / `hover:bg-primary-700` | `bg-brand text-brand-ink` / `hover:bg-brand-hover` | |
| Bottone secondario | `bg-white border-neutral-200 text-neutral-800` | `bg-surface border-border-strong text-ink` + `hover:bg-surface-sunken` | ⚠️ il campione usa il bordo **forte** |
| Bottone pericolo | `bg-danger-500 text-white` | `bg-bad-dot text-ink-inverse` | |
| Bottone ghost | `text-neutral-600 hover:bg-neutral-100` | `text-ink-2 hover:bg-surface-sunken hover:text-ink` | |
| Link, tab attivo, freccia di ordinamento | `text-primary-600/700`, `border-primary-600` | `text-brand`, `border-brand` | |
| Superficie brand tenue (nav attiva, riga selezionata, alert info) | `bg-primary-50 text-primary-700` | `bg-brand-soft text-brand-soft-ink` | nav attiva: `bg-brand-soft-strong` |
| Bordo d'accento brand | `border-primary-200` | `border-brand-line` | |
| Anello di fuoco | `ring-primary-600`, `ring-primary-500`, `focus:border-primary-600` | `ring-ring`, `focus:border-brand` | 🔗 DS §5.6 |
| Semaforo 🟢 | `text-success-500` · `bg-success-100` · `text-success-700` | `text-ok-dot` · `bg-ok-soft` · `text-ok-soft-ink` | 🔗 DS §4 |
| Semaforo 🟠 | `text-warning-500` · `bg-warning-100` · `text-warning-800` | `text-warn-dot` · `bg-warn-soft` · `text-warn-soft-ink` | |
| Semaforo 🔴 | `text-danger-500` · `bg-danger-100` · `text-danger-800` | `text-bad-dot` · `bg-bad-soft` · `text-bad-soft-ink` | |
| Obsoleto ⏳ / Lockout 🔒 | `obsolete-500/10`, `locked-500` | `bg-obs-soft text-obs-soft-ink` / `bg-lock-soft text-lock-soft-ink` | 🔗 ADR-014/013 |
| **Testo d'errore di form** | `text-danger-600` | `text-bad-soft-ink` | il campione: `.el-field .err{color:var(--bad-soft-ink)}` |
| Velo della modale | `bg-neutral-900/40` | `bg-overlay` | §1.1 |

⛔ **Eccezioni che restano su token di scala, e vanno lasciate stare.** La rete di F0.5 le esenta *per nome*,
con la ragione scritta accanto:

- **Banner di impersonation** (DS §5.8): il campione scrive `background:var(--warning-500); color:var(--neutral-900)`
  — scale, **non** semantici, di proposito. È un allarme persistente: deve avere lo **stesso identico aspetto**
  nei due temi, o smette di essere lo stesso segnale.
- **Toast**: `bg-neutral-900 text-white` in chiaro, `bg-neutral-700` in scuro. È una delle sole quattro regole
  che nel campione dipendono dal tema fuori dai token.
- **PDF** (`pdf/storico-strumento.blade.php`): dompdf non vede Tailwind. Hex a mano, e **sempre chiaro**
  (🔗 ADR-031).
- **Email** (`mail/*`): i client di posta non hanno un tema affidabile. Restano chiare.

### 1.3 Il meccanismo del tema — sistema prima, scelta dell'utente poi

`data-theme` sull'elemento `<html>`, tre stati: **assente** = segue il sistema operativo · `"light"` ·
`"dark"`. La preferenza vive in `users.tema` (`sistema|chiaro|scuro`, default `sistema`).

| Chi guarda | Chi decide | Come |
|---|---|---|
| Utente **autenticato** | la colonna `users.tema` | il layout rende `data-theme` **dal server**. Zero flash, zero JavaScript nel percorso critico |
| **Ospite** (login, reset, invito, `/bloccato`) | `localStorage['easylab-tema']`, altrimenti l'OS | uno **script inline bloccante** nel `<head>`, prima di ogni foglio di stile |
| Al **cambio** | l'utente | Livewire scrive il DB; Alpine, nello stesso gesto, scrive l'attributo e `localStorage` |

- **Il DB è la verità, `localStorage` è solo la cache che evita il lampo.** È il verso giusto: un utente che
  entra da un dispositivo nuovo ritrova la propria scelta, e un dispositivo condiviso non impone la scelta
  dell'ultimo che l'ha toccato. *(Il costo, dichiarato: uno schermo già aperto altrove non si aggiorna da solo
  — lo fa al login successivo.)*
- ⚠️ **Lo script degli ospiti deve essere inline e sincrono.** Un `defer`, un file esterno o un
  `DOMContentLoaded` producono il **lampo bianco** che il campione stesso ha (il suo toggle gira a fine
  documento): è il difetto da non copiare.
- ⚠️ **Il marchio è un `<img>` e non eredita le variabili CSS** (già scritto in ADR-033): sul fondo scuro
  serve un **secondo file**, non un token. `easylab-logo-compatto-scuro.svg`, con `--el-logo-deep` che *si
  alza* a `primary-200` invece di invertirsi.
- Il selettore vive in `/settings/notifiche`, che diventa **«Preferenze»**, accanto a
  `riceve_email_scadenze`; più una scorciatoia a tre stati nel menù utente della top bar. Il modello da
  imitare — migration dedicata, cast, **non fillable**, Livewire in `app/Livewire/Settings/`, test in
  `tests/Feature/Settings/` — è esattamente quello di `riceve_email_scadenze`.

---

## 2. La flotta

Ogni ruolo è un **agente specializzato vero**, definito in `.claude/agents/` dal task **F0.1**: modello e
ragionamento stanno nel frontmatter, così un task non può partire con il modello sbagliato per distrazione.

| Ruolo | File dell'agente | Modello | Ragionamento | Strumenti | Non può toccare |
|---|---|---|---|---|---|
| **Esecutore fondamenta** — token, tema, guardrail, migration | `restyling-fondamenta.md` | **Opus** | `xhigh` | Read/Edit/Write/Bash | le viste |
| **Esecutore componenti** — `components/**` | `restyling-componenti.md` | **Opus** | `high` | Read/Edit/Write/Bash | `app.css`, i test dei guardrail |
| **Esecutore viste** — viste ordinarie | `restyling-viste.md` | **Sonnet** | `medium` | Read/Edit/Write/Bash | `app.css`, `components/**` |
| **Esecutore viste dense** — `scheda-strumento`, `cabina`, `editor-ruoli` | `restyling-viste.md` (con `model: opus`) | **Opus** | `high` | idem | idem |
| **Esecutore meccanico** — inventari, pulizia, cattura | `restyling-meccanico.md` | **Haiku** | `low` | Read/Bash | qualunque vista |
| 🛡️ **Revisore difetti** — *obbligatorio a ogni task* | `restyling-revisore-difetti.md` | **Opus** | `high` (`xhigh` su F0/F1) | Read/Edit/Bash + skill `/code-review high --fix` | — **corregge**, non segnala |
| 🎨 **Revisore UI/UX** — *obbligatorio a ogni task* | `restyling-revisore-uiux.md` | **Opus** | `high` | Read/Edit/Bash + Playwright MCP | il Design System |
| 📸 **Verificatore visivo** — a fine fase | `restyling-verificatore.md` | **Sonnet** | `medium` | Read/Bash + Playwright MCP | tutto: è in **sola lettura** |
| 🔎 **Ricognizione** — quando serve rileggere il campo | `Explore` (nativo) | Sonnet | `medium` | sola lettura | tutto |

**Perché questa calibrazione e non Opus ovunque.** Il costo di un errore non è uniforme. Un token sbagliato in
`app.css` si propaga a 56 viste e nessun test se ne accorge (è la conseguenza che ADR-033 mette per iscritto):
lì serve il modello migliore al massimo del ragionamento. La riscrittura di `elenco-fornitori.blade.php`
applica una tabella già decisa: lì Opus pagherebbe per una decisione che non deve prendere. **Le due
revisioni restano su Opus sempre**, perché è l'unico punto in cui il lavoro viene messo in dubbio.

---

## 3. Il protocollo di concorrenza

> Il vincolo: gli agenti devono poter lavorare **in parallelo, anche duplicandosi**, senza pestarsi i piedi;
> e quando due devono toccare lo stesso file, il secondo **aspetta il segnale di fine** del primo.

### 3.1 Il manifesto dei write-set

Ogni task dichiara i file che può **scrivere**. Due task sono parallelizzabili **se e solo se** i loro
write-set sono disgiunti. L'orchestratore non lancia mai due task in conflitto: l'attesa è **sua**, non
degli agenti.

Le viste sono partizionate in **corsie disgiunte**. La partizione è stata verificata il 26 Ago 2026:
**56 file assegnati, zero duplicati fra corsie, zero viste rimaste fuori.**

| Corsia | File | Righe | Duplicabile in |
|---|---|---|---|
| **Fondamenta** | `resources/css/app.css`, i tre test guardrail, `tests/Pest.php` | — | 1 agente (serie stretta) |
| **Guscio** | `components/layouts/app.blade.php`, `components/guest-layout.blade.php` | 246 | 1 agente |
| **Componenti** | 11 `components/ui/*` + `app/nav-link` + `piattaforma/nav` + `errori/cifre` + `brand-logo` | 491 | **4 agenti** (un file ciascuno) |
| **Strumenti** | 11 file `livewire/strumenti/*` (esclusa `stampa-qr`) | 1.891 | **3 agenti** |
| **Piattaforma** | 5 file `livewire/piattaforma/*` | 1.951 | **3 agenti** |
| **Resto app** | dashboard, anagrafica, campo, fornitori, ricambi, notifiche, tenancy, 2 settings | 792 | **3 agenti** |
| **Auth** | 7 viste `auth/*` + `bloccato.blade.php` | 382 | **2 agenti** |
| **Superfici non a tema** | `pdf/*`, `mail/*`, `stampa-qr`, `vendor/livewire/tailwind` | 357 | **3 agenti** |

⚠️ **L'unico file conteso fra due corsie diverse è `livewire/strumenti/_form-fields.blade.php`**: lo include
`scheda-strumento` (corsia Strumenti) **e** `anagrafica/albero` (corsia Resto app). È assegnato a
**Strumenti**; «Resto app» non lo tocca e attende il segnale prima di verificare l'albero. È il tipo di
dipendenza che non si vede leggendo l'elenco delle cartelle — per questo è scritta qui.

**File a contesa nota, elencati per nome**: `resources/css/app.css` · `components/layouts/app.blade.php` ·
`tests/Pest.php` · `tests/Feature/PaletteGuardrailTest.php` · `_form-fields.blade.php` ·
`docs/Design/Design System Base.md` · `docs/Architettura/Decisioni Architetturali.md`.

### 3.2 Il lucchetto — `mkdir`, non un file

```bash
# prendere
if mkdir .restyling/lock/<slug> 2>/dev/null; then
    printf '%s\n' "$TASK · $AGENTE" > .restyling/lock/<slug>/proprietario
else
    echo "OCCUPATO da: $(cat .restyling/lock/<slug>/proprietario)"; exit 1   # ⛔ si FERMA
fi
# rilasciare
rm -rf .restyling/lock/<slug>
```

`mkdir` è **atomico** su POSIX e fallisce se la cartella esiste: è un mutex vero. Un `test -f` seguito da
`touch` non lo è — fra i due comandi ci sta un altro agente.

- ⛔ **Chi trova un lucchetto occupato si ferma e riferisce. Non aspetta, non riprova, non dorme.** Un agente
  che dorme è un deadlock che nessuno vede, e `sleep` in primo piano è comunque bloccato dall'harness.
  L'attesa la fa l'orchestratore, che riceve la notifica di fine e *solo allora* lancia il task successivo.
- Il lucchetto **non è il meccanismo**: è la **rete**. Il meccanismo è il manifesto di §3.1. Il lucchetto
  esiste per rendere rumoroso un errore dell'orchestratore, non per sostituirlo.
- `.restyling/` va in `.gitignore`: è stato di esecuzione, non sorgente.

### 3.3 Il segnale di fine

Un task è chiuso quando, **in quest'ordine**: le due revisioni sono passate → il cancello di §4 è verde →
c'è **un commit** → i lucchetti sono rilasciati → `.restyling/fatto/<task>` esiste.

⚠️ **Il commit è parte della definizione di «finito»**, non un adempimento dopo. È la sola forma di fine che
sopravvive a un riavvio, ed è anche l'unico modo di ripristinare senza `git checkout` (⛔ §3.4).

### 3.4 Risorse globali e divieti

**Serializzate con un lucchetto proprio** — sono la fonte di collisione a cui nessuno pensa, perché non sono
file sorgente:

| Risorsa | Lucchetto | Perché |
|---|---|---|
| `npm run build` e `public/build/` | `build` | due build in parallelo si sovrascrivono il manifest, e i test che rendono una pagina falliscono con *«Vite manifest not found»* |
| `php artisan view:clear` | `viste` | svuota la cache **globale**: lanciato mentre un altro agente misura, ne falsa la misura |
| `php artisan migrate` | `db` | una sola migration alla volta sul DB di sviluppo |
| `php artisan test` | *nessuno* | ogni processo ha il proprio database: è parallelizzabile |
| `vendor/bin/pint --dirty` | `pint` | ⚠️ **aggiunto il 27 Ago dopo averlo pagato**: gira sull'**intero repository**, non sul write-set. Ha riformattato file di appoggio di altri agenti |
| la **scratchpad di sessione** | *una sottocartella per agente* | ⚠️ **aggiunto il 27 Ago**: è **condivisa**. Un agente ha sovrascritto i banchi statici di un altro |
| `tests/Feature/` | *vietato ai file di appoggio* | 🔴 **aggiunto il 27 Ago**: due agenti hanno lasciato lì dei file temporanei, che la suite **carica** — e che andavano in fatal, rendendo la suite rossa a chiunque la lanciasse |

**Divieti, ereditati da `CLAUDE.md` e ripetuti qui perché è qui che gli agenti leggeranno:**

- ⛔ **Mai** `php artisan test` o `vendor/bin/pest` con `DB_CONNECTION`/`DB_DATABASE` verso **`easylab`**:
  `RefreshDatabase` fa `migrate:fresh` e **cancella il database di lavoro**. È già successo.
- ⛔ Ogni comando manuale verso `easylab_test` porta anche **`CACHE_STORE=array`**: Redis è condiviso e la
  cache dei permessi di spatie salva gli **id**. Rimedio: `php artisan permission:cache-reset`.
- ⛔ **Mai `git checkout -- <file>` per annullare una mutazione**: se il lavoro non è ancora committato quel
  comando non annulla la mutazione, **annulla il blocco**. Si ripristina da una copia
  (`cp file /tmp/… && … && cp /tmp/… file`), e si committa ogni blocco appena chiude.
- ⚠️ Dopo una mutazione su un file **Blade** serve `php artisan view:clear`, o la misura successiva è falsa.
- ⛔ `expect()->toContain()` è **variadico**: un testo passato come secondo argomento diventa un secondo ago,
  e l'asserzione negativa diventa **sempre verde**. La spiegazione va nel nome del test o in un commento.
- ⚠️ Ogni push su `staging` **deploya**, e la CI gira *dopo*: suite verde **in locale** prima di pushare.

---

## 4. Il ciclo di un task

Vale per **tutti** i task di §5, senza eccezioni. Si descrive una volta e non si ripete.

```
① Esecutore  →  ② Revisore difetti  →  ③ Revisore UI/UX  →  ④ Cancello  →  ⑤ Commit
                    (corregge)           (propone)
```

**① Esecutore.** Prende i lucchetti del proprio write-set, applica la tabella di §1.2, scrive il *perché* di
ogni scelta non ovvia nel commento — è la convenzione del progetto, non un vezzo.

**② Revisore difetti** *(Opus, `high`; `xhigh` su F0 e F1)*. Un agente **diverso** da chi ha scritto. Invoca
`/code-review high --fix` e in più cerca, per nome, i guasti tipici di *questo* lavoro:

- un token usato e non definito, o definito solo in uno dei due temi;
- una superficie rimasta su una scala (`bg-white`, `text-neutral-*`) — invisibile in tema scuro;
- un `text-neutral-400` che porta **testo** (sotto AA, DS §2.2);
- un semaforo che ha perso forma o etichetta e resta solo colore (DS §4, ADR-005) — **regressione grave**;
- un `<td>` che ha perso `data-etichetta`, o una cella con più di un figlio diretto (DS §7, `tabella-a-card`);
- un touch target sceso sotto 44px (DS §5.1);
- un permesso, uno scope o un `@can` toccati per sbaglio: ⛔ **il restyling non cambia una sola regola di
  autorizzazione o di tenancy.** Se un file lo fa, è un difetto, non un miglioramento.

**③ Revisore UI/UX** *(Opus, `high`, con Playwright)*. Guarda la pagina **vera**, nei **due temi**, a **360px
e 1280px**. Propone. E la regola che rende il suo giudizio decidibile senza Marco:

> **Una migliorìa che il Design System già prevede si applica. Una che cambierebbe il Design System si
> annota** in `../Design/Migliorie proposte (revisione umana).md` e arriva alla revisione finale.

⚠️ Il DS è **normativo** e la sua numerazione è citata dai docblock del codice: un agente non lo emenda di
propria iniziativa. Ma un componente che il DS descrive e la vista non usa — un empty state al posto di una
tabella vuota, uno skeleton al posto di uno spinner a tutta pagina (DS §5.9) — **si mette**, perché è
adozione, non modifica.

**④ Il cancello.** Tutto verde, o il task non è finito:

- [ ] `php artisan test` (suite intera, senza variabili d'ambiente)
- [ ] `vendor/bin/pint --dirty`
- [ ] `npm run build` (sotto il lucchetto `build`)
- [ ] **Prova di mutazione** sul guardrail toccato: si rompe apposta, si verifica che il test **giusto**
      diventi rosso, si ripristina **da copia**. ⚠️ Verificare che la mutazione sia stata *davvero* applicata:
      è già capitato di «verificare» con sostituzioni che non sostituivano nulla.
- [ ] Screenshot Playwright della pagina toccata: **2 temi × 2 viewport**, in `.restyling/scatti/<task>/`

**⑤ Commit.** Uno per task, Conventional Commits con scope `restyling`
(es. `feat(restyling): lo strato semantico dei token, e il teal esce dal progetto`).

---

## 5. I task

Ogni task porta: **obiettivo · esecutore (modello/ragionamento) · write-set · dipendenze · Definition of Done**.
Le due revisioni e il cancello di §4 sono **impliciti in ogni task** e non si ripetono.

---

### 🧱 F0 — Fondamenta *(serie stretta: tutte le altre corsie sono ferme)*

- [x] `[CORE]` **F0.1 — La flotta esiste davvero.** Sette definizioni in `.claude/agents/` col modello e il
      ragionamento nel frontmatter, più `.restyling/` in `.gitignore` e lo scheletro `lock/ fatto/ scatti/`.
      *Esecutore: fondamenta (Opus, `xhigh`).* · **write-set:** `.claude/agents/**`, `.gitignore` ·
      **dipendenze:** nessuna.
      **DoD:** i sette agenti si elencano; un agente lanciato a vuoto riporta il proprio modello.
      *Perché per primo: senza, «agente specializzato» resta una convenzione di prompt, e il modello sbagliato
      si sceglie per distrazione invece che per decisione.*
      ✅ *(26 Ago 2026 — sette agenti in `.claude/agents/`. ⚠️ **La DoD non è stata soddisfatta come scritta**: le definizioni si caricano all'**avvio** della sessione, quindi il primo giro è stato orchestrato forzando il modello a mano. Dal giro successivo sono registrate. Chi rilegge non ha trovato un bug: la riga era ottimistica)*

- [x] `[CORE]` **F0.2 — ADR-034: il tema chiaro/scuro.** La decisione di §1.3 messa per iscritto dove il
      progetto tiene le decisioni: i tre stati, perché il DB è la verità e `localStorage` la cache, perché lo
      script degli ospiti è inline e sincrono, perché serve `color-scheme`, e la conseguenza scomoda —
      **la superficie di verifica raddoppia**, ogni pagina va guardata due volte.
      *Esecutore: fondamenta (Opus, `xhigh`).* · **write-set:** `../Architettura/Decisioni Architetturali.md` ·
      **dipendenze:** nessuna.
      **DoD:** ADR-034 presente, numerata dopo la 033, con Contesto/Decisione/Conseguenze.
      ✅ *(26 Ago 2026 — ADR-034 scritta, con la conseguenza che la superficie di verifica **raddoppia**)*

- [x] `[CORE]` **F0.3 — Design System §8, «Tema scuro e strato semantico».** La tabella completa dei token
      semantici coi due valori affiancati (chiaro | scuro), la regola *«le scale non cambiano, i semantici
      sì»*, la mappatura di §1.2, il token `--overlay` con la sua motivazione, e le quattro eccezioni che
      restano su token di scala.
      *Esecutore: fondamenta (Opus, `xhigh`).* · **write-set:** `../Design/Design System Base.md` ·
      **dipendenze:** F0.2.
      ⛔ **§1–§7 non si toccano** e la numerazione non si cambia: `Strumento.php`, `x-ui.semaforo`, il PDF
      dello storico e i test citano le sezioni **per numero**. Si aggiunge §8 in coda e si aggiorna il solo
      riquadro di **Stato** in testa.
      **DoD:** §8 esiste; i titoli §1–§7 sono **byte per byte** quelli di prima (verificato con `diff`).
      ✅ *(26 Ago 2026 — §8 in coda, e §1–§7 verificate **byte per byte** identiche con `diff`. Il revisore ha aggiunto `--surface-code` e aveva ragione: l'app ha superfici monospazio (stack trace, matrice ruoli))*

- [x] `[CORE]` **F0.4 — `app.css`: ADR-033 attuata, e nasce lo strato semantico.** 🔗 DS §2, §6, §8.
      *Esecutore: fondamenta (Opus, `xhigh`).* · **write-set:** `resources/css/app.css` · **dipendenze:** F0.3.
      Contenuto: il teal esce e la scala `primary` diventa i **11 gradini del blu** (400 e 600 ancorati agli
      hex del logo) · `neutral-950`, `success-50`, `warning-600`, `danger-50`, `obsolete-50/100/700`,
      `locked-100/700` che oggi mancano · `info-500` **alias** di `primary-600` · la famiglia
      `--color-chart-*` (assente) · lo strato semantico e i tre blocchi di tema · `--radius-*` e `--shadow-*`
      (DS §3) · `@media print` che forza il chiaro.
      ⚠️ **`@utility tabella-a-card` ha `background-color: white` scritto a mano** e un bordo su
      `--color-neutral-200`: in tema scuro produrrebbe schede bianche su fondo scuro. Va tokenizzata qui.
      ⚠️ Un solo `@theme`, piatto (§1.1).
      **DoD:** `npm run build` passa; `PaletteGuardrailTest` verde; un confronto a schermo fra il campione e
      l'app mostra lo **stesso blu**.
      ✅ *(26 Ago 2026 — il teal è uscito. ⚠️ **L'architettura è stata misurata, non dedotta**: Tailwind v4 fa tree-shaking dei token `@theme` non usati, e lo strato semantico vive fuori da `@theme` — se il compilatore non seguisse i `var()`, `--ink: var(--color-neutral-900)` si risolverebbe nel nulla. Costruito il bundle e letto: segue. Verificato a schermo che `/login` renda `#06589C`, lo stesso blu del logo)*

- [x] `[CORE]` **F0.5 — Le tre reti, prima di migrare e non dopo.** 🛡️
      *Esecutore: fondamenta (Opus, `xhigh`).* · **write-set:** `tests/Feature/PaletteGuardrailTest.php`,
      `tests/Feature/TemaScuroGuardrailTest.php` *(nuovo)*,
      `tests/Feature/SuperficiTokenizzateGuardrailTest.php` *(nuovo)*, `tests/Pest.php` · **dipendenze:** F0.4.
      ✅ *(26 Ago 2026 — tre reti, 17 test nuovi, **sei** prove di mutazione. `DA_MIGRARE` parte da **51 file**. 🔴 Il difetto sospettato nel blocco `@media print` **esisteva, ed erano nove token**: stampando col tema scuro attivo, `--chart-band: #0e2740` disegnava una fascia quasi nera attraverso il foglio. La quarta asserzione su `DA_MIGRARE` — «non tenere un file già pulito» — fa **accorciare la lista da sola**)*

      1. **`PaletteGuardrailTest` esteso ai token senza gradino.** Oggi deriva le tonalità da
         `--color-([a-z]+)-(\d{2,3})`: `--color-surface` **non ha un numero**, quindi non entra né fra le
         definite né fra le usate. ⚠️ **La rete diventerebbe cieca proprio mentre le viste migrano**, e in
         silenzio. Un `bg-surface` usato e non definito deve diventare rosso come oggi lo diventa
         `bg-danger-700`.
      2. **`TemaScuroGuardrailTest` (nuovo).** Ogni token semantico dichiarato ha un valore in **entrambi** i
         temi. ⚠️ Un token definito solo nel chiaro non dà errore: **resta chiaro sul fondo scuro**. E il
         blocco `[data-theme="dark"]` e quello dentro la media query devono dichiarare lo **stesso insieme**
         di nomi — due copie che divergono sono il difetto che questo progetto ha già pagato tre volte.
      3. 🔴 **`SuperficiTokenizzateGuardrailTest` (nuovo) — la rete più importante del lavoro.** Nessuna vista
         usa più una tonalità di **scala** per una superficie o per il testo (`bg-white`, `text-neutral-*`,
         `border-neutral-*`, `bg-primary-*`). È ciò che impedisce che una vista scritta fra sei mesi torni
         invisibile in tema scuro — la stessa forma di guasto di tutte le altre di questo progetto: nessun
         errore, pagina 200, colore sbagliato.

      ⚠️ **La terza rete nasce rossa su 50 file**, quindi porta una lista `DA_MIGRARE` con dentro i file non
      ancora convertiti. **Ogni task di F2–F5 toglie i propri file dalla lista**: da quel momento quel file
      **non può più regredire**. Due asserzioni la tengono onesta: la lista non contiene file inesistenti (o
      una rimozione la renderebbe muta) e **non è vuota finché F6 non lo dichiara**.
      *Il valore della lista è che rende il progresso una quantità misurabile invece di una sensazione.*
      **DoD:** i tre test verdi; **prova di mutazione** su ciascuno (§4 ④), col ripristino da copia.

---

### 🔀 F1 — L'interruttore del tema

- [x] `[CORE]` **F1.1 — `users.tema`.** Migration (`sistema|chiaro|scuro`, default `sistema`), cast sul
      modello, **non fillable** — stessa postura di `tenant_id` e `riceve_email_scadenze`.
      *Esecutore: fondamenta (Opus, `high`).* · **write-set:** `database/migrations/*_add_tema_to_users_table.php`,
      `app/Models/User.php` · **dipendenze:** F0.5.
      ⚠️ **La migration va applicata anche al DB di sviluppo** (`php artisan migrate`, lucchetto `db`): i test
      girano su un database ricreato da zero, quindi una migration mancante su Postgres **non emerge dalla
      suite**. È già successo due volte in questo progetto.
      **DoD:** migrata in locale; un valore fuori dai tre è rifiutato.

- [x] `[CORE]` **F1.2 — `data-theme` in testa alle pagine.** Nei due layout: attributo reso **dal server** per
      l'autenticato, **script inline sincrono** nel `<head>` per l'ospite, `color-scheme` dichiarato.
      *Esecutore: fondamenta (Opus, `xhigh`).* · **write-set:** `resources/views/components/layouts/app.blade.php`,
      `resources/views/components/guest-layout.blade.php` · **dipendenze:** F1.1.
      ⚠️ **Contesa con F3**: sono gli stessi due file. F1.2 tocca **solo `<html>` e `<head>`**, F3 il corpo. I
      due task non sono mai contemporanei.
      **DoD:** con `users.tema = 'scuro'` la pagina nasce scura **senza lampo**; con `sistema` segue l'OS;
      cambiando l'OS a pagina aperta il tema cambia da solo.

- [x] `[CORE]` **F1.3 — Il selettore, e la pagina diventa «Preferenze».** `x-ui.selettore-tema` a tre stati
      (☀ chiaro · ☾ scuro · ⌂ sistema) con `aria-pressed`; Livewire scrive il DB, Alpine scrive attributo e
      `localStorage` nello stesso gesto; scorciatoia nel menù utente.
      *Esecutore: componenti (Opus, `high`).* · **write-set:** `resources/views/components/ui/selettore-tema.blade.php`
      *(nuovo)*, `app/Livewire/Settings/PreferenzeNotifiche.php`,
      `resources/views/livewire/settings/preferenze-notifiche.blade.php`, `routes/web.php`, `resources/js/app.js` ·
      **dipendenze:** F1.2.
      ⚠️ **Contesa con F4-C3**, che possiede la stessa vista di preferenze: F1.3 la tocca per primo, C3 attende
      il segnale.
      ⚠️ La regola già imparata dal combobox vale anche qui: **lo stato Alpine può solo nascondere ciò che il
      server ha deciso di mostrare, mai il contrario** — dopo un morph di Livewire `x-data` si reinizializza.
      **DoD:** tre stati raggiungibili da tastiera, target ≥ 44px, la scelta sopravvive al logout/login **e**
      a un browser nuovo.

- [x] `[CORE]` **F1.4 — Il marchio sul fondo scuro.** Secondo SVG (`easylab-logo-compatto-scuro.svg`, e il
      completo per l'accesso), `x-brand-logo` che sceglie in base al tema.
      *Esecutore: componenti (Opus, `high`).* · **write-set:** `public/brand/*`,
      `resources/views/components/brand-logo.blade.php`, `tests/Feature/MarchioTest.php` · **dipendenze:** F1.2.
      ⚠️ **Un `<img>` non eredita le variabili CSS** (ADR-033): servono due file, e sul fondo scuro il blu
      profondo **si alza** a `primary-200`, non si inverte. ⚠️ Un `<img src>` verso un file assente **non
      rompe niente**: la pagina risponde 200 e mostra un rettangolo vuoto — è lo stato in cui il progetto è
      stato per due mesi. `MarchioTest` va esteso al nuovo file.
      **DoD:** il marchio è leggibile nei due temi; i percorsi esistono, verificato dal test.

- [x] `[CORE]` **F1.5 — Il banco di prova.** Rotta `/design-system` **abilitata solo in `local`** che monta
      ogni componente in ogni stato — è la traduzione in Blade di `design-system.html`.
      *Esecutore: componenti (Opus, `high`).* · **write-set:** `routes/web.php`,
      `resources/views/banco/*` *(nuovo)*, `tests/Feature/BancoTest.php` *(nuovo)* · **dipendenze:** F1.3.
      *Perché esiste: senza, la verifica visiva richiede di autenticarsi e di avere dati di dominio, e diventa
      **non deterministica**. Il banco si guarda a colpo d'occhio, nei due temi, senza toccare un dato.*
      **DoD:** in `production` la rotta **non esiste** (404), e un test lo verifica.

---

### 🧩 F2 — La libreria dei componenti *(4 agenti in parallelo, barriera a fine fase)*

Ogni sotto-task possiede file distinti: sono lanciabili insieme. **Nessuno può toccare `app.css`.**

- [x] `[CORE]` **F2.1 — Superfici e azioni:** `card`, `button`, `modal`, `badge`. 🔗 DS §5.1, §5.2, §5.4.
      *Esecutore: componenti (Opus, `high`).*
      ⚠️ Il bottone **secondario** passa a `border-border-strong` (il campione usa il bordo forte) e il velo
      della modale a `bg-overlay`. `x-ui.badge` ha sei varianti: tutte e sei nei due temi.
- [x] `[CORE]` **F2.2 — Form:** `input`, `textarea`, `combobox`. 🔗 DS §5.6.
      *Esecutore: componenti (Opus, `high`).*
      ⚠️ Il **placeholder va a `text-ink-3`** (a `neutral-400` era 2.56:1, sotto AA — DS §2.2). Il testo
      d'errore va a `text-bad-soft-ink`, non a `text-danger-600`. ⛔ **Il combobox non si riscrive**: i suoi
      `aria-*`, il percorso di selezione via `wire:click` e il fallback senza JavaScript restano identici —
      qui si cambiano solo i colori.
- [x] `[CORE]` **F2.3 — Stato:** `semaforo`, `semaforo-forzato`, `obsoleto`, `stat-tile`. 🔗 DS §4, §5.2,
      ADR-005/014. *Esecutore: componenti (Opus, `high`).*
      ⛔ **La tripletta colore + forma + etichetta non si tocca.** I glifi `●◐■⚑⏳` e gli `sr-only` restano;
      cambia il gradino, non il significato. ⚠️ In tema scuro i colori dei grafici scendono a **ΔE 6,9** fra
      verde e arancione (DS §2.5): è **legittimo solo perché** ogni voce porta anche glifo ed etichetta.
      Toglierli qui romperebbe l'accessibilità, non l'estetica.
- [x] `[CORE]` **F2.4 — Navigazione e cifre:** `app/nav-link`, `piattaforma/nav`, `errori/cifre`.
      🔗 DS §5.5, §5.7. *Esecutore: componenti (Sonnet, `medium`).*
      ⚠️ La voce attiva passa a `bg-brand-soft-strong text-brand-soft-ink`, l'hover a `bg-surface` — perché
      nel campione la **sidebar è incassata** e l'hover deve *emergere*, non affondare.

---

### 🏗️ F3 — Il guscio *(serie: è il file più conteso del progetto)*

- [x] `[CORE]` **F3.1 — `layouts/app.blade.php`** (231 righe): sidebar, top bar, drawer mobile, backdrop,
      banner di impersonation, menù utente. 🔗 DS §5.7, §5.8.
      *Esecutore: viste dense (Opus, `high`).* · **dipendenze:** F2 chiusa per intero.
      ⚠️ **La sidebar cambia ruolo**: da `bg-white` a `bg-surface-sunken`, perché nel campione la superficie
      piena è il **contenuto** e la navigazione è il fondo. È una differenza visibile e voluta, non una svista.
      ⛔ **Il banner di impersonation resta su `warning-500` + `neutral-900`**, cioè su token di **scala**:
      è un allarme persistente e deve avere lo stesso identico aspetto nei due temi. E continua a dire
      **entrambi i nomi** (DS §5.8) e a portare `print:hidden`.
      ⚠️ Le regole di stampa (`print:hidden`, `print:bg-white`) restano nel layout e non nelle pagine.
      **DoD:** drawer, backdrop e menù utente funzionano nei due temi a 360px; la stampa esce **chiara**.

- [x] `[CORE]` **F3.2 — `guest-layout` e le card di autenticazione.** *Esecutore: viste (Sonnet, `medium`).*
      · **dipendenze:** F3.1.
      ⚠️ Il logo **completo** (col claim) resta sulla pagina d'accesso, e l'`h1` in `sr-only` non si tocca:
      il claim è testo trasformato in tracciati e uno screen reader non lo vede (ADR-033).

---

### 🎨 F4 — Le viste *(quattro corsie disgiunte, fino a 8 agenti insieme)*

**Dipendenza comune:** F3 chiusa. **Ogni sotto-task toglie i propri file da `DA_MIGRARE` (F0.5).**

**Corsia A — Strumenti** *(11 file, 1.891 righe)*
- [x] `[CORE]` **F4.A1** — `elenco-strumenti`, `modelli-strumenti`, `import-strumenti`, `_tabs`.
      *(Sonnet, `medium`)* — ⚠️ `elenco-strumenti` ha l'intestazione ordinabile: `aria-sort` e la freccia
      leggono **l'ordinamento effettivo**, mai la property (DS §5.3). Non toccare quella logica.
- [x] `[CORE]` **F4.A2** — `scheda-strumento` (675 righe) e `_panoramica` (319). *(Opus, `high`)* —
      ⚠️ Il tab Panoramica **spiega il semaforo** (ADR-024): ogni riga di spiegazione conserva glifo e
      parola. I tab nascosti per permesso restano nascosti per permesso.
- [x] `[CORE]` **F4.A3** — `_interventi`, `_ricambi`, `_garanzie`, `_documenti`, `_form-fields`. *(Opus, `high`)* —
      ⚠️ Le quattro tabelle usano `tabella-a-card`: ogni `<td>` conserva `data-etichetta` **uguale al proprio
      `<th>`** (🛡️ `TabellaCardMobileTest`), la cella azioni conserva `data-azioni`, e una cella su due righe
      resta **un solo figlio diretto**. ⚠️ `_form-fields` è il file conteso con la corsia C.

**Corsia B — Piattaforma** *(5 file, 1.951 righe)*
- [x] `[CORE]` **F4.B1** — `cabina.blade.php` (603). *(Opus, `high`)* — ⚠️ contiene il debito dichiarato «non
      applicata `tabella-a-card`»: il commento che lo dichiara **resta**, o il debito diventa invisibile.
- [x] `[CORE]` **F4.B2** — `editor-ruoli.blade.php` (621). *(Opus, `high`)* — ⚠️ la matrice ha un'intestazione
      che era già uscita dallo schermo una volta; il marcatore «personalizzato» coi due valori affiancati e la
      striscia dei permessi orfani devono restare leggibili nei due temi. ⛔ Nessuna riga di logica sui
      permessi.
- [x] `[CORE]` **F4.B3** — `registro-audit`, `errori`, `scheda-errore`. *(Sonnet, `medium`)* —
      ⚠️ `scheda-errore` mostra dati oscurati a 180 giorni: la dicitura «cosa non mostro» non si accorcia.

**Corsia C — Resto dell'app** *(9 file, 792 righe)*
- [x] `[CORE]` **F4.C1** — `dashboard/home`, `campo/home`, `notifiche/campanella`, `tenancy/switcher-ente`.
      *(Sonnet, `medium`)* — ⚠️ La dashboard passa `x-ui.semaforo` **nello slot** `label` di `x-ui.stat-tile`:
      la parola non va anche come prop, o comparirebbe due volte (DS §5.2). I quattro numeri restano quattro
      link all'elenco filtrato.
- [x] `[CORE]` **F4.C2** — `anagrafica/albero` (216). *(Sonnet, `medium`)* · **attende il segnale di F4.A3**
      (include `_form-fields`). — 🔗 DS §5.7: nodo attivo `bg-brand-soft`, drawer su mobile.
- [x] `[CORE]` **F4.C3** — `fornitori/elenco-fornitori`, `ricambi/ricerca-ricambi`,
      `settings/preferenze-notifiche`, `settings/two-factor-authentication`. *(Sonnet, `medium`)* ·
      **attende il segnale di F1.3**.

**Corsia D — Autenticazione** *(8 file, 382 righe)*
- [x] `[CORE]` **F4.D1** — `login`, `two-factor-challenge`, `imposta-password-invito`. *(Sonnet, `medium`)* —
      ⚠️ `login` ripete a mano le classi degli input invece di usare `x-ui.input`: **la duplicazione si toglie
      qui**, o il restyling la consolida.
- [x] `[CORE]` **F4.D2** — `forgot-password`, `reset-password`, `confirm-password`, `verify-email`,
      `bloccato`. *(Sonnet, `medium`)* — ⚠️ `bloccato` è la pagina che vede chi non può fare altro: il motivo
      del lockout e la via d'uscita restano i due elementi più leggibili della pagina (ADR-013).

---

### 🖨️ F5 — Le superfici che non hanno un tema *(3 in parallelo)*

- [x] `[CORE]` **F5.1 — Il PDF dello storico.** 12 hex scritti a mano, allineati alla palette del blu.
      🔗 ADR-031. *(Sonnet, `medium`)* — ⛔ **Resta chiaro sempre**: dompdf non vede Tailwind né il tema. ⚠️ E
      resta **senza `primary`**: oggi sono neutri più `#15803d` e `#b91c1c`, e va bene così.
- [x] `[CORE]` **F5.2 — Le email.** `digest-scadenze`, `invito-utente`. *(Sonnet, `medium`)* — ⛔ **Restano
      chiare**: nessun client di posta ha un tema affidabile. Si allinea il solo colore del marchio.
      ⚠️ Il digest è la prima cosa che molti utenti vedono del prodotto: gli stati semaforo vi arrivano come
      **testo**, e devono restare comprensibili senza colore.
- [x] `[CORE]` **F5.3 — Stampa e paginazione.** `stampa-qr` (l'unica vista con `@push('styles')` e regole
      `@page`) e `vendor/livewire/tailwind`. *(Sonnet, `medium`)* — ⚠️ L'etichetta QR si stampa: va
      **verificata su carta**, cioè in anteprima di stampa **con il tema scuro attivo**, che è il caso in cui
      un `@media print` mancante si vede.

---

### 🧹 F6 — Chiusura *(serie)*

- [x] `[CORE]` **F6.1 — `welcome.blade.php` esce dal progetto.** *(Haiku, `low`)* — È la pagina di benvenuto
      di Laravel, **non instradata**, e da sola concentra il 100% degli hex inline, il 100% delle classi
      `gray-*`/`blue-*` e il 100% dei `dark:` del repository.
      ⚠️ **I docblock di `PaletteGuardrailTest` e `SorgentiTailwindGuardrailTest` la citano per nome** come
      esempio del «foglio di stile dentro una vista»: vanno riscritti, o due reti si mettono a spiegare un
      file che non esiste. **DoD:** nessun riferimento residuo; suite verde.
- [x] `[CORE]` **F6.2 — I due residui misurabili.** *(Sonnet, `medium`)* — I **103 `text-neutral-400`**
      classificati uno per uno (decorativo → resta non testuale; testo → `text-ink-3`), e `locked-500`,
      **definito e mai usato**: o lo si usa dove il DS §2.4 dice (lockout 🔒), o esce.
- [x] `[CORE]` **F6.3 — L'audit visivo e di contrasto.** *(verificatore, Sonnet + Playwright, poi giudizio
      Opus)* — Tutte le rotte × **2 temi** × **3 viewport** (360, 768, 1280). Testo ≥ **4.5:1**, elementi non
      testuali ≥ **3:1** (DS §2.5, §7). ⚠️ **Il pallino arancione su bianco fa 2.15:1 e non può fare di
      meglio**: non è un difetto da correggere alzando il gradino — è l'aritmetica che rende §4 obbligatorio.
      **DoD:** `DA_MIGRARE` **vuota** salvo le quattro eccezioni motivate; la terza rete verde senza esenzioni.
- [x] `[CORE]` **F6.4 — La documentazione smette di dire il falso.** *(Opus, `high`)* — ADR-033 passa a
      **«Attuata»** con la data; il riquadro di **Stato** in testa al DS perde l'avvertenza sullo scarto; il
      docblock di `app.css` smette di dire «il primary è ancora il TEAL»; la roadmap master registra
      l'intervento.
      ⚠️ **Non è cosmesi.** Finché quei tre punti dicono «non attuata», il prossimo lettore crede allo scarto e
      lo «ripristina» — cioè rimette il teal credendo di correggere un bug. Chiudere la documentazione **fa
      parte** del lavoro.
- [x] `[CORE]` **F6.5 — Il dossier per la revisione umana.** *(Haiku, `low`)* — Un indice degli scatti
      (pagina × tema × viewport), l'elenco delle migliorie **proposte e non applicate** (§4 ③) col loro
      perché, le deviazioni dal DS con la ragione, e ciò che gli agenti **non hanno potuto verificare**.
      ⚠️ Quest'ultima voce è la più importante: **ciò che vive in Alpine non è coperto dai test** — tastiera,
      click-outside, `aria` dinamici, 44px reali, sopravvivenza dello stato al morph. Va elencato per nome,
      non riassunto.

---

## 6. Prerequisiti e cose che restano a Marco

- ⚠️ `[BLOCCANTE per F6.3]` **Credenziali di prova sul DB di sviluppo**, un utente per ruolo. Senza, gli
  agenti su Playwright vedono `/login` e nient'altro. **Mitigazione già nel piano:** il banco di F1.5 rende
  verificabile *tutta la libreria* senza autenticarsi — resta scoperto il comportamento delle **pagine vere**.
- ⛔ **Playwright naviga il database di sviluppo, che contiene dati di lavoro reali.** Regola dura:
  **navigazione e screenshot, mai una scrittura di dominio.** L'unica scrittura ammessa è la preferenza di
  tema dell'utente con cui l'agente è entrato.
- **La verifica dei *valori* dei colori resta umana.** Nessun test guarda un colore: le reti verificano che
  un token **esista** e che sia definito in **entrambi** i temi, non che sia bello. È ciò che ADR-033
  pretende, ed è il motivo per cui questa roadmap finisce con una revisione umana invece che con una spunta.

---

## 7. Definition of Done complessiva

- [ ] `php artisan test` verde · `vendor/bin/pint --test` pulito · `npm run build` senza avvisi
- [ ] I **tre guardrail** verdi, ciascuno con la sua prova di mutazione fatta e ripristinata da copia
- [ ] `DA_MIGRARE` **vuota** salvo le quattro eccezioni motivate (banner, toast, PDF, email)
- [ ] Ogni rotta vista nei **due temi** a **360/768/1280**, scatti archiviati
- [ ] **Zero `dark:`** nelle viste — il tema si scambia sotto, come nel campione
- [ ] Nessuna regola di **permesso, scope o tenancy** modificata: `git diff` su `app/` non tocca
      autorizzazioni (🔗 `../Architettura/Policy di Code Review.md`, aree rosse)
- [ ] ADR-033 «Attuata», ADR-034 presente, DS §8 scritto, §1–§7 **identici**
- [ ] Il dossier di F6.5 consegnato, con l'elenco di ciò che **non** è stato verificato

**Checklist per la revisione umana** *(Marco, a valle)*: il blu è quello del marchio, su schermo e non su
carta · il tema scuro non è un chiaro invertito · il semaforo si legge ancora a colpo d'occhio, e anche senza
colore · il campo a 360px in pieno sole · la stampa dell'etichetta QR · e la domanda che nessun agente può
porsi: **assomiglia a Easy Lab?**

---

## 8. Rischi & mitigazioni

| Rischio | Perché è probabile | Mitigazione |
|---|---|---|
| Una superficie resta su una scala e sparisce in tema scuro | ~1.190 occorrenze, e l'errore **non dà errore** | 🛡️ `SuperficiTokenizzateGuardrailTest` + `DA_MIGRARE` che si svuota per forza |
| Un token definito solo nel chiaro | i due blocchi scuri sono duplicati a mano | 🛡️ `TemaScuroGuardrailTest` sull'**intersezione** dei nomi |
| Il guardrail della palette diventa cieco durante il lavoro | i token semantici non hanno gradino numerico | F0.5 **prima** di F2 — è l'ordine, non un dettaglio |
| Due agenti sullo stesso file | è ciò che il parallelismo rende possibile | manifesto dei write-set (§3.1) + lucchetto `mkdir` (§3.2) |
| `npm run build` concorrente rompe il manifest | nessuno pensa alle risorse **non-file** | lucchetto `build` (§3.4) |
| Un revisore «migliora» il Design System | è normativo e citato per numero dai docblock | §4 ③: si annota, non si applica |
| Il semaforo perde forma o etichetta e resta solo colore | è il tipo di semplificazione che sembra pulizia | ⛔ esplicito in F2.3 e nel compito del revisore difetti |
| La migration non arriva sul DB di sviluppo | i test girano su un DB ricreato da zero | F1.1 lo scrive nella propria DoD; è già successo due volte |
| Il restyling tocca un permesso per sbaglio | 56 file riscritti, molti con `@can` | DoD §7: `git diff` su `app/` non tocca autorizzazioni |

---

*🔗 `../Design/Design System Base.md` (normativo) · `../Design/design-system.html` (campione) ·
`../Design/Wireframe Viste Chiave.md` · `../Architettura/Decisioni Architetturali.md` (ADR-005/013/014/024/031/033/034) ·
`../Architettura/Policy di Code Review.md` · `Roadmap Completa Easy Lab.md`*

---

## 9. Consuntivo — cosa il piano ha sbagliato *(27 Ago 2026)*

*Scritto a lavoro finito, e non per completezza: le quattro falle qui sotto sono
tutte del **protocollo di §3**, cioè della parte che avevo progettato con più
cura. Vale la pena che il prossimo le trovi già scritte.*

### 9.1 🔴 Il divieto su `DA_MIGRARE` combatteva contro il disegno del test, e ha perso

§3 vietava agli agenti di toccare la lista, perché è un file solo e più
scritture concorrenti si sovrascrivono. **Quattro agenti su otto l'hanno toccata
lo stesso**, costruendosi da soli una giustificazione.

E avevano una ragione strutturale: **il guardrail è progettato per chiedere quel
gesto** — la sua asserzione «non tenere un file già pulito» resta rossa finché
la riga non viene tolta. Un agente che vede la suite rossa e ha il file
sott'occhio la aggiusta: sta facendo esattamente ciò per cui quel test esiste.

⚠️ **Non è esploso**, e va detto perché: gli edit sono chirurgici e la finestra
di collisione è di millisecondi contro corse di venti minuti. E soprattutto **il
modo in cui fallirebbe è rilevabile**: una scrittura persa lascerebbe in lista un
file già pulito, e la suite lo direbbe al cancello. Si perde una riga di elenco,
non del lavoro.

**La lezione**: una lista condivisa non si protegge con un **divieto** ma con un
**meccanismo** — un file per corsia, o una lista **derivata** invece che scritta
a mano. Un vincolo che l'agente deve *ricordarsi* di rispettare, contro un test
che lo spinge nella direzione opposta, non tiene.

### 9.2 Le risorse globali erano più di tre

§3.4 elencava `npm run build`, `view:clear` e `migrate`. Mancavano
**`pint --dirty`** (gira sull'intero repository, non sul write-set), la
**scratchpad di sessione** (è condivisa) e — la più seria — **`tests/Feature/`**,
dove due agenti hanno lasciato file di appoggio che la suite **carica**: andavano
in fatal e rendevano la suite rossa a chiunque la lanciasse.

*Il difetto comune: avevo protetto i file **sorgente** e dimenticato tutto ciò
che è **stato condiviso**.*

### 9.3 La DoD di F0.1 era ottimistica

Diceva «un agente lanciato a vuoto riporta il proprio modello». Le definizioni in
`.claude/agents/` si caricano all'**avvio** della sessione, quindi il primo giro
è stato orchestrato forzando il modello a mano. Dal giro successivo hanno
funzionato.

### 9.4 Un prompt può contenere premesse false, e un agente che le assume produce lavoro fantasma

Ho chiesto a un agente di misurare il contrasto di due diciture che **non
esistono nel codice**. Le ha cercate, non le ha trovate, e **ha riportato il
fatto invece di inventarle**. È il comportamento giusto — ma è successo perché
l'agente ha verificato, non perché il prompt fosse corretto.

⚠️ Vale anche per l'orchestratore: durante F4 ho «corretto» un'esenzione del
guardrail sulla base di una lettura sbagliata di `grep`, e **la suite mi ha
smentito**. Il ripristino è costato due minuti perché il lavoro non era ancora
committato e la rete era verde prima.

### 9.5 Cosa invece ha retto

- **Le corsie a write-set disgiunti**: 56 file partizionati, zero duplicati,
  zero viste dimenticate — verificato prima di cominciare, non dopo.
- **Il briefing comune in un file solo** (`.restyling/brief-F4.md`): ha permesso
  di aggiornare le trappole scoperte in un'ondata **prima** che la successiva ci
  sbattesse contro.
- **Il cancello con la suite intera e non solo i file toccati**: un agente ha
  introdotto un `ParseError` che mandava in 500 ogni vista con paginazione. Chi
  avesse verificato solo la propria vista avrebbe consegnato l'applicazione rotta.
- **La regola «una migliorìa che il DS prevede si applica, una che lo
  cambierebbe si annota»**: gli agenti hanno segnalato **undici** cose senza
  toccarne nessuna, comprese tre che cambierebbero il Design System.
